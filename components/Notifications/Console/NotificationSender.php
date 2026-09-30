<?php


namespace WCC\Notifications\Console;

use Cocur\BackgroundProcess\BackgroundProcess;
use Monolog\Logger;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use WCAA\App;
use WCAA\Console\AbstractCommand;
use WCAA\Infrastructure\Components\AbstractComponentCommand;
use WCAA\Infrastructure\Poller\PollerProcessor;
use WCAA\Infrastructure\PrometheusMetrics;
use WCAA\Models\Devices\Device;
use WCAA\Storage\PollerData\PollerProcessingStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\Notifications\Models\Notification;
use WCC\Notifications\Storage\NotificationsStorage;
use WCC\Pinger\Controllers\Controller;

class NotificationSender extends AbstractComponentCommand
{

    /**
     * @Inject
     * @var \WCC\Notifications\Controllers\NotificationSender
     */
    protected $notificationSender;

    /**
     * @Inject
     * @var Logger
     */
    protected $logger;

    /**
     * @Inject
     * @var NotificationsStorage
     */
    protected $notificationStorage;

    /**
     * @var array
     */
    function config()
    {
        $this->setName("send-notify")
            ->addArgument("notification-id", InputArgument::REQUIRED, "Notification ID")
            ->setDescription("Send messages by notification ID");
    }


    function exec(InputInterface $input, OutputInterface $output)
    {
        $notification = $this->notificationStorage->getById($input->getArgument('notification-id'));
        if($notification->getStatus() && $notification->getStatus() != Notification::STATUS_IN_PROCESS) {
            $output->writeln("Notification with id={$notification->getId()} sended early");
            return self::SUCCESS;
        }
        $output->writeln("Start process notification with id={$notification->getId()}");
        $this->logger->info("Start process notification with id={$notification->getId()}", $notification->getAsArray());
        if($this->notificationSender->isSendCanceled($notification)) {
            $output->writeln("Notification with id={$notification->getId()} was canceled for send");
            $this->logger->info("Notification with id={$notification->getId()} was canceled for send", $notification->getAsArray());
            $notification->setStatus(Notification::STATUS_CANCELED);
            $notification->setMeta([
               'cancel_reason' => 'Event resolved before sending',
            ]);
            $this->notificationStorage->update($notification);
            return self::SUCCESS;
        }
        if(!$this->notificationSender->allowedToSendByUplinkChecking($notification)) {
            $output->writeln("Notification with id={$notification->getId()} was canceled for send");
            $this->logger->info("Notification with id={$notification->getId()} was canceled for send", $notification->getAsArray());
            $notification->setStatus(Notification::STATUS_CANCELED);
            $notification->setMeta([
                'cancel_reason' => 'Uplink has same not resolved event',
            ]);
            $this->notificationStorage->update($notification);
            return self::SUCCESS;
        }
        try {
            $output->writeln("Try send notify with id={$notification->getId()}");
            $this->logger->info("Try send notify with id={$notification->getId()}");
            $notifyMeta = $this->notificationSender->sendNotify($notification);
            $this->notificationStorage->update($notification
                ->setSentAt(date("Y-m-d H:i:s"))
                ->setMeta($notifyMeta)
                ->setStatus(Notification::STATUS_SENT));
            $output->writeln("<success>Notification success sent,  id={$notification->getId()}</success>");
            $this->logger->info("Notification success sent, id={$notification->getId()}");

        } catch (\Throwable $e) {
            $output->writeln("<error>Error sent notification with id={$notification->getId()}, err: {$e->getMessage()}</error>");
            $this->logger->error("Error sent notification with id={$notification->getId()}, err: {$e->getMessage()}",  [
                'message' => $e->getMessage(),
                'line' => "{$e->getFile()}:{$e->getLine()}",
                'trace' => $e->getTraceAsString(),
            ]);
            $this->notificationStorage->update($notification
                ->setSentAt(date("Y-m-d H:i:s"))
                ->setMeta([
                    'error' => [
                        'message' => $e->getMessage(),
                        'line' => "{$e->getFile()}:{$e->getLine()}",
                        'trace' => $e->getTraceAsString(),
                    ]
                ])
            ->setStatus(Notification::STATUS_FAILED));
            return  self::INVALID;
        }
        return self::SUCCESS;
    }

}
