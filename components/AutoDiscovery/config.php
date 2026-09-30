<?php

use Slim\Interfaces\RouteCollectorProxyInterface as Group;

return [
    'name' => 'autodiscovery',
    'installer' => \WCC\AutoDiscovery\Installer::class,
    'controller' => \WCC\AutoDiscovery\Controllers\Controller::class,
    'console' => [
        \WCC\AutoDiscovery\Console\Discovery::class,
    ],
    'routes' => [
        ['methods' => ['GET'], 'pattern' => '/all', 'callable' => \WCC\AutoDiscovery\Api\GetDiscoveryNetworks::class],
        ['methods' => ['PUT'], 'pattern' => '/all', 'callable' => \WCC\AutoDiscovery\Api\UpdateDiscoveryNetworks::class],
    ],
    'rules' => [
        [
            'key' => 'system_configuration',
            'routes' => [
                '^.*?:/.*$'
            ],
        ]
    ],
    'description' => 'Scan networks and automatic add devices',
];
