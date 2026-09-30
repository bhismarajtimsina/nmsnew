<?php

return [
    'name' => 'macros',
    'installer' => \WCC\Macros\Installer::class,
    'controller' => \WCC\Macros\Controllers\MacrosGateway::class,
    'rules' => yaml_parse_file(__DIR__ . '/rules.yml'),
    'events' => [],
    'routes' => [
        //Управление макросов
        ['methods' => ['GET'],    'pattern' => '/control', 'callable' => \WCC\Macros\Api\Control\MacrosList::class],
        ['methods' => ['POST'],   'pattern' => '/control/preview', 'callable' => \WCC\Macros\Api\Control\MacrosLiveView::class],
        ['methods' => ['POST'],   'pattern' => '/control/variables', 'callable' => \WCC\Macros\Api\Control\MacrosLiveVariables::class],
        ['methods' => ['GET'],    'pattern' => '/control/{id}', 'callable' => \WCC\Macros\Api\Control\GetMacrosById::class],
        ['methods' => ['PUT'],    'pattern' => '/control/{id}', 'callable' => \WCC\Macros\Api\Control\UpdateMacros::class],
        ['methods' => ['PUT'],    'pattern' => '/control/clone/{id}', 'callable' => \WCC\Macros\Api\Control\CloneMacros::class],
        ['methods' => ['POST'],   'pattern' => '/control', 'callable' => \WCC\Macros\Api\Control\CreateMacros::class],
        ['methods' => ['DELETE'], 'pattern' => '/control/{id}', 'callable' => \WCC\Macros\Api\Control\DeleteMacros::class],

        ['methods' => ['POST'], 'pattern' => '/execute', 'callable' => \WCC\Macros\Api\ExecuteMacros::class],
        ['methods' => ['GET'], 'pattern' => '/execute/progress/{id}', 'callable' => \WCC\Macros\Api\GetExecutionProgress::class],
        ['methods' => ['POST'], 'pattern' => '/variables', 'callable' => \WCC\Macros\Api\PreviewVariables::class],
        ['methods' => ['GET'], 'pattern' => '/macro/{id}', 'callable' => \WCC\Macros\Api\GetMacrosById::class],
        ['methods' => ['GET'], 'pattern' => '/list', 'callable' => \WCC\Macros\Api\GetMacrossesListByParameters::class],
    ],
    'console' => [
    ],
    'description' => 'Allow create some macros',
    'dependencies' => [
        'olts'
    ],
];
