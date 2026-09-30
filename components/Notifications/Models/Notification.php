<?php

namespace WCC\Notifications\Models;

use WCAA\Models\AbstractModel;
use WCAA\Models\Devices\Device;
use WCAA\Models\SystemAction;
use WCAA\Models\User\User;
use WCC\Events\Models\Event;

class Notification extends AbstractModel
{


    const STATUS_FAILED = 'failed';
    const STATUS_SENT = 'sent';
    const STATUS_CANCELED = 'canceled';

    const STATUS_IN_PROCESS = 'in_process';

    function __construct($id = null)
    {
        parent::__construct($id);
        $this->created_at = date("Y-m-d H:i:s");
        $this->send_at = date("Y-m-d H:i:s");
    }

    /**
     * @morm
     * @var string
     */
    protected $created_at;

    /**
     * @morm
     * @prop.display=no
     * @var int
     */
    protected $contact_id;

    /**
     * @var NotificationContact
     */
    protected $contact;

    /**
     * @morm
     * @prop.display=no
     * @var int
     */
    protected $event_id;

    /**
     * @var Event | null
     */
    protected $event;

    /**
     * @morm
     * @prop.display=no
     * @var int
     */
    protected $action_id;

    /**
     * @var SystemAction | null
     */
    protected $action;

    /**
     * @morm
     * @var string | null
     */
    protected $send_at;

    /**
     * @morm
     * @var string | null
     */
    protected $sent_at;


    /**
     * @morm
     * @prop.display=no
     * @var int
     */
    protected $previous_notification_id;

    /**
     * @var Notification | null
     */
    protected $previous_notification;


    /**
     * @morm
     * @var string | null
     */
    protected $status;

    /**
     * @morm
     * @var array | null
     */
    protected $meta;

    /**
     * @morm
     * @var string
     */
    protected $type;

    /**
     * @return SystemAction|null
     */
    public function getAction(): ?SystemAction
    {
        return $this->action;
    }

    /**
     * @param SystemAction|null $action
     * @return Notification
     */
    public function setAction(?SystemAction $action): Notification
    {
        $this->action = $action;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getSendAt(): ?string
    {
        return $this->send_at;
    }

    /**
     * @param string|null $send_at
     * @return Notification
     */
    public function setSendAt(?string $send_at): Notification
    {
        $this->send_at = $send_at;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getSentAt(): ?string
    {
        return $this->sent_at;
    }

    /**
     * @param string|null $sent_at
     * @return Notification
     */
    public function setSentAt(?string $sent_at): Notification
    {
        $this->sent_at = $sent_at;
        return $this;
    }



    /**
     * @return string
     */
    public function getCreatedAt()
    {
        return $this->created_at;
    }

    /**
     * @param string $created_at
     * @return Notification
     */
    public function setCreatedAt($created_at)
    {
        $this->created_at = $created_at;
        return $this;
    }

    /**
     * @return NotificationContact
     */
    public function getContact(): NotificationContact
    {
        return $this->contact;
    }

    /**
     * @param NotificationContact $contact
     * @return Notification
     */
    public function setContact(NotificationContact $contact): Notification
    {
        $this->contact = $contact;
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
     * @return Notification
     */
    public function setEvent(?Event $event): Notification
    {
        $this->event = $event;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getStatus(): ?string
    {
        return $this->status;
    }

    /**
     * @param string|null $status
     * @return Notification
     */
    public function setStatus(?string $status): Notification
    {
        $this->status = $status;
        return $this;
    }



    /**
     * @return array|null
     */
    public function getMeta(): ?array
    {
        return $this->meta;
    }

    /**
     * @param array|null $meta
     * @return Notification
     */
    public function setMeta(?array $meta): Notification
    {
        $this->meta = $meta;
        return $this;
    }

    /**
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @param string $type
     * @return Notification
     */
    public function setType(string $type): Notification
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
     * @return Notification
     */
    public function setPreviousnotification(?Notification $previous_notification): Notification
    {
        $this->previous_notification = $previous_notification;
        return $this;
    }

}
