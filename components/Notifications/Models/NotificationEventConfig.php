<?php

namespace WCC\Notifications\Models;

use WCAA\Models\AbstractModel;
use WCAA\Models\Devices\Device;
use WCAA\Models\User\User;
use WCC\Events\Models\Event;

class NotificationEventConfig extends AbstractModel
{

    /**
     * @var Device[] | null
     */
    protected $ignored_devices;

    function __construct($id = null)
    {
        parent::__construct($id);
        $this->created_at = date("Y-m-d H:i:s");
    }

    /**
     * @morm
     * @var string
     */
    protected $created_at;

    /**
     * @morm
     * @var string
     */
    protected $event_name;

    /**
     * @morm
     * @var bool
     */
    protected $check_uplink;



    /**
     * @morm
     * @var int
     */
    protected $delay_before_send;

    /**
     * @morm
     * @var bool
     */
    protected $send_resolved;


    /**
     * @morm
     * @var bool
     */
    protected $enabled;

    /**
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @param bool $enabled
     * @return NotificationEventConfig
     */
    public function setEnabled(bool $enabled): NotificationEventConfig
    {
        $this->enabled = $enabled;
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
     * @return NotificationEventConfig
     */
    public function setCreatedAt($created_at)
    {
        $this->created_at = $created_at;
        return $this;
    }

    /**
     * @return string
     */
    public function getEventName(): string
    {
        return $this->event_name;
    }

    /**
     * @param string $event_name
     * @return NotificationEventConfig
     */
    public function setEventName(string $event_name): NotificationEventConfig
    {
        $this->event_name = $event_name;
        return $this;
    }

    /**
     * @return int
     */
    public function getDelayBeforeSend(): int
    {
        return $this->delay_before_send;
    }

    /**
     * @param int $delay_before_send
     * @return NotificationEventConfig
     */
    public function setDelayBeforeSend(int $delay_before_send): NotificationEventConfig
    {
        $this->delay_before_send = $delay_before_send;
        return $this;
    }

    /**
     * @return bool
     */
    public function isSendResolved(): bool
    {
        return $this->send_resolved;
    }

    /**
     * @param bool $send_resolved
     * @return NotificationEventConfig
     */
    public function setSendResolved(bool $send_resolved): NotificationEventConfig
    {
        $this->send_resolved = $send_resolved;
        return $this;
    }

    /**
     * @return Device[]|null
     */
    public function getIgnoredDevices(): ?array
    {
        return $this->ignored_devices;
    }

    /**
     * @param Device[]|null $ignored_devices
     * @return NotificationEventConfig
     */
    public function setIgnoredDevices(?array $ignored_devices): NotificationEventConfig
    {
        $this->ignored_devices = $ignored_devices;
        return $this;
    }

    /**
     * @return bool
     */
    public function isCheckUplink(): bool
    {
        return $this->check_uplink;
    }

    /**
     * @param bool $check_uplink
     * @return NotificationEventConfig
     */
    public function setCheckUplink(bool $check_uplink): NotificationEventConfig
    {
        $this->check_uplink = $check_uplink;
        return $this;
    }



}
