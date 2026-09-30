<?php

use Slim\Interfaces\RouteCollectorProxyInterface as Group;

return [
    //Key of module. key must be uniq for components
    'name' => 'olts_control',
    'installer' => \WCC\OltsControl\Installer::class,
    // List of rules
    'rules' => yaml_parse_file(__DIR__ . '/rules.yml'),

    //For work with another components in system you can create class with public method.
    //For access to object of module controller you must use ModuleInjector::getController(<name of module>)
    'controller' => \WCC\OltsControl\Controllers\Controller::class,

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
        ['methods' => ['PUT'], 'pattern'=>'/ont/dereg/{device}/{interface}', 'callable'=>\WCC\OltsControl\Api\DeregOnuAction::class],
        ['methods' => ['PUT'], 'pattern'=>'/ont/clear-pon/{device}/{interface}', 'callable'=>\WCC\OltsControl\Api\ClearPonPortAction::class],
        ['methods' => ['PUT'], 'pattern'=>'/ont/reboot/{device}/{interface}', 'callable'=>\WCC\OltsControl\Api\RebootOnuAction::class],
        ['methods' => ['PUT'], 'pattern'=>'/ont/reset/{device}/{interface}', 'callable'=>\WCC\OltsControl\Api\ResetOnuAction::class],
        ['methods' => ['PUT'], 'pattern'=>'/olt/reset-port/{device}/{interface}', 'callable'=>\WCC\OltsControl\Api\ResetPortAction::class],
        ['methods' => ['PUT'], 'pattern'=>'/olt/interface/description/{device}/{interface}', 'callable'=>\WCC\OltsControl\Api\ChangePortDescription::class],
        ['methods' => ['PUT'], 'pattern'=>'/ont/disable/{device}/{interface}', 'callable'=>\WCC\OltsControl\Api\DisableOnuAction::class],
        ['methods' => ['PUT'], 'pattern'=>'/ont/description/{device}/{interface}', 'callable'=>\WCC\OltsControl\Api\DescriptionOnuAction::class],
        ['methods' => ['PUT'], 'pattern'=>'/ont/uni-control/{device}/{interface}', 'callable'=>\WCC\OltsControl\Api\ControlUniPorts::class],
    ],

    //List of console commands
    //For create some console command you must create classes extended from WCAA\Modules\AbstractComponentCommand
    //For work with console used symfony console (https://symfony.com/doc/current/components/console.html)
    'console' => [],
    'description' => 'ONU management component (change description, overwrite, delete)',
    'dependencies' => [
        'olts'
    ],
];
