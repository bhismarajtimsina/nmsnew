<?php

namespace WCC\Oxidized\Controllers;


use Curl\Curl;
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
     * @var Controller
     */
    protected $controller;


    function __construct(Logger $logger, Controller $controller)
    {
        $this->logger = $logger->withName("oxidized");
        $this->controller = $controller;

    }


    function notify(\SplSubject $subject, $event, $data = null)
    {
        if (strpos($event, "device:") !== false) {
            $this->logger->warning("Sending {$event} for reload oxidized configuration");
            $this->controller->reloadConfig();
        } elseif (strpos($event, "device-access:") !== false) {
            $this->logger->warning("Sending {$event} for reload oxidized configuration");
            $this->controller->reloadConfig();
        }
    }


    function getEventType()
    {
        return "*";
    }



}