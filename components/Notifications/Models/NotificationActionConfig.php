<?php

namespace WCC\Notifications\Models;

use WCAA\Models\AbstractModel;
use WCAA\Models\Devices\Device;
use WCAA\Models\User\User;
use WCC\Events\Models\Event;

class NotificationActionConfig extends AbstractModel
{

    function __construct($id = null)
    {
        parent::__construct($id);
        $this->created_at = date("Y-m-d H:i:s");
    }


    /**
     * @var Device[] | null
     */
    protected $ignored_devices;

    /**
     * @morm
     * @var string
     */
    protected $created_at;

    /**
     * @morm
     * @var string
     */
    protected $action_name;

    /**
     * @morm
     * @var bool
     */
    protected $send_on_status_failed;

    /**
     * @morm
     * @var bool
     */
    protected $send_on_status_success;


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
     * @return NotificationActionConfig
     */
    public function setEnabled(bool $enabled): NotificationActionConfig
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
     * @return NotificationActionConfig
     */
    public function setCreatedAt($created_at)
    {
        $this->created_at = $created_at;
        return $this;
    }

    /**
     * @return string
     */
    public function getActionName(): string
    {
        return $this->action_name;
    }

    /**
     * @param string $action_name
     * @return NotificationActionConfig
     */
    public function setActionName(string $action_name): NotificationActionConfig
    {
        $this->action_name = $action_name;
        return $this;
    }

    /**
     * @return bool
     */
    public function isSendOnStatusFailed(): bool
    {
        return $this->send_on_status_failed;
    }

    /**
     * @param bool $send_on_status_failed
     * @return NotificationActionConfig
     */
    public function setSendOnStatusFailed(bool $send_on_status_failed): NotificationActionConfig
    {
        $this->send_on_status_failed = $send_on_status_failed;
        return $this;
    }

    /**
     * @return bool
     */
    public function isSendOnStatusSuccess(): bool
    {
        return $this->send_on_status_success;
    }

    /**
     * @param bool $send_on_status_success
     * @return NotificationActionConfig
     */
    public function setSendOnStatusSuccess(bool $send_on_status_success): NotificationActionConfig
    {
        $this->send_on_status_success = $send_on_status_success;
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
     * @return NotificationActionConfig
     */
    public function setIgnoredDevices(?array $ignored_devices): NotificationActionConfig
    {
        $this->ignored_devices = $ignored_devices;
        return $this;
    }



}
