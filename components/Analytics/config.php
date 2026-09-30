<?php

return [
    //Key of module. key must be uniq for components
    'name' => 'analytics',
    'installer' => \WCC\Analytics\Installer::class,
    // List of rules
    'rules' => yaml_parse_file(__DIR__ . '/rules.yml'),

    //For work with another components in system you can create class with public method.
    //For access to object of module controller you must use ModuleInjector::getController(<name of module>)
    'controller' => \WCC\Analytics\Controllers\Controller::class,

    //List of processors
    //You can add methods for listen events.
    //For create event listener object you must implement ModuleEventListenerInterface.
    //Every event return object \WCAA\Models\Event
    'events' => [],

    'routes' => [
        ['methods' => ['GET'], 'pattern'=>'/parameters/device-list', 'callable'=>\WCC\Analytics\Api\DeviceListAction::class],
        ['methods' => ['GET'], 'pattern'=>'/parameters/device-groups', 'callable'=>\WCC\Analytics\Api\DeviceGroupsAction::class],
        ['methods' => ['POST'], 'pattern'=>'/charts/ont-statuses', 'callable'=>\WCC\Analytics\Api\OntStatusesSeriesAction::class],
        ['methods' => ['POST'], 'pattern'=>'/charts/device-statuses', 'callable'=>\WCC\Analytics\Api\DevicesStatusSeriesAction::class],
        ['methods' => ['POST'], 'pattern'=>'/charts/increasing-errors', 'callable'=>\WCC\Analytics\Api\GetErrorsAnalyticsSeriesAction::class],

        ['methods' => ['GET'], 'pattern'=>'/table/optical-drift', 'callable'=>\WCC\Analytics\Api\OpticalDriftAction::class],
        ['methods' => ['GET', 'PUT'], 'pattern'=>'/table/increasing-errors', 'callable'=>\WCC\Analytics\Api\GetErrorsIncreasingAnalyticsTable::class],
        ['methods' => ['GET', 'PUT'], 'pattern'=>'/table/ont-statuses-history', 'callable'=>\WCC\Analytics\Api\OntStatusesTableAction::class],
        ['methods' => ['GET', 'PUT'], 'pattern'=>'/table/signal-strength', 'callable'=>\WCC\Analytics\Api\OntSignalTableAction::class],
        ['methods' => ['GET', 'PUT'], 'pattern'=>'/table/device-statuses', 'callable'=>\WCC\Analytics\Api\DevicesStatusTableAction::class],
        ['methods' => ['GET', 'PUT'], 'pattern'=>'/table/duplicated-mac-addresses', 'callable'=>\WCC\Analytics\Api\DuplicatedMacAdrressesTable::class],
        ['methods' => ['GET', 'PUT'], 'pattern'=>'/table/duplicated-onts', 'callable'=>\WCC\Analytics\Api\DuplicatedOntIdentsTable::class],
        ['methods' => ['GET', 'PUT'], 'pattern'=>'/table/ont-list', 'callable'=>\WCC\Analytics\Api\GetOntList::class],

        ['methods' => ['GET', 'PUT', 'POST'], 'pattern'=>'/bars/ont-levels', 'callable'=>\WCC\Analytics\Api\OntSignalLevelsBar::class],

        ['methods' => ['GET', 'POST'], 'pattern'=>'/errors/increasing-chart/by-interface/{interface_id}', 'callable'=>\WCC\Analytics\Api\GetErrorsIncreasingSeriesAction::class],
        ['methods' => ['GET'], 'pattern'=>'/errors/increasing-data/by-interface/{interface_id}', 'callable'=>\WCC\Analytics\Api\GetErrorsIncreasingByInterface::class],
        ['methods' => ['GET'], 'pattern'=>'/errors/increasing-data/by-device/{device_id}', 'callable'=>\WCC\Analytics\Api\GetErrorsIncreasingByDevice::class],
        ['methods' => ['GET'], 'pattern'=>'/widgets/bad-signals', 'callable'=>\WCC\Analytics\Api\Widgets\WithBadSignals::class],

    ],

    //List of console commands
    //For create some console command you must create classes extended from WCAA\Modules\AbstractComponentCommand
    //For work with console used symfony console (https://symfony.com/doc/current/components/console.html)
    'console' => [
        \WCC\Analytics\Console\DuplicatedOntIdents::class,
        \WCC\Analytics\Console\DuplicatedMacAddresses::class,
    ],

    'event_listeners' => [
        \WCC\Analytics\Controllers\EventListener::class,
    ],
    'env_params' => yaml_parse_file(__DIR__ . '/params.yml'),
    'description' => 'View live and historical device data',
];
