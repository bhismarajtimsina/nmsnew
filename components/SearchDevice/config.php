<?php

use Slim\Interfaces\RouteCollectorProxyInterface as Group;

return [
    //Key of module. key must be uniq for components
    'name' => 'search_device',
    'installer' => \WCC\SearchDevice\Installer::class,
    // List of rules
    'rules' => yaml_parse_file(__DIR__ . '/rules.yml'),

    //For work with another components in system you can create class with public method.
    //For access to object of module controller you must use ModuleInjector::getController(<name of module>)
    'controller' => \WCC\SearchDevice\Controllers\Controller::class,

    //List of processors
    //You can add methods for listen events.
    //For create event listener object you must implement ModuleEventListenerInterface.
    //Every event return object \WCAA\Models\Event
    'events' => [],

    'routes' => [
        ['methods' => ['POST'], 'pattern'=>'/search-mac', 'callable'=>\WCC\SearchDevice\Api\SearchMacAction::class],
        ['methods' => ['POST'], 'pattern'=>'/search-ip', 'callable'=>\WCC\SearchDevice\Api\SearchIpAction::class],
        ['methods' => ['POST'], 'pattern'=>'/search-ip-with-fdb', 'callable'=>\WCC\SearchDevice\Api\SearchIpWithFdbAction::class],
    ],

    //List of console commands
    //For create some console command you must create classes extended from WCAA\Modules\AbstractComponentCommand
    //For work with console used symfony console (https://symfony.com/doc/current/components/console.html)
    'console' => [],

    'env_params' => yaml_parse_file(__DIR__ . '/params.yml'),
    'description' => 'Rest API interfaces for search devices over IP or MAC-address',
];
