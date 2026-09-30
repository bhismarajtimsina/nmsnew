<?php

use Slim\Interfaces\RouteCollectorProxyInterface as Group;

return [
    //Key of module. key must be uniq for components
    'name' => 'auto_topology',
    'installer' => \WCC\AutoTopology\Installer::class,
    // List of rules
    'rules' => [],

    //For work with another components in system you can create class with public method.
    //For access to object of module controller you must use ModuleInjector::getController(<name of module>)
    'controller' => \WCC\AutoTopology\Controllers\Controller::class,

    //List of processors
    'event_listeners' => [

    ],

    'routes' => [
        ['methods' => ['POST'], 'pattern'=>'/update', 'callable'=>\WCC\AutoTopology\Api\UpdateAction::class],
        ['methods' => ['GET'], 'pattern'=>'/devices-list', 'callable'=>\WCC\AutoTopology\Api\GetDevicesList::class],
    ],

    //List of console commands
    //For create some console command you must create classes extended from WCAA\Modules\AbstractComponentCommand
    //For work with console used symfony console (https://symfony.com/doc/current/components/console.html)
    'console' => [
        \WCC\AutoTopology\Console\RunAutoTopology::class,
        \WCC\AutoTopology\Console\ClearNotActualLinks::class
    ],

    'env_params' => yaml_parse_file(__DIR__ . '/params.yml'),
    'description' => 'Automatic build topology for LLDP/FDB',
];
