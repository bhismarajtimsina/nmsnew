<?php

namespace WCC\Notifications\Controllers\NotificationGenerators;

use WCAA\Models\SystemAction;
use WCC\Events\Models\Event;
use WCC\Notifications\Models\Notification;
use WCC\Notifications\Models\NotificationContact;
use WCC\Notifications\Storage\NotificationEventConfigStorage;

class EventGenerator extends AbstractNotificationGenerator
{

    /**
     * @Inject
     * @var NotificationEventConfigStorage
     */
    protected $eventConfigStorage;

    /**
     * @param Event $data
     * @return false|void
     * @throws \Exception
     */
    function process($data)
    {
        if(!($data instanceof Event)) {
            throw new \Exception("Event generator working only with Event object");
        }
        $cfg = $this->eventConfigStorage->searchByName($data->getName());
        if (!$cfg) {
            $this->logger->info("Event with name {$data->getName()} not configure for sending, ignoring...");
            return false;
        }
        if (!$cfg->isEnabled()) {
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

        if ($data->getResolvedAt() && !$cfg->isSendResolved()) {
            $this->logger->info("Event with name {$data->getName()} with status resolved, ignoring...");
            return false;
        }
        if($data->getResolvedAt()) {
            $sendAt = date('Y-m-d H:i:s');
        } else {
            $sendAt = date("Y-m-d H:i:s", time() + $cfg->getDelayBeforeSend());
        }
        $contacts = $this->getContacts($data);

        foreach ($contacts as $contact) {
            if($contact->getType() == NotificationContact::TYPE_PHONE_FOR_TELEGRAM) continue;
            if($contact->getType() == NotificationContact::TYPE_PHONE) continue;

            $notification = (new Notification())
                ->setEvent($data)
                ->setContact($contact)
                ->setSendAt($sendAt)
                ->setType($data->getResolvedAt() ? 'resolved' : 'alert');
            if ($data->getResolvedAt()) {
                $previous = $this->searchPreviousByNotification($notification);
                $notification->setPreviousnotification($previous);

                if($previous) {
                    $prevSendAt = \DateTime::createFromFormat("Y-m-d H:i:s", $previous->getSendAt())->getTimestamp() + 10;
                    $notification->setSendAt(date("Y-m-d H:i:s", $prevSendAt));
                }
            }
            $this->notificationsStorage->add($notification);
        }
    }

    /**
     * @param Event|SystemAction $data
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
                if (!in_array($data->getSeverity(), $contact->getParams()['severities'])) {
                    $this->logger->info("Contact {$contact->getValue()} was ignored by severities", ['has' => $data->getSeverity(), 'want' => $contact->getParams()['severities']]);
                    continue;
                }
                if (in_array($data->getName(), $contact->getParams()['ignore_events'])) {
                    $this->logger->info("Contact {$contact->getValue()} was ignored by ignore_notifications", ['has' => $data->getName(), 'ignored' => $contact->getParams()['ignore_notifications']]);
                    continue;
                }
                $contacts[] = $contact;
            }
        }
        return $contacts;
    }
}