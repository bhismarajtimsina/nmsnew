<?php

return [
    //Key of module. key must be uniq for components
    'name' => 'attachments',
    'installer' => \WCC\Attachments\Installer::class,
    // List of rules
    'rules' => yaml_parse_file(__DIR__ . '/rules.yml'),

    //For work with another components in system you can create class with public method.
    //For access to object of module controller you must use ModuleInjector::getController(<name of module>)
    'controller' => \WCC\Attachments\Controllers\Controller::class,

    //List of processors
    'events' => [],

    'routes' => [
      ['methods' => ['GET'], 'pattern'=>'/list/{type}/{id}', 'callable'=>\WCC\Attachments\Api\ListByObject::class],
      ['methods' => ['POST'], 'pattern'=>'/upload/{type}/{id}', 'callable'=>\WCC\Attachments\Api\Upload::class],
      ['methods' => ['GET'], 'pattern'=>'/meta/{uuid}', 'callable'=>\WCC\Attachments\Api\GetAttachmentMeta::class],
      ['methods' => ['GET'], 'pattern'=>'/object/{uuid}', 'callable'=>\WCC\Attachments\Api\GetAttachment::class],
      ['methods' => ['GET'], 'pattern'=>'/thumb/{uuid}', 'callable'=>\WCC\Attachments\Api\GetAttachmentThumb::class],
      ['methods' => ['DELETE'], 'pattern'=>'/object/{uuid}', 'callable'=>\WCC\Attachments\Api\DeleteAttachment::class],
    ],

    //List of console commands
    'console' => [
        \WCC\Attachments\Console\ClearNotExistedAttachments::class,
    ],

    'event_listeners' => [
    ],
    'env_params' => [],
    'description' => 'View live and historical device data',
    'path' => '/www/var/attachments',
];
