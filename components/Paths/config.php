<?php

return [
    //Key of module. key must be uniq for components
    'name' => 'paths',
    'installer' => \WCC\Paths\Installer::class,
    'rules' => yaml_parse_file(__DIR__ . '/rules.yml'),

    'controller' => \WCC\Paths\Controllers\Controller::class,

    'routes' => [
        //Map/overview reads
        ['methods' => ['GET'], 'pattern' => '/map', 'callable' => \WCC\Paths\Api\GetMapView::class],
        ['methods' => ['GET'], 'pattern' => '/groups', 'callable' => \WCC\Paths\Api\GetGroupStates::class],

        //CRUD
        ['methods' => ['GET'], 'pattern' => '', 'callable' => \WCC\Paths\Api\GetAll::class],
        ['methods' => ['POST'], 'pattern' => '', 'callable' => \WCC\Paths\Api\AddAction::class],
        ['methods' => ['GET'], 'pattern' => '/{id}', 'callable' => \WCC\Paths\Api\GetByIdAction::class],
        ['methods' => ['PUT'], 'pattern' => '/{id}', 'callable' => \WCC\Paths\Api\UpdateAction::class],
        ['methods' => ['DELETE'], 'pattern' => '/{id}', 'callable' => \WCC\Paths\Api\DeleteAction::class],
        ['methods' => ['PUT'], 'pattern' => '/{id}/segments', 'callable' => \WCC\Paths\Api\SetSegmentsAction::class],
    ],

    'console' => [
        \WCC\Paths\Console\CalculatePathState::class,
    ],

    'env_params' => yaml_parse_file(__DIR__ . '/params.yml'),

    //Segment health is derived from Links adjacency and Pinger reachability.
    'dependencies' => [
        'links',
        'pinger',
    ],
    'description' => 'Monitor multi-hop transport paths and redundancy groups',
];
