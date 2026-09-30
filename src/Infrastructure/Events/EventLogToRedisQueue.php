<?php

namespace WCAA\Infrastructure\Events;

use AndrewBreksa\RSMQ\RSMQClient;
use Monolog\Logger;
use SplSubject;
use WCAA\App;
use WCAA\Models\AbstractModel;

class EventLogToRedisQueue extends Observer
{

    protected $logger;

    /**
     * @var \Superbalist\PubSub\Redis\RedisPubSubAdapter
     */
    protected $rsmq;
    function __construct(\Superbalist\PubSub\Redis\RedisPubSubAdapter $rsmq, Logger $logger, App $app)
    {
        $this->logger = clone $logger;
        $this->logger->setHandlers([new \Monolog\Handler\StreamHandler(
                $app->conf('logger.path') . '/events.log'
            )]
        );
        $this->rsmq = $rsmq;
    }


    function notify(SplSubject $subject, $event, $data = null)
    {
        if (is_object($data) && $data instanceof AbstractModel) {
            $data = $data->getAsArray();
        } else {
            $data = json_decode(json_encode($data), true);
        }
        if (!is_array($data)) {
            $data = ['data' => $data];
        }

        $this->rsmq->publish('internal-events',  ["name"=>$event, "data" => $data]);

    }


    function getEventType()
    {
        return "*";
    }

}