<?php

namespace WCC\Notifications\Models;

use WCAA\Models\SystemAction;
use WCAA\Models\User\User;
use WCAA\Storage\SystemActionsStorage;
use WCC\Events\Models\Event;

class NotificationFilter
{
    /**
     * @var NotificationContact | null
     */
    protected $alertContact;

    /**
     * @var string | null
     */
    protected $channel;

    /**
     * @var Event | null
     */
    protected $event;

    /**
     * @var SystemAction | null
     */
    protected $action;

    /**
     * @var string | null
     */
    protected $type;

    /**
     * @var Notification | null
     */
    protected $previous_notification;

    /**
     * @var User | null
     */
    protected $user;


    /**
     * @return NotificationContact|null
     */
    public function getAlertContact(): ?NotificationContact
    {
        return $this->alertContact;
    }

    /**
     * @param NotificationContact|null $alertContact
     * @return NotificationFilter
     */
    public function setAlertContact(?NotificationContact $alertContact): NotificationFilter
    {
        $this->alertContact = $alertContact;
        return $this;
    }

    /**
     * @return Event|null
     */
    public function getEvent(): ?Event
    {
        return $this->event;
    }

    /**
     * @param Event|null $event
     * @return NotificationFilter
     */
    public function setEvent(?Event $event): NotificationFilter
    {
        $this->event = $event;
        return $this;
    }


    /**
     * @return string|null
     */
    public function getType(): ?string
    {
        return $this->type;
    }

    /**
     * @param string|null $type
     * @return NotificationFilter
     */
    public function setType(?string $type): NotificationFilter
    {
        $this->type = $type;
        return $this;
    }

    /**
     * @return Notification|null
     */
    public function getPreviousnotification(): ?Notification
    {
        return $this->previous_notification;
    }

    /**
     * @param Notification|null $previous_notification
     * @return NotificationFilter
     */
    public function setPreviousnotification(?Notification $previous_notification): NotificationFilter
    {
        $this->previous_notification = $previous_notification;
        return $this;
    }

    /**
     * @return SystemAction|null
     */
    public function getAction(): ?SystemAction
    {
        return $this->action;
    }

    /**
     * @param SystemAction|null $action
     * @return NotificationFilter
     */
    public function setAction(?SystemAction $action): NotificationFilter
    {
        $this->action = $action;
        return $this;
    }

    public function setUser(User $user): NotificationFilter
    {
        $this->user = $user;
        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }



    public function getChannel(): ?string
    {
        return $this->channel;
    }

    public function setChannel(?string $channel): NotificationFilter
    {
        $this->channel = $channel;
        return $this;
    }


}