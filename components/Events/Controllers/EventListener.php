<?php

namespace WCC\Events\Controllers;


use DI\Container;
use Monolog\Logger;
use WCAA\Infrastructure\Events\Observer;
use WCC\Events\Controllers\EventProcessors\AlertmanagerEventProcessor;

class EventListener extends Observer
{

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var Container
     */
    protected $container;

    function __construct(Logger $logger, Container $container)
    {
        $this->logger = $logger->withName("event-listener");
        $this->container = $container;
    }


    function notify(\SplSubject $subject, $event, $data = null)
    {
        if (strpos($event, "alertmanager") !== false) {
            $this->container->get(AlertmanagerEventProcessor::class)->process($event, $data);
        }
    }


    function getEventType()
    {
        return "*";
    }



}