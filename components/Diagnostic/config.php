<?php

use Slim\Interfaces\RouteCollectorProxyInterface as Group;

return [
    //Key of module. key must be uniq for components
    'name' => 'diagnostic',
    'installer' => \WCC\Diagnostic\Installer::class,
    // List of rules
    'rules' => yaml_parse_file(__DIR__ . '/rules.yml'),

    //For work with another components in system you can create class with public method.
    //For access to object of module controller you must use ModuleInjector::getController(<name of module>)
    'controller' => \WCC\Diagnostic\Controllers\Controller::class,

    //List of processors
    //You can add methods for listen events.
    //For create event listener object you must implement ModuleEventListenerInterface.
    //Every event return object \WCAA\Models\Event
    'events' => [],

    'routes' => [
        ['methods' => ['POST'], 'pattern'=>'/arp-ping', 'callable'=>\WCC\Diagnostic\Api\ArpPing::class],
        ['methods' => ['GET'], 'pattern'=>'/interface/{iface_id}/diag', 'callable'=>\WCC\Diagnostic\Api\Diagnostic::class],
        ['methods' => ['GET'], 'pattern'=>'/interface/{iface_id}/diag/html', 'callable'=>\WCC\Diagnostic\Api\RenderDiagnosticCard::class],
        ['methods' => ['GET'], 'pattern'=>'/interface/{iface_id}/links', 'callable'=>\WCC\Diagnostic\Api\Links::class],
    ],

    //List of console commands
    //For create some console command you must create classes extended from WCAA\Modules\AbstractComponentCommand
    //For work with console used symfony console (https://symfony.com/doc/current/components/console.html)
    'console' => [],

    'env_params' => [],
    'description' => 'Rest API interfaces for network diag',

    'load_modules' => [
        'switch' => ['vlans_by_port','cable_diag','interface_descriptions', 'interfaces_list', 'fdb', 'link_info', 'cable_diag', 'sfp_info', 'errors', 'interface_counters'],
        'olt' => ['interface_descriptions', 'pon_onts_status', 'pon_onts_optical', 'pon_onts_mac_addr', 'pon_onts_serial', 'fdb', 'interface_counters', 'pon_onts_reasons','pon_onts_vendor', 'uni_interfaces_status'],
    ],
];
