<?php

namespace WCC\Notifications\Controllers;

use DI\Container;
use Monolog\Logger;
use WCAA\Infrastructure\ComponentInjector;
use WCAA\Infrastructure\Components\AbstractComponentController;
use WCAA\Models\Devices\Device;
use WCAA\Models\User\User;
use WCAA\Models\User\UserRole;
use WCAA\Storage\SystemComponentsStorage;
use WCAA\Storage\UserStorage;
use WCC\Notifications\Controllers\Channels\Mail;
use WCC\Notifications\Controllers\Channels\ChannelInterface;
use WCC\Notifications\Controllers\Channels\Telegram;
use WCC\Notifications\Models\NotificationActionConfig;
use WCC\Notifications\Models\NotificationContact;
use WCC\Notifications\Models\Notification;
use WCC\Notifications\Models\NotificationEventConfig;
use WCC\Notifications\Models\NotificationFilter;
use WCC\Notifications\Storage\NotificationActionConfigStorage;
use WCC\Notifications\Storage\NotificationEventConfigStorage;
use WCC\Notifications\Storage\NotificationsContactsStorage;
use WCC\Notifications\Storage\NotificationsStorage;
use WCC\Events\Models\Event;

class Controller extends AbstractComponentController
{

    /**
     * @Inject
     * @var NotificationsContactsStorage
     */
    protected $alertContactStorage;

    /**
     * @Inject
     * @var NotificationsStorage
     */
    protected $alertEventStorage;

    /**
     * @Inject
     * @var UserStorage
     */
    protected $userStorage;


    /**
     * @Inject
     * @var NotificationActionConfigStorage
     */
    protected $actionConfigStorage;


    /**
     * @Inject
     * @var NotificationEventConfigStorage
     */
    protected $eventConfigStorage;

    /**
     * @var array
     */
    protected $config;


    function __construct(Container $c, SystemComponentsStorage $componentsStorage, ComponentInjector $componentInjector, Logger $logger)
    {
        $this->channels = [
            'telegram' => $c->get(Telegram::class),
            'email' => $c->get(Mail::class),
        ];

        $this->config = $componentsStorage->getByKey('notifications')->getConfiguration();
        parent::__construct($componentInjector, $logger);

    }

    /**
     * @param User $user
     * @param NotificationContact[] $contacts
     * @param NotificationContact[]
     */
    function setContactsByUser(User $user, array $contacts)
    {
        $this->alertContactStorage->begin();
        $this->alertContactStorage->deleteByUser($user);
        foreach ($contacts as $contact) {
            $this->alertContactStorage->add($contact);
        }
        $this->alertContactStorage->commit();
        return $this->getContactsByUser($user);
    }

    /**
     * @param User $user
     * @return NotificationContact[]
     */
    function getContactsByUser(User $user)
    {
        return $this->alertContactStorage->getByUser($user);
    }

    function getConfiguredSources()
    {
        $configuredSources = [];
        foreach ($this->channels as $key => $channel) {
            if ($channel->isConfigured()) {
                if ($key == 'email') {
                    $configuredSources[$key] = [
                        'key' => $key,
                        'from_email' => $channel->getConfiguration()['from_email'],
                        'from_name' => $channel->getConfiguration()['from_name'],
                    ];
                }
                if ($key == 'telegram') {
                    $configuredSources[$key] = [
                        'key' => $key,
                        'bot_username' => $channel->getConfiguration()['bot_username'],
                        'url' => "https://t.me/" . $channel->getConfiguration()['bot_username'],
                    ];
                }
            }
        }
        return $configuredSources;
    }

    function getChannelConfig($source)
    {
        if (isset($this->channels[$source])) {
            return $this->channels[$source]->getConfiguration();
        }
        throw new \Exception("Unknown source with name $source");
    }

    function getDefaultConfig($source)
    {
        if (isset($this->channels[$source])) {
            return $this->channels[$source]->getDefaultConfig();
        }
        throw new \Exception("Unknown source with name $source");
    }

    function updateConfiguration($channel, $configuration)
    {
        if (!isset($this->channels[$channel])) {
            throw new \Exception("Source with name $channel not found");
        }
        return $this->channels[$channel]->updateConfiguration($configuration);
    }

    /**
     * @return NotificationEventConfig[]
     */
    function getEventsConfiguration() {
        return $this->eventConfigStorage->fetchAll();
    }

    /**
     * @return NotificationActionConfig[]
     */
    function getActionsConfiguration() {
        return $this->actionConfigStorage->fetchAll();
    }

    /**
     * @param NotificationEventConfig[] $events
     * @return NotificationEventConfig[]
     */
    function updateEventsConfiguration(array $events) {
        return $this->eventConfigStorage->updateAll($events);
    }

    /**
     * @param NotificationActionConfig[] $actions
     * @return NotificationActionConfig[]
     */
    function updateActionsConfiguration(array $actions) {
        return $this->actionConfigStorage->updateAll($actions);
    }
}
