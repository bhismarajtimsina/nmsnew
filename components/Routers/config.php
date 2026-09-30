<?php

use Slim\Interfaces\RouteCollectorProxyInterface as Group;

return [
    //Key of module. key must be uniq for components
    'name' => 'routers',
    'installer' => \WCC\Routers\Installer::class,
    // List of rules
    'rules' => yaml_parse_file(__DIR__ . '/rules.yml'),

    //For work with another components in system you can create class with public method.
    //For access to object of module controller you must use ModuleInjector::getController(<name of module>)
    'controller' => \WCC\Routers\Controllers\RouterController::class,

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
        ['methods' => ['GET'], 'pattern'=>'/tab-stats/{device}', 'callable'=>\WCC\Routers\Api\GetDeviceTabsStat::class],
        ['methods' => ['GET'], 'pattern'=>'/interfaces/{device}', 'callable'=>\WCC\Routers\Api\GetInterfaceInfo::class],
        ['methods' => ['GET'], 'pattern'=>'/interfaces/parse/{device}/{interface}', 'callable'=>\WCC\Routers\Api\ParseInterfaceAction::class],
        ['methods' => ['GET'], 'pattern'=>'/resources/{device}', 'callable'=>\WCC\Routers\Api\GetResources::class],
        ['methods' => ['GET'], 'pattern'=>'/vlans/{device}', 'callable'=>\WCC\Routers\Api\GetVlanList::class],
        ['methods' => ['GET'], 'pattern'=>'/arps/{device}', 'callable'=>\WCC\Routers\Api\GetArps::class],
        ['methods' => ['GET'], 'pattern'=>'/direct-routes/{device}', 'callable'=>\WCC\Routers\Api\GetDirectRoutes::class],
        ['methods' => ['GET'], 'pattern'=>'/fdb/{device}', 'callable'=>\WCC\Routers\Api\GetFDB::class],
    ],
    //List of RPC commands
    //For create rpc method you must create class with method __invoke() with list of arguments
    //In __construct() you can set all dependencies if you need.
    'rpc' => [

    ],
    'description' => 'Component for get info from L3 devices (Routers)',

];
