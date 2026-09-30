<?php

return [
    'name' => 'onts_registration',
    'installer' => \WCC\OntsRegistration\Installer::class,
    'controller' => \WCC\OntsRegistration\Controllers\MacrosGateway::class,
    'rules' => yaml_parse_file(__DIR__ . '/rules.yml'),
    'events' => [],
    'routes' => [
        //Управление макросов
        ['methods' => ['GET'],    'pattern' => '/control', 'callable' => \WCC\OntsRegistration\Api\Control\MacrosList::class],
        ['methods' => ['POST'],   'pattern' => '/control/preview', 'callable' => \WCC\OntsRegistration\Api\Control\MacrosLiveView::class],
        ['methods' => ['POST'],   'pattern' => '/control/variables', 'callable' => \WCC\OntsRegistration\Api\Control\MacrosLiveVariables::class],
        ['methods' => ['GET'],    'pattern' => '/control/{id}', 'callable' => \WCC\OntsRegistration\Api\Control\GetMacrosById::class],
        ['methods' => ['PUT'],    'pattern' => '/control/{id}', 'callable' => \WCC\OntsRegistration\Api\Control\UpdateMacros::class],
        ['methods' => ['PUT'],    'pattern' => '/control/clone/{id}', 'callable' => \WCC\OntsRegistration\Api\Control\CloneMacros::class],
        ['methods' => ['POST'],   'pattern' => '/control', 'callable' => \WCC\OntsRegistration\Api\Control\CreateMacros::class],
        ['methods' => ['DELETE'], 'pattern' => '/control/{id}', 'callable' => \WCC\OntsRegistration\Api\Control\DeleteMacros::class],
        ['methods' => ['POST'], 'pattern' => '/execute', 'callable' => \WCC\OntsRegistration\Api\ExecuteMacros::class],
        ['methods' => ['GET'], 'pattern' => '/execute/progress/{id}', 'callable' => \WCC\OntsRegistration\Api\GetExecutionProgress::class],
        ['methods' => ['POST'], 'pattern' => '/variables', 'callable' => \WCC\OntsRegistration\Api\PreviewVariables::class],
        ['methods' => ['GET'], 'pattern' => '/by-device/{device_id}', 'callable' => \WCC\OntsRegistration\Api\GetMacroByDevice::class],
        ['methods' => ['GET'], 'pattern' => '/by-ident/{device_id}/{ident}', 'callable' => \WCC\OntsRegistration\Api\GetOntByIdent::class],
        ['methods' => ['GET'], 'pattern' => '/unregistered[/{device_id}]', 'callable' => \WCC\OntsRegistration\Api\GetUnregistered::class],
    ],

    'console' => [
        \WCC\OntsRegistration\Console\GetUnregisteredOnts::class
    ],
    'description' => 'Allow to get unregistered onts and register',
    'dependencies' => [
        'olts'
    ],
];
