<?php

namespace WCC\Notifications\Controllers;

use DI\Container;
use Monolog\Logger;
use WCAA\App;
use WCAA\Storage\SystemComponentsStorage;
use WCC\Events\Storage\EventsStorage;
use WCC\Notifications\Controllers\Channels\ChannelInterface;
use WCC\Notifications\Controllers\Channels\Mail;
use WCC\Notifications\Controllers\Channels\Telegram;
use WCC\Notifications\Models\Notification;
use WCC\Notifications\Storage\NotificationEventConfigStorage;
use WCC\Notifications\Storage\NotificationsStorage;

class NotificationSender
{

    /**
     * @Inject
     * @var App
     */
    protected $app;

    /**
     * @var ChannelInterface[]
     */
    protected $channels;

    /**
     * @Inject
     * @var NotificationsStorage
     */
    protected $notificationsStorage;

    /**
     * @Inject
     * @var EventsStorage
     */
    protected $eventsStorage;

    /**
     * @Inject
     * @var NotificationEventConfigStorage
     */
    protected $notificationEventConfigStorage;

    protected $contactTypesToChannels = [
        'TELEGRAM_ID' => 'telegram',
        'EMAIL' => 'email',
    ];

    /**
     * @var Logger
     */
    protected $logger;

    protected $config;

    function __construct(Container $c, SystemComponentsStorage $componentsStorage, Logger $logger)
    {
        $this->channels = [
            'telegram' => $c->get(Telegram::class),
            'email' => $c->get(Mail::class),
        ];
        $this->logger = $logger;
        $this->config = $componentsStorage->getByKey('notifications')->getConfiguration();
        $this->initChannels();

    }

    function initChannels()
    {
        foreach ($this->channels as  $value) {
            if ($value->isConfigured()) {
                $value->init();
            }
        }
        return $this;
    }


    function isSendCanceled(Notification $notification) {
        if(!$notification->getEvent()) return false;

        //Эта проверка добавлена, так как даже при условии выключенной проверки на предыдущие сообщения - нельзя отправлять resolved
         if($notification->getType() == 'resolved' &&
             $notification->getPreviousnotification() &&
             $notification->getPreviousnotification()->getStatus() == Notification::STATUS_CANCELED
         ) return true;

        // Если это алерт и на момент отправки алерт был закрыт - незачем отправлять
        if($notification->getType() == 'alert' &&
            $notification->getEvent()->getResolvedAt() !== null
        ) return true;

        if(_env('NOTIFICATIONS_CHECK_PREVIOUS_MESSAGE', false)) {
            if($notification->getType() == 'resolved' && !$notification->getPreviousnotification()) {
                $this->logger->warning("Alert notification for event_id={$notification->getEvent()->getId()} not found, ignore resolved notification");
                return  true;
            }
            if($notification->getType() == 'resolved' &&
                $notification->getPreviousnotification() &&
                $notification->getPreviousnotification()->getStatus() !== Notification::STATUS_SENT
            ) {
                $this->logger->warning("Alert notification canceled for event_id={$notification->getEvent()->getId()}, ignore resolved notification");
                return  true;
            }
        }
        return false;
    }

    function allowedToSendByUplinkChecking(Notification $notification) {
        //Check by uplink devices
        if(!$notification->getEvent()) return true;
        if(!$notification->getEvent()->getDevice()) return true;
        if(!$this->app->getComponentInjector()->isComponentEnabled('links')) return true;
        /**
         * @var $links \WCC\Links\Controllers\Controller
         */
        $links = $this->app->getComponentInjector()->getController('links');
        $cfg = $this->notificationEventConfigStorage->searchByName($notification->getEvent()->getName());
        if(!$cfg) {
            $this->logger->debug("Event with name {$notification->getEvent()->getName()} not found in configuration");
            return  true;
        }
        if(!$cfg->isCheckUplink()) {
            $this->logger->debug("Uplink checking disabled, notification must be send");
            return  true;
        }
        $uplinks = $links->getByDevice($notification->getEvent()->getDevice(), 'src');
        if(!$uplinks) {
            $this->logger->debug("Not found uplinks by device {$notification->getEvent()->getDevice()->getIp()}");
            return  true;
        }
        foreach ($uplinks as $uplink) {
            if($this->eventsStorage->getNotResolvedBy($notification->getEvent()->getName(), $uplink->getSrcDevice())) {
                $this->logger->warning("Cancel send notification {$notification->getId()} with event ({$notification->getEvent()->getName()}), becouse uplink device has same not resolved event");
                return  false;
            }
        }
        return  true;
    }

    /**
     * @param Notification $notification
     * @return \Throwable[]
     */
    function sendNotify(Notification $notification)
    {
        try {
                $channel = $this->contactTypesToChannels[$notification->getContact()->getType()];
                if (!isset($this->channels[$channel])) {
                    $this->logger->alert("Channel {$channel} not setted in controller", [
                        'action' => $notification->getAction() ? $notification->getAction()->getAsArray() : null,
                        'event' => $notification->getEvent() ? $notification->getEvent()->getAsArray() : null,
                        'contact' => $notification->getContact()->getAsArray(),
                    ]);

                }
                if (!$this->channels[$channel]->isConfigured()) {
                    $this->logger->alert("Channel {$channel} not configured!", [
                        'action' => $notification->getAction() ? $notification->getAction()->getAsArray() : null,
                        'event' => $notification->getEvent() ? $notification->getEvent()->getAsArray() : null,
                        'contact' => $notification->getContact()->getAsArray(),
                    ]);
                }
                return $this->channels[$channel]->send($notification);
        } catch (\Throwable $e) {
            $this->logger->error("Error send notification: {$e->getMessage()}", [
                'action' => $notification->getAction() ? $notification->getAction()->getAsArray() : null,
                'event' => $notification->getEvent() ? $notification->getEvent()->getAsArray() : null,
                'contact' => $notification->getContact()->getAsArray(),
                'line' => "{$e->getFile()}:{$e->getLine()}",
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * @return Notification[]
     */
    function getNotificationsForSend() {
        return $this->notificationsStorage->getNotSendedNotifications();
    }

    function setInProcess(Notification $notification)
    {
        $this->notificationsStorage->update($notification->setStatus(Notification::STATUS_IN_PROCESS));
    }

    function setCancelWithMessage(Notification $notification, $message)
    {
        $this->notificationsStorage->update($notification->setMeta(['error' => ['message' => $message]])->setStatus(Notification::STATUS_CANCELED));
        return $this;
    }

}