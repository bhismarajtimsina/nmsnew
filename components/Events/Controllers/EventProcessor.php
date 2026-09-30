<?php

namespace WCC\Events\Controllers;

use Monolog\Logger;
use WCAA\App;
use WCAA\Infrastructure\Events\EventObserverStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\UserStorage;
use WCC\Events\Models\Event;
use WCC\Events\Storage\EventsStorage;

abstract class EventProcessor
{

    /**
     * @var EventsStorage
     */
    protected $storage;

    /**
     * @var DeviceStorage
     */
    protected $devStorage;

    /**
     * @var UserStorage
     */
    protected $userStorage;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var EventObserverStorage
     */
    protected $events;

    function __construct(EventObserverStorage $events, EventsStorage $storage, Logger $logger, DeviceStorage $devstorage, UserStorage $userStorage)
    {
        $this->events = $events;
        $this->storage = $storage;
        $this->devStorage = $devstorage;
        $this->userStorage = $userStorage;
        $this->logger = $logger->withName("event-listener");
    }

    abstract function process($eventName, $data);

    /**
     * @param Event $event
     * @return Event
     */
    function createEvent(Event $event) {
        if($event->getDevice() && !$event->getDevice()->isEnabled()) {
            return $event;
        }
        $event = $this->storage->add($event);
        $this->events->notify("event:created", $event);
        return $event;
    }

    function resolveEvent($name = "", $device = null, $labels = null, $key = '') {

        $events = $this->storage->getNotResolvedBy($name,$device,$labels,$key);
        if (!$events) {
            return false;
        }

        foreach ($events as $event) {
            $eventClosed = $this->storage->update(
                $event
                    ->setUpdatedAt(date("Y-m-d H:i:s"))
                    ->setResolvedAt(date("Y-m-d H:i:s"))
                    ->setResolvedBy(App::getInstance()->getSysUser())
            );
            $this->events->notify("event:resolved", $eventClosed);
        }
        return  true;
    }
}