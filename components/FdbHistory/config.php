<?php

use Slim\Interfaces\RouteCollectorProxyInterface as Group;

return [
    //Key of module. key must be uniq for components
    'name' => 'fdb_history',

    'description' => 'Realize API for working with history DFB',

    'installer' => \WCC\FdbHistory\Installer::class,
    // List of rules
    'rules' => yaml_parse_file(__DIR__ . '/rules.yml'),


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
        ['methods' => ['GET'], 'pattern'=>'/{device}[/{interface}]', 'callable'=>\WCC\FdbHistory\Api\GetByInterface::class],
        ['methods' => ['POST'], 'pattern'=>'/filter', 'callable'=>\WCC\FdbHistory\Api\GetByFilterAction::class],
    ],

];
