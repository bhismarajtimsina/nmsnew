<?php

return [
    //Key of module. key must be uniq for components
    'name' => 'qr-generator',
    'installer' => \WCC\QrGenerator\Installer::class,
    // List of rules
    'rules' => yaml_parse_file(__DIR__ . '/rules.yml'),

    //For work with another components in system you can create class with public method.
    //For access to object of module controller you must use ModuleInjector::getController(<name of module>)
    'controller' => \WCC\QrGenerator\Controllers\Controller::class,

    //List of processors
    'events' => [],

    'routes' => [
      ['methods' => ['GET'], 'pattern'=>'/qr-code-base64/{type}/{id}', 'callable'=>\WCC\QrGenerator\Api\GetQrBase64::class],
    ],

    //List of console commands
    'console' => [],

    'event_listeners' => [
    ],
    'env_params' => [],
    'description' => 'Generate QR codes of system objects',
    'path' => '/www/var/qr-generator',
];
