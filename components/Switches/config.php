<?php

use Slim\Interfaces\RouteCollectorProxyInterface as Group;

return [
    //Key of module. key must be uniq for components
    'name' => 'switches',
    'installer' => \WCC\Switches\Installer::class,
    // List of rules
    'rules' => yaml_parse_file(__DIR__ . '/rules.yml'),

    //For work with another components in system you can create class with public method.
    //For access to object of module controller you must use ModuleInjector::getController(<name of module>)
    'controller' => \WCC\Switches\Controllers\SwitchesController::class,

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
        ['methods' => ['GET'], 'pattern'=>'/tab-stats/{device}', 'callable'=>\WCC\Switches\Api\GetDeviceTabsStat::class],
        ['methods' => ['GET'], 'pattern'=>'/interfaces/{device}', 'callable'=>\WCC\Switches\Api\GetInterfaceInfo::class],
        ['methods' => ['GET'], 'pattern'=>'/interfaces/{device}/cable_diag', 'callable'=>\WCC\Switches\Api\CableDiagOnInterface::class],
        ['methods' => ['GET'], 'pattern'=>'/interfaces/{device}/sfp_diag', 'callable'=>\WCC\Switches\Api\SfpDiagOnInterface::class],
        ['methods' => ['GET'], 'pattern'=>'/interfaces/parse/{device}/{interface}', 'callable'=>\WCC\Switches\Api\ParseInterfaceAction::class],
        ['methods' => ['GET'], 'pattern'=>'/resources/{device}', 'callable'=>\WCC\Switches\Api\GetResources::class],
        ['methods' => ['GET'], 'pattern'=>'/vlans/{device}', 'callable'=>\WCC\Switches\Api\GetVlanList::class],
        ['methods' => ['PUT'], 'pattern'=>'/system/{device}/reboot', 'callable'=>\WCC\Switches\Api\RebootDeviceAction::class],
        ['methods' => ['PUT'], 'pattern'=>'/system/{device}/save_config', 'callable'=>\WCC\Switches\Api\SaveConfigAction::class],
        ['methods' => ['PUT'], 'pattern'=>'/system/{device}/clear_counters', 'callable'=>\WCC\Switches\Api\ClearCountersAction::class],
        ['methods' => ['PUT'], 'pattern'=>'/interface/{device}/{interface}', 'callable'=>\WCC\Switches\Api\UpdatePortAction::class],
    ],
    //List of RPC commands
    //For create rpc method you must create class with method __invoke() with list of arguments
    //In __construct() you can set all dependencies if you need.
    'rpc' => [

    ],
    'description' => 'Component for get info from L2 switches',

];
