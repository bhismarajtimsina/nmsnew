<?php

use Slim\Interfaces\RouteCollectorProxyInterface as Group;

return [
    //Key of module. key must be uniq for components
    'name' => 'router_os',
    'installer' => \WCC\RouterOS\Installer::class,
    // List of rules
    'rules' => yaml_parse_file(__DIR__ . '/rules.yml'),

    //For work with another components in system you can create class with public method.
    //For access to object of module controller you must use ModuleInjector::getController(<name of module>)
    'controller' => \WCC\RouterOS\Controllers\Controller::class,

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
        ['methods' => ['GET'], 'pattern'=>'/resources/{id}', 'callable'=>\WCC\RouterOS\Api\GetResources::class],
        ['methods' => ['GET'], 'pattern'=>'/device/{id}/resources', 'callable'=>\WCC\RouterOS\Api\GetResources::class],
        ['methods' => ['GET'], 'pattern'=>'/device/{id}/address-list', 'callable'=>\WCC\RouterOS\Api\AddressListInfoAction::class],
        ['methods' => ['GET'], 'pattern'=>'/device/{id}/arp-list', 'callable'=>\WCC\RouterOS\Api\ArpInfoAction::class],
        ['methods' => ['GET'], 'pattern'=>'/device/{id}/dhcp-servers-list', 'callable'=>\WCC\RouterOS\Api\DhcpServersListAction::class],
        ['methods' => ['GET'], 'pattern'=>'/device/{id}/bgp-sessions-list', 'callable'=>\WCC\RouterOS\Api\BgpSessionsListAction::class],
        ['methods' => ['GET'], 'pattern'=>'/device/{id}/leases-list', 'callable'=>\WCC\RouterOS\Api\LeaseInfoAction::class],
        ['methods' => ['GET'], 'pattern'=>'/device/{id}/simple-queue-list', 'callable'=>\WCC\RouterOS\Api\SimpleQueueInfoAction::class],
        ['methods' => ['GET'], 'pattern'=>'/device/{id}/interface-vlans-list', 'callable'=>\WCC\RouterOS\Api\InterfaceVlanInfoAction::class],
        ['methods' => ['GET'], 'pattern'=>'/device/{id}/interfaces-list', 'callable'=>\WCC\RouterOS\Api\InterfacesListAction::class],
        ['methods' => ['GET'], 'pattern'=>'/tab-stats/{id}', 'callable'=>\WCC\RouterOS\Api\GetDeviceTabsStat::class],
    ],


    //List of console commands
    //For create some console command you must create classes extended from WCAA\Modules\AbstractComponentCommand
    //For work with console used symfony console (https://symfony.com/doc/current/components/console.html)
    'console' => [
    ],
    'description' => 'Working with routerOS',
];
