<?php

use Slim\Interfaces\RouteCollectorProxyInterface as Group;

return [
    //Key of module. key must be uniq for components
    'name' => 'olts',
    'installer' => \WCC\Olts\Installer::class,
    // List of rules
    'rules' => yaml_parse_file(__DIR__ . '/rules.yml'),

    //For work with another components in system you can create class with public method.
    //For access to object of module controller you must use ModuleInjector::getController(<name of module>)
    'controller' => \WCC\Olts\Controllers\Controller::class,

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
        ['methods' => ['GET'], 'pattern'=>'/tab-stats/{device}', 'callable'=>\WCC\Olts\Api\GetTabStats::class],
        ['methods' => ['GET'], 'pattern'=>'/resources/{device}', 'callable'=>\WCC\Olts\Api\GetResources::class],
        ['methods' => ['GET'], 'pattern'=>'/system/resources/{device}', 'callable'=>\WCC\Olts\Api\GetResources::class],
        ['methods' => ['GET'], 'pattern'=>'/system/supported-modules/{device}', 'callable'=>\WCC\Olts\Api\GetSupportedModules::class],
        ['methods' => ['GET'], 'pattern'=>'/cards/{device}', 'callable'=>\WCC\Olts\Api\GetCardsList::class],
        ['methods' => ['GET'], 'pattern'=>'/cards/status/{device}', 'callable'=>\WCC\Olts\Api\GetCardsStatus::class],
        ['methods' => ['GET'], 'pattern'=>'/interfaces/onts/{device}', 'callable'=>\WCC\Olts\Api\GetOntsList::class],
        ['methods' => ['GET'], 'pattern'=>'/interfaces/ont/{device}/{interface}', 'callable'=>\WCC\Olts\Api\GetOntInfo::class],
        ['methods' => ['GET'], 'pattern'=>'/interfaces/pon-ports/{device}', 'callable'=>\WCC\Olts\Api\GetPonInterfaces::class],
        ['methods' => ['GET'], 'pattern'=>'/interfaces/physical/{device}', 'callable'=>\WCC\Olts\Api\GetPhysicalInterfacesStatus::class],
        ['methods' => ['GET'], 'pattern'=>'/interfaces/parse/{device}/{interface}', 'callable'=>\WCC\Olts\Api\ParseInterfaceAction::class],
    ],

    //List of console commands
    //For create some console command you must create classes extended from WCAA\Modules\AbstractComponentCommand
    //For work with console used symfony console (https://symfony.com/doc/current/components/console.html)
    'console' => [],
    'description' => 'Working with OLTs ',
];
