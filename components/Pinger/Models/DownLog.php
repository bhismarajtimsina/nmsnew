<?php

namespace WCC\Pinger\Models;

use WCAA\Models\AbstractModel;
use WCAA\Models\Devices\Device;

class DownLog extends AbstractModel
{
    /**
     * @var Device
     */
    protected $device;

    /**
     * @morm
     * @prop.display=no
     * @var int
     */
    protected $device_id;

    /**
     * @morm
     * @var string
     */
    protected $start;

    /**
     * @morm
     * @var string
     */
    protected $stop;

    /**
     * @return Device
     */
    public function getDevice(): Device
    {
        return $this->device;
    }

    /**
     * @param Device $device
     * @return DownLog
     */
    public function setDevice(Device $device): DownLog
    {
        $this->device = $device;
        return $this;
    }

    /**
     * @return int
     */
    public function getDeviceId(): int
    {
        return $this->device_id;
    }

    /**
     * @param int $device_id
     * @return DownLog
     */
    public function setDeviceId(int $device_id): DownLog
    {
        $this->device_id = $device_id;
        return $this;
    }

    /**
     * @return string
     */
    public function getStart(): string
    {
        return $this->start;
    }

    /**
     * @param string $start
     * @return DownLog
     */
    public function setStart(string $start): DownLog
    {
        $this->start = $start;
        return $this;
    }

    /**
     * @return string
     */
    public function getStop(): string
    {
        return $this->stop;
    }

    /**
     * @param string $stop
     * @return DownLog
     */
    public function setStop(string $stop): DownLog
    {
        $this->stop = $stop;
        return $this;
    }


}
