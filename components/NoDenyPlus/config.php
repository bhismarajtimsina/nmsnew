<?php

use Slim\Interfaces\RouteCollectorProxyInterface as Group;

$envParams = yaml_parse_file(__DIR__ . '/params.yml');

// Adding parameter check topology if component links exists and enabled
//
//- param_name: NODENY_COMPARISON_CHECK_TOPOLOGY
//    type: checkbox
//    default: false
if(file_exists(__DIR__ . '/../Links/config.php')) {
    $envParams['nodeny_plus'][] = [
        'param_name' => 'NODENY_COMPARISON_CHECK_TOPOLOGY',
        'type' => 'checkbox',
        'default' => false,
    ];
}

return [
    //Key of module. key must be uniq for components
    'name' => 'nodeny_plus',
    'installer' => \WCC\NoDenyPlus\Installer::class,
    // List of rules
    'rules' => yaml_parse_file(__DIR__ . '/rules.yml'),

    //For work with another components in system you can create class with public method.
    //For access to object of module controller you must use ModuleInjector::getController(<name of module>)
    'controller' => \WCC\NoDenyPlus\Controllers\Controller::class,

    //List of processors
    //You can add methods for listen events.
    //For create event listener object you must implement ModuleEventListenerInterface.
    //Every event return object \WCAA\Models\Event
    'events' => [],

    'routes' => [
        ['methods' => ['GET'], 'pattern'=>'/billing/render-diag-card', 'callable'=>\WCC\NoDenyPlus\Api\RenderDiagnosticCard::class],
        ['methods' => ['GET'], 'pattern'=>'/billing/diagnostic', 'callable'=>\WCC\NoDenyPlus\Api\Diagnostic::class],
    ],

    //List of console commands
    //For create some console command you must create classes extended from WCAA\Modules\AbstractComponentCommand
    //For work with console used symfony console (https://symfony.com/doc/current/components/console.html)
    'console' => [
        \WCC\NoDenyPlus\Console\SyncClients::class,
    ],
    'env_params' => $envParams,
    'description' => 'Realize sync with NoDeny plus',
];
