<?php

use Slim\Interfaces\RouteCollectorProxyInterface as Group;

return [
    //Key of module. key must be uniq for components
    'name' => 'notifications',
    'installer' => \WCC\Notifications\Installer::class,
    // List of rules
    'rules' => yaml_parse_file(__DIR__ . '/rules.yml'),

    'controller' => \WCC\Notifications\Controllers\Controller::class,

    'routes' => [
        ['methods' => ['GET'], 'pattern'=>'/contacts/by-user/{user}', 'callable'=>\WCC\Notifications\Api\GetContactsByUserAction::class],
        ['methods' => ['PUT'], 'pattern'=>'/contacts/by-user/{user}', 'callable'=>\WCC\Notifications\Api\SetContactsByUserAction::class],
        ['methods' => ['GET'], 'pattern'=>'/config/channel/defaults/{channel}', 'callable'=>\WCC\Notifications\Api\GetDefaultChannelConfigurationAction::class],
        ['methods' => ['GET'], 'pattern'=>'/config/channel/{channel}', 'callable'=>\WCC\Notifications\Api\GetChannelConfigurationAction::class],
        ['methods' => ['PUT'], 'pattern'=>'/config/channel/{channel}', 'callable'=>\WCC\Notifications\Api\UpdateChannelConfigurationAction::class],
        ['methods' => ['GET'], 'pattern'=>'/configured-channels', 'callable'=>\WCC\Notifications\Api\GetConfiguredChannels::class],
        ['methods' => ['GET'], 'pattern'=>'/config/actions', 'callable'=>\WCC\Notifications\Api\GetActionsConfiguration::class],
        ['methods' => ['GET'], 'pattern'=>'/config/events', 'callable'=>\WCC\Notifications\Api\GetEventConfiguration::class],
        ['methods' => ['PUT'], 'pattern'=>'/config/actions', 'callable'=>\WCC\Notifications\Api\UpdateActionsConfiguration::class],
        ['methods' => ['PUT'], 'pattern'=>'/config/events', 'callable'=>\WCC\Notifications\Api\UpdateEventsConfiguration::class],
        ['methods' => ['GET'], 'pattern'=>'/config/names/events', 'callable'=>\WCC\Notifications\Api\GetPossibleEventNames::class],
        ['methods' => ['GET'], 'pattern'=>'/config/names/actions', 'callable'=>\WCC\Notifications\Api\GetPossibleActionNames::class],
        ['methods' => ['GET'], 'pattern'=>'/devices', 'callable'=>\WCC\Notifications\Api\GetDevicesList::class],
        ['methods' => ['GET'], 'pattern'=>'/history/{by}/{id}', 'callable'=>\WCC\Notifications\Api\GetHistory::class],
    ],

    //List of console commands
    //For create some console command you must create classes extended from WCAA\Modules\AbstractComponentCommand
    //For work with console used symfony console (https://symfony.com/doc/current/components/console.html)
    'console' => [
        \WCC\Notifications\Console\NotificationSenderService::class,
        \WCC\Notifications\Console\NotificationSender::class,
        \WCC\Notifications\Console\TelegramBotListener::class,
    ],

    //List of RPC commands
    //For create rpc method you must create class with method __invoke() with list of arguments
    //In __construct() you can set all dependencies if you need.

    'rpc' => [],
    'env_params' => yaml_parse_file(__DIR__ . '/params.yml'),
    'event_listeners' => [
        \WCC\Notifications\Controllers\EventListener::class,
    ],
    'dependencies' => [
        'events',
    ],
    'description' => 'Sending notifications over Telegram/Email',

];
