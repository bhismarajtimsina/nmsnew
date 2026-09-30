<?php

use Slim\Interfaces\RouteCollectorProxyInterface as Group;

return [
    //Key of module. key must be uniq for components
    'name' => 'events',
    'installer' => \WCC\Events\Installer::class,
    // List of rules
    'rules' => yaml_parse_file(__DIR__ . '/rules.yml'),

    'controller' => \WCC\Events\Controllers\Controller::class,

    'routes' => [
        ['methods' => ['GET'], 'pattern'=>'/params/names', 'callable'=>\WCC\Events\Api\GetEventNames::class],
        ['methods' => ['GET', 'POST'], 'pattern'=> '', 'callable'=>\WCC\Events\Api\GetEventsFilteredAction::class],
        ['methods' => ['PUT'], 'pattern'=>'/{id}/resolve', 'callable'=>\WCC\Events\Api\ResolveEventAction::class],
        ['methods' => ['PUT'], 'pattern'=>'/resolve-all', 'callable'=>\WCC\Events\Api\ResolveAllFilteredEvents::class],
        ['methods' => ['GET', 'POST'], 'pattern'=>'/incidents', 'callable'=>\WCC\Events\Api\GetIncidentsAction::class],
        ['methods' => ['GET'], 'pattern'=>'/severity-stat', 'callable'=>\WCC\Events\Api\GetSeverityStat::class],
        ['methods' => ['GET'], 'pattern'=>'/count-by-name', 'callable'=>\WCC\Events\Api\CountEventsByName::class],
        ['methods' => ['GET'], 'pattern'=>'/alertmanager', 'callable'=>\WCC\Events\Api\Alertmanager\GetRulesList::class],
        ['methods' => ['PUT'], 'pattern'=>'/alertmanager', 'callable'=>\WCC\Events\Api\Alertmanager\UpdateRules::class],
        ['methods' => ['PUT'], 'pattern'=>'/alertmanager/validate-expression', 'callable'=>\WCC\Events\Api\Alertmanager\ValidateExpression::class],
        ['methods' => ['PUT'], 'pattern'=>'/alertmanager/validate-rule', 'callable'=>\WCC\Events\Api\Alertmanager\ValidateRule::class],
    ],

    //List of console commands
    //For create some console command you must create classes extended from WCAA\Modules\AbstractComponentCommand
    //For work with console used symfony console (https://symfony.com/doc/current/components/console.html)
    'console' => [
        \WCC\Events\Console\ApplyRulesCommand::class,
        \WCC\Events\Console\ClearOldDataCommand::class,
        \WCC\Events\Console\SyncActiveAlertsCommand::class,
    ],

    //List of RPC commands
    //For create rpc method you must create class with method __invoke() with list of arguments
    //In __construct() you can set all dependencies if you need.

    'rpc' => [],
    'env_params' => [

    ],
    'testing_rules_path' => __DIR__ . '/../../var/prometheus/test-rules.yml',
    'rules_path' => __DIR__ . '/../../var/prometheus/custom.rules.yml',
    'event_listeners' => [
        \WCC\Events\Controllers\EventListener::class,
    ],
    'description' => 'Realize events functional'
];
