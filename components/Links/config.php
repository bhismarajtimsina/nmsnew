<?php

use Slim\Interfaces\RouteCollectorProxyInterface as Group;

return [
    //Key of module. key must be uniq for components
    'name' => 'links',
    'installer' => \WCC\Links\Installer::class,
    // List of rules
    'rules' => yaml_parse_file(__DIR__ . '/rules.yml'),

    //For work with another components in system you can create class with public method.
    //For access to object of module controller you must use ModuleInjector::getController(<name of module>)
    'controller' => \WCC\Links\Controllers\Controller::class,

    'routes' => [
        ['methods' => ['GET', 'PUT'], 'pattern'=>'/view/list', 'callable'=>\WCC\Links\Api\GetAllFull::class],
        ['methods' => ['GET'], 'pattern'=>'/view/tree', 'callable'=>\WCC\Links\Api\DirectionTree::class],
        ['methods' => ['GET'], 'pattern'=>'', 'callable'=>\WCC\Links\Api\GetAll::class],
        ['methods' => ['GET'], 'pattern'=>'/topology-tree', 'callable'=>\WCC\Links\Api\Topology::class],
        ['methods' => ['PUT'], 'pattern'=>'/external-neighbor-name', 'callable'=>\WCC\Links\Api\SetExternalNeighborName::class],
        ['methods' => ['GET'], 'pattern'=>'/{id}', 'callable'=>\WCC\Links\Api\GetByIdAction::class],
        ['methods' => ['GET'], 'pattern'=>'/upward/{device_id}', 'callable'=>\WCC\Links\Api\GetUpwardTopologyByDevice::class],
        ['methods' => ['GET'], 'pattern'=>'/lldp-neighbors/{device_id}', 'callable'=>\WCC\Links\Api\LldpNeighborsAction::class],
        ['methods' => ['GET'], 'pattern'=>'/by-device/{device_id}', 'callable'=>\WCC\Links\Api\GetAllByDeviceAction::class],
        ['methods' => ['GET'], 'pattern'=>'/by-device/{device_id}/{interface_id}', 'callable'=>\WCC\Links\Api\GetAllByBindInterfaceAction::class],
        ['methods' => ['GET'], 'pattern'=>'/by-interface/{interface_id}', 'callable'=>\WCC\Links\Api\GetAllByInterfaceAction::class],
        ['methods' => ['POST'], 'pattern'=>'', 'callable'=>\WCC\Links\Api\AddAction::class],
        ['methods' => ['PUT'], 'pattern'=>'/{id}', 'callable'=>\WCC\Links\Api\UpdateAction::class],
        ['methods' => ['DELETE'], 'pattern'=>'/{id}', 'callable'=>\WCC\Links\Api\DeleteAction::class],
        ['methods' => ['GET'], 'pattern'=>'/options/devices', 'callable'=>\WCC\Links\Api\GetDevicesList::class],
        ['methods' => ['GET'], 'pattern'=>'/options/interfaces/{iface_id}', 'callable'=>\WCC\Links\Api\GetInterfacesByDevice::class],
        ['methods' => ['GET'], 'pattern'=>'/options/configuration', 'callable'=>\WCC\Links\Api\GetOptionConfiguration::class],
        ['methods' => ['GET'], 'pattern'=>'/widgets/high-utilization', 'callable'=>\WCC\Links\Api\Widgets\HighUtilization::class],
    ],

    //List of console commands
    //For create some console command you must create classes extended from WCAA\Modules\AbstractComponentCommand
    //For work with console used symfony console (https://symfony.com/doc/current/components/console.html)
    'console' => [
            \WCC\Links\Console\CalculateLinkUtilization::class,
    ],
    'event_listeners' => [
        \WCC\Links\Controllers\EventListener::class,
    ],
    'env_params' => yaml_parse_file(__DIR__ . '/params.yml'),
    'description' => 'Allow to build dependecies tree',
];
