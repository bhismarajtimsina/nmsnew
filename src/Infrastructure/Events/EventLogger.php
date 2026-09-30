<?php

namespace WCAA\Infrastructure\Events;

use Monolog\Logger;
use SplSubject;
use WCAA\App;
use WCAA\Models\AbstractModel;

class EventLogger extends Observer
{

    protected $logger;

    function __construct(Logger $logger, App $app)
    {
        $this->logger = clone $logger;
        $this->logger->setHandlers([new \Monolog\Handler\StreamHandler(
                $app->conf('logger.path') . '/events.log'
            )]
        );

    }


    function notify(SplSubject $subject, $event, $data = null)
    {
        if(is_object($data) && $data instanceof AbstractModel) {
            $data = $data->getAsArray();
        } else {
            $data = json_decode(json_encode($data), true);
        }
        if(!is_array($data)) {
            $data = ['data' => $data];
        }
        $this->logger->info("{$event}",$data);
    }


    function getEventType()
    {
        return "*";
    }

}