<?php

namespace WCC\Notifications\Controllers;

use Monolog\Logger;
use WCAA\Infrastructure\Events\Observer;
use WCC\Notifications\Controllers\NotificationGenerators\ActionGenerator;
use WCC\Notifications\Controllers\NotificationGenerators\EventGenerator;

class EventListener extends Observer
{
    /**
     * @var Logger
     */
    protected $logger;


    /**
     * @Inject
     * @var ActionGenerator
     */
    protected $actionGenerator;

    /**
     * @Inject
     * @var EventGenerator
     */
    protected $eventGenerator;

    function __construct(Logger $logger)
    {
        $this->logger = $logger->withName("notifications-event-listener");
    }

    /**
     * @param \SplSubject $subject
     * @param $event
     * @return void
     */
    function notify(\SplSubject $subject, $event, $data = null)
    {
        list($source) = explode(":", $event);
        switch ($source) {
            case 'event':
                $this->eventGenerator->process($data);
                break;
            case 'sys_action':
                $this->actionGenerator->process($data);
                break;
            default:
                $this->logger->info("Ignore event with name {$event} - not supported");
        }
    }

    function getEventType()
    {
        return "*";
    }
}