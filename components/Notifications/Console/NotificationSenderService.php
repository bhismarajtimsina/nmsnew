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
use WCC\Pinger\Controllers\Controller;

class NotificationSenderService extends AbstractComponentCommand
{

    /**
     * @Inject
     * @var \WCC\Notifications\Controllers\NotificationSender
     */
    protected $notificationSender;


    const COUNT_PROCS = 20;

    /**
     * @Inject
     * @var App
     */
    protected $app;

    /**
     * @Inject
     * @var Logger
     */
    protected $logger;

    /**
     * @var array
     */
    function config()
    {
        $this->setName("service")
            ->addOption("run-once", 'once', InputOption::VALUE_NEGATABLE, "Run once", false)
            ->setDescription("Service for background message sending");
    }


    function exec(InputInterface $input, OutputInterface $output)
    {
        $this->logger = clone $this->logger;
        $this->logger->setHandlers(
            [new \Monolog\Handler\StreamHandler(
                App::getInstance()->conf('logger.path') . '/notification-service.log',
                App::getInstance()->conf('logger.level')
            )]
        );

        $this->output->writeln("<comment>Start notification service</comment>");
        $this->output->writeln("Count of proc processes: " . self::COUNT_PROCS);

        $processes = [];
        do {
            $notifications = $this->notificationSender->getNotificationsForSend();
            if(count($notifications) == 0) {
                sleep(3);
                continue;
            }
            $this->output->writeln("Found ".count($notifications)." notifications for sending");

             foreach ($notifications as $notification) {
                if(!$notification->getEvent() && !$notification->getAction()) {
                    $output->writeln("<comment>Notification was ignored, event or action not exist</comment>");
                    $this->notificationSender->setCancelWithMessage($notification, "Notification was ignored, event or action not exist");
                    continue;
                }

                 if(isset($processes[$notification->getId()])) {
                     $output->writeln("<comment>Sender for {$notification->getId()} was started early</comment>");
                 } else {
                     $this->notificationSender->setInProcess($notification);
                     $proccess = new BackgroundProcess("wca notifications:send-notify {$notification->getId()}");
                     $proccess->run();
                     $output->writeln("<info>Start proc proccess for {$notification->getId()} with pid={$proccess->getPid()}</info>");
                     $processes[$notification->getId()] = [
                         'proc' => $proccess,
                         'notification' => $notification,
                     ];
                     usleep(100000);
                 }
                CHECK_STATE:
                $countInWork = 0;
                foreach ($processes as $key => $proc) {
                    if ($proc['proc']->isRunning()) {
                        $countInWork++;
                    } else {
                        $output->writeln("<info>Sending notification {$proc['notification']->getId()}) finished!</info>");
                        unset($processes[$key]);
                    }
                }
                if (self::COUNT_PROCS <= $countInWork) {
                    sleep(1);
                    if ($output->isVerbose()) {
                        $output->writeln("Max worker reached! Wait before check. Count procs in work={$countInWork}");
                    }
                    goto CHECK_STATE;
                }
            }
        } while (!$input->getOption('run-once'));
        return self::SUCCESS;
    }

}
