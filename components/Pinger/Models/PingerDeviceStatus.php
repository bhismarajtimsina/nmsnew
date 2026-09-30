<?php

namespace WCC\Pinger\Models;

use WCAA\Models\AbstractModel;
use WCAA\Models\Devices\Device;

class PingerDeviceStatus extends AbstractModel
{
    /**
     * @morm
     * @prop.display=no
     * @var int
     */
    protected $device_id;

    /**
     * @var Device
     */
    protected $device;

    /**
     * @morm
     * @var int
     */
    protected $latency;

    /**
     * @morm
     * @var string
     */
    protected $last_change;

    /**
     * @return int
     */
    public function getDeviceId(): int
    {
        return $this->device_id;
    }

    /**
     * @param int $device_id
     * @return PingerDeviceStatus
     */
    public function setDeviceId(int $device_id): PingerDeviceStatus
    {
        $this->device_id = $device_id;
        return $this;
    }

    /**
     * @return Device
     */
    public function getDevice(): Device
    {
        return $this->device;
    }

    /**
     * @param Device $device
     * @return PingerDeviceStatus
     */
    public function setDevice(Device $device): PingerDeviceStatus
    {
        $this->device = $device;
        return $this;
    }

    /**
     * @return int
     */
    public function getLatency(): int
    {
        return $this->latency;
    }

    /**
     * @param int $latency
     * @return PingerDeviceStatus
     */
    public function setLatency(int $latency): PingerDeviceStatus
    {
        $this->latency = $latency;
        return $this;
    }

    /**
     * @return string
     */
    public function getLastChange(): string
    {
        return $this->last_change;
    }

    /**
     * @param string $last_change
     * @return PingerDeviceStatus
     */
    public function setLastChange(string $last_change): PingerDeviceStatus
    {
        $this->last_change = $last_change;
        return $this;
    }

    public function isUp() {
        return $this->latency > 0;
    }
    public function isNoICMP() {
        return $this->latency == 999;
    }

    function __construct($id = null)
    {
        $this->last_change = date("Y-m-d H:i:s");
        parent::__construct($id);
    }
}
