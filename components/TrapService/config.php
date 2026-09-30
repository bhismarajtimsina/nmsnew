<?php

return [
    //Key of module. key must be uniq for components
    'name' => 'trapservice',
    'installer' => \WCC\TrapService\Installer::class,
    // List of rules
    'rules' => [],

    //For work with another components in system you can create class with public method.
    //For access to object of module controller you must use ModuleInjector::getController(<name of module>)
    'controller' => \WCC\TrapService\Controllers\Controller::class,

    //List of processors
    //You can add methods for listen events.
    //For create event listener object you must implement ModuleEventListenerInterface.
    //Every event return object \WCAA\Models\Event
    'events' => [],

    'routes' => [
        ['methods' => ['GET'], 'pattern'=>'/history/params/object-names', 'callable'=>\WCC\TrapService\Api\GetObjectNames::class],
        ['methods' => ['GET'], 'pattern'=>'/history/stat/by-objects', 'callable'=>\WCC\TrapService\Api\GetStatByObjects::class],
        ['methods' => ['GET'], 'pattern'=>'/history/stat/by-device', 'callable'=>\WCC\TrapService\Api\GetStatByDevice::class],
        ['methods' => ['GET', 'POST'], 'pattern'=> '/history', 'callable'=>\WCC\TrapService\Api\GetFilteredAction::class],
    ],

    //List of console commands
    //For create some console command you must create classes extended from WCAA\Modules\AbstractComponentCommand
    //For work with console used symfony console (https://symfony.com/doc/current/components/console.html)
    'console' => [
        \WCC\TrapService\Console\Handler::class,
        \WCC\TrapService\Console\ClearOldLogs::class,
    ],
    'env_params' => yaml_parse_file(__DIR__ . '/params.yml'),
    'description' => 'Realize trap listener service',
];
