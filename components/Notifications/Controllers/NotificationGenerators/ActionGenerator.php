<?php

namespace WCC\Notifications\Controllers\NotificationGenerators;

use WCAA\Models\SystemAction;
use WCC\Events\Models\Event;
use WCC\Notifications\Models\Notification;
use WCC\Notifications\Models\NotificationContact;
use WCC\Notifications\Storage\NotificationActionConfigStorage;

class ActionGenerator extends AbstractNotificationGenerator
{


    /**
     * @Inject
     * @var NotificationActionConfigStorage
     */
    protected $actionsConfigStorage;


    function process($data)
    {
        if(!($data instanceof SystemAction)) {
            throw new \Exception("Action generator working only with System action");
        }
        if (!$this->isSendAllowed($data)) {
            return;
        }
        $contacts = $this->getContacts($data);
        foreach ($contacts as $contact) {
            if($contact->getType() == NotificationContact::TYPE_PHONE_FOR_TELEGRAM) continue;
            if($contact->getType() == NotificationContact::TYPE_PHONE) continue;

            $this->notificationsStorage->add(
                (new Notification())
                    ->setAction($data)
                    ->setContact($contact)
                    ->setSendAt(date("Y-m-d H:i:s"))
                    ->setType('notification')
            );
        }
    }


    protected function isSendAllowed(SystemAction $data)
    {
        $cfg = $this->actionsConfigStorage->searchByName($data->getAction());
        if (!$cfg) {
            $this->logger->info("Action with name {$data->getAction()} not configure for sending, ignoring...");
            return false;
        }

        if($data->getDevice()) {
            $ignoredDevicesArray = array_filter($cfg->getIgnoredDevices(), function ($dev) use ($data) {
                return $data->getDevice()->getId() === $dev->getId();
            });
            if(count($ignoredDevicesArray) > 0) {
                return  false;
            }
        }

        if (!$cfg->isEnabled()) {
            return false;
        }
        if ($cfg->isSendOnStatusFailed() && $data->getStatus() == SystemAction::STATUS_FAILED) {
            return true;
        }
        if ($cfg->isSendOnStatusSuccess() && $data->getStatus() == SystemAction::STATUS_SUCCESS) {
            return true;
        }
        $this->logger->info("Action with name {$data->getAction()} with status {$data->getStatus()} ignoring, becouse status disabled...");
        return false;
    }

    /**
     * @param SystemAction $data
     * @return NotificationContact[]
     */
    protected function getContacts($data)
    {
        $users = $this->getUsers($data);
        $this->logger->info("Found " . count($users) . " users");
        $contacts = [];
        foreach ($users as $user) {
            $this->logger->info("Try get contacts by user {$user->getLogin()}");
            $userContacts = $this->notificationsContactsStorage->getByUser($user, true);
            $this->logger->info("Found " . count($userContacts) . " contacts by user {$user->getLogin()}");
            foreach ($userContacts as $contact) {
                if (!in_array('NOTIFICATION', $contact->getParams()['severities'])) {
                    $this->logger->info("Contact {$contact->getValue()} was ignored by severities", ['has' => 'notification', 'want' => $contact->getParams()['severities']]);
                    continue;
                }
                if (in_array($data->getAction(), $contact->getParams()['ignore_actions'])) {
                    $this->logger->info("Contact {$contact->getValue()} was ignored by ignore_notifications", ['has' => $data->getAction(), 'ignored' => $contact->getParams()['ignore_notifications']]);
                    continue;
                }
                $contacts[] = $contact;
            }
        }
        return $contacts;
    }

}