<?php

namespace WCC\Notifications\Controllers\NotificationGenerators;

use Monolog\Logger;
use WCAA\App;
use WCAA\Models\SystemAction;
use WCAA\Storage\UserRoleStorage;
use WCAA\Storage\UserStorage;
use WCC\Events\Models\Event;
use WCC\Notifications\Models\Notification;
use WCC\Notifications\Models\NotificationContact;
use WCC\Notifications\Models\NotificationFilter;
use WCC\Notifications\Storage\NotificationActionConfigStorage;
use WCC\Notifications\Storage\NotificationsContactsStorage;
use WCC\Notifications\Storage\NotificationsStorage;

abstract class AbstractNotificationGenerator
{

    /**
     * @var Logger
     */
    protected $logger;


    function __construct(Logger $logger)
    {
        $this->logger = clone $logger;
    }

    /**
     * @Inject
     * @var NotificationsContactsStorage
     */
    protected $notificationsContactsStorage;


    /**
     * @Inject
     * @var UserRoleStorage
     */
    protected $roleStorage;

    /**
     * @Inject
     * @var UserStorage
     */
    protected $userStorage;

    /**
     * @Inject
     * @var NotificationsStorage
     */
    protected $notificationsStorage;

    /**
     * @param Notification $alertEvent
     * @return Notification|null
     */
    protected function searchPreviousByNotification(Notification $alertEvent)
    {
        $alertEvents = $this->notificationsStorage->getByEventFilter(
            (new NotificationFilter())
                ->setEvent($alertEvent->getEvent())
                ->setAlertContact($alertEvent->getContact())
                ->setType('alert')
        );
        if(count($alertEvents) > 0) {
            return $alertEvents[0];
        }
        return  null;
    }

    protected function getUsers($data) {
        $this->logger->info("Load users by data", $data->getAsArray());
        if ($data->getDevice()) {
            $users = $this->userStorage->getUsersByDeviceGroup($data->getDevice()->getGroup());
        } else {
            $users = [];
            foreach ($this->roleStorage->getRolesByPermissionName('notifications_send_global_notify') as $role) {
                $users = array_merge($users, $this->userStorage->getUsersByRole($role));
            }
        }
        return $users;
    }

    abstract function process($data);
}