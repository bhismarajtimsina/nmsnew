<?php

use Slim\Interfaces\RouteCollectorProxyInterface as Group;

return [
    //Key of module. key must be uniq for components
    'name' => 'pinger',
    'installer' => \WCC\Pinger\Installer::class,
    // List of rules
    'rules' => yaml_parse_file(__DIR__ . '/rules.yml'),

    'controller' => \WCC\Pinger\Controllers\Controller::class,

    //List of processors
    //You can add methods for listen events.
    //For create event listener object you must implement ModuleEventListenerInterface.
    //Every event return object \WCAA\Models\Event
    'events' => [],

    'routes' => [
        ['methods' => ['GET'], 'pattern'=>'/pinger', 'callable'=>\WCC\Pinger\Api\PingerHostsListAction::class],
        ['methods' => ['POST'], 'pattern'=>'/pinger', 'callable'=>\WCC\Pinger\Api\PingerUpdateHostsStatusAction::class],
        ['methods' => ['GET'], 'pattern'=>'/device-status-stat', 'callable'=>\WCC\Pinger\Api\GetHostsStatusChart::class],
        ['methods' => ['GET'], 'pattern'=>'/logs/{device_id}', 'callable'=>\WCC\Pinger\Api\GetPingerLogs::class],
        ['methods' => ['GET'], 'pattern'=>'/status/{device_id}', 'callable'=>\WCC\Pinger\Api\GetPingerStatus::class],
        ['methods' => ['GET'], 'pattern'=>'/statuses', 'callable'=>\WCC\Pinger\Api\GetPingerAllStatuses::class],
    ],

    //List of console commands
    //For create some console command you must create classes extended from WCAA\Modules\AbstractComponentCommand
    //For work with console used symfony console (https://symfony.com/doc/current/components/console.html)
    'console' => [
        \WCC\Pinger\Console\UpdateExporterStatuses::class,
    ],

    'env_params' => [

    ],
    'description' => 'ICMP pinger',
];
