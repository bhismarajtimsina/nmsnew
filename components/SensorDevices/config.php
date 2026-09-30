<?php

use Slim\Interfaces\RouteCollectorProxyInterface as Group;

return [
    //Key of module. key must be uniq for components
    'name' => 'sensor_devices',
    'installer' => \WCC\SensorDevices\Installer::class,
    // List of rules
    'rules' => yaml_parse_file(__DIR__ . '/rules.yml'),

    //For work with another components in system you can create class with public method.
    //For access to object of module controller you must use ModuleInjector::getController(<name of module>)
    'controller' => \WCC\SensorDevices\Controllers\Controller::class,

    //List of processors
    //You can add methods for listen events.
    //For create event listener object you must implement ModuleEventListenerInterface.
    //Every event return object \WCAA\Models\Event
    'events' => [],

    //List of routes.
    //In project used slim routing(https://www.slimframework.com/docs/v4/objects/routing.html)
    //You must extends from WCAA\Api\Actions\Action
    // or WCAA\Api\Actions\PrivateAction (called user added, only for private methods)
    'routes' => [
        ['methods' => ['GET'], 'pattern'=>'/tab-stats/{device_id}', 'callable'=>\WCC\SensorDevices\Api\GetDeviceTabsStat::class],
        ['methods' => ['GET'], 'pattern'=>'/{device_id}/status', 'callable'=>\WCC\SensorDevices\Api\ListSensors::class],
        ['methods' => ['POST'], 'pattern'=>'/{device_id}/get-series/{type}/{id}', 'callable'=>\WCC\SensorDevices\Api\SensorSeriesAction::class],
        ['methods' => ['PUT'], 'pattern'=>'/{device_id}/control/{type}/{id}', 'callable'=>\WCC\SensorDevices\Api\ControlAction::class],
        ['methods' => ['PUT'], 'pattern'=>'/{device_id}/toggle/{type}/{id}', 'callable'=>\WCC\SensorDevices\Api\ToggleSensor::class],
        ['methods' => ['PUT'], 'pattern'=>'/{device_id}/config/{type}/{id}', 'callable'=>\WCC\SensorDevices\Api\SensorEditConfig::class],
        ['methods' => ['PUT'], 'pattern'=>'/{device_id}/disable-module/{type}', 'callable'=>\WCC\SensorDevices\Api\SetDisabledModulesState::class],
        ['methods' => ['GET'], 'pattern'=>'/{device_id}/disable-modules', 'callable'=>\WCC\SensorDevices\Api\SetDisabledModulesState::class],
      ],
    //List of RPC commands
    //For create rpc method you must create class with method __invoke() with list of arguments
    //In __construct() you can set all dependencies if you need.
    'rpc' => [

    ],
    'description' => 'Component for working with sensor devices',

];
