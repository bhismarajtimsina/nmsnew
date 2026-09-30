<?php

return [
    //Key of module. key must be uniq for components
    'name' => 'mikbill_integration',
    'installer' => \WCC\MikBillIntegration\Installer::class,
    // List of rules
    'rules' => yaml_parse_file(__DIR__ . '/rules.yml'),

    //For work with another components in system you can create class with public method.
    //For access to object of module controller you must use ModuleInjector::getController(<name of module>)
    'controller' => \WCC\MikBillIntegration\Controllers\Controller::class,

    //List of processors
    //You can add methods for listen events.
    //For create event listener object you must implement ModuleEventListenerInterface.
    //Every event return object \WCAA\Models\Event
    'events' => [],

    'routes' => [],

    //List of console commands
    //For create some console command you must create classes extended from WCAA\Modules\AbstractComponentCommand
    //For work with console used symfony console (https://symfony.com/doc/current/components/console.html)
    'console' => [
        \WCC\MikBillIntegration\Console\SyncClients::class,
    ],
    'env_params' => yaml_parse_file(__DIR__ . '/params.yml'),
    'description' => 'Realize sync clients with billing mikbill',
];
