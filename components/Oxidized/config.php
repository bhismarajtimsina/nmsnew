<?php

use Slim\Interfaces\RouteCollectorProxyInterface as Group;

return [
    //Key of module. key must be uniq for components
    'name' => 'oxidized',
    'installer' => \WCC\Oxidized\Installer::class,
    // List of rules
    'rules' => yaml_parse_file(__DIR__ . '/rules.yml'),

    //For work with another components in system you can create class with public method.
    //For access to object of module controller you must use ModuleInjector::getController(<name of module>)
    'controller' => \WCC\Oxidized\Controllers\Controller::class,

    //List of processors
    //You can add methods for listen events.
    //For create event listener object you must implement ModuleEventListenerInterface.
    //Every event return object \WCAA\Models\Event
    'event_listeners' => [
        \WCC\Oxidized\Controllers\EventListener::class,
    ],

    'routes' => [
        ['methods' => ['GET'], 'pattern'=>'/internal/devices-list', 'callable'=>\WCC\Oxidized\Api\DeviceListAction::class],
        ['methods' => ['GET'], 'pattern'=>'/is-oxidized-supported/{device_id}', 'callable'=>\WCC\Oxidized\Api\IsDeviceSupportedByOxidized::class],
        ['methods' => ['GET'], 'pattern'=>'/data/config/{device_id}', 'callable'=>\WCC\Oxidized\Api\GetDeviceConfig::class],
        ['methods' => ['GET'], 'pattern'=>'/data/status/{device_id}', 'callable'=>\WCC\Oxidized\Api\GetDeviceStatus::class],
        ['methods' => ['GET'], 'pattern'=>'/data/get-links/{device_id}', 'callable'=>\WCC\Oxidized\Api\GetDeviceLink::class],
    ],

    //List of console commands
    //For create some console command you must create classes extended from WCAA\Modules\AbstractComponentCommand
    //For work with console used symfony console (https://symfony.com/doc/current/components/console.html)
    'console' => [],
    'env_params' => yaml_parse_file(__DIR__ . '/params.yml'),
    'description' => 'Oxidized config backup system',
    'models_map' => yaml_parse_file(__DIR__ . '/models_map.yml'),
];
