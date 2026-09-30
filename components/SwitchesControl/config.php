<?php

use Slim\Interfaces\RouteCollectorProxyInterface as Group;

return [
    //Key of module. key must be uniq for components
    'name' => 'switches_control',
    'installer' => \WCC\SwitchesControl\Installer::class,
    // List of rules
    'rules' => yaml_parse_file(__DIR__ . '/rules.yml'),

    //For work with another components in system you can create class with public method.
    //For access to object of module controller you must use ModuleInjector::getController(<name of module>)
    'controller' => \WCC\SwitchesControl\Controllers\SwitchesController::class,

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
        ['methods' => ['PUT'], 'pattern'=>'/system/{device}/reboot', 'callable'=>\WCC\SwitchesControl\Api\RebootDeviceAction::class],
        ['methods' => ['PUT'], 'pattern'=>'/system/{device}/save_config', 'callable'=>\WCC\SwitchesControl\Api\SaveConfigAction::class],
        ['methods' => ['PUT'], 'pattern'=>'/system/{device}/clear_counters', 'callable'=>\WCC\SwitchesControl\Api\ClearCountersAction::class],
        ['methods' => ['PUT'], 'pattern'=>'/system/{device}/{interface}/clear_counters', 'callable'=>\WCC\SwitchesControl\Api\ClearIfaceCountersAction::class],
        ['methods' => ['PUT'], 'pattern'=>'/interface/{device}/{interface}', 'callable'=>\WCC\SwitchesControl\Api\UpdatePortAction::class],
    ],
    //List of RPC commands
    //For create rpc method you must create class with method __invoke() with list of arguments
    //In __construct() you can set all dependencies if you need.
    'rpc' => [

    ],
    'description' => 'Component for management ports on L2 switches',
    'dependencies' => [
        'switches'
    ],
];
