<?php

namespace WCC\TrapService\Models;

use WCAA\Models\Devices\Device;
use WCAA\Models\Devices\DeviceInterface;
use WCAA\Models\User\User;

class TrapFilter
{
    /**
     * @var Device | null
     */
    public $device;

    /**
     * @var User | null
     */
    public $user;

    /**
     * @var DeviceInterface | null
     */
    public $interface;

    /**
     * @var string | null
     */
    public $object;

    /**
     * @var array | null
     */
    public $names;

    /**
     * @var array | null
     */
    public $timePeriod = [];

    public function __construct()
    {
        $this->timePeriod = [
            date("Y-m-d H:i:s", strtotime("-1 day")),
            date("Y-m-d") . " 23:59:59",
        ];
    }

    public function getDevice(): ?Device
    {
        return $this->device;
    }

    public function setDevice(?Device $device): TrapFilter
    {
        $this->device = $device;
        return $this;
    }

    public function getInterface(): ?DeviceInterface
    {
        return $this->interface;
    }

    public function setInterface(?DeviceInterface $interface): TrapFilter
    {
        $this->interface = $interface;
        return $this;
    }

    public function getObject(): ?string
    {
        return $this->object;
    }

    public function setObject(?string $object): TrapFilter
    {
        $this->object = $object;
        return $this;
    }

    public function getNames(): ?array
    {
        return $this->names;
    }

    public function setNames(?array $names): TrapFilter
    {
        $this->names = $names;
        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): TrapFilter
    {
        $this->user = $user;
        return $this;
    }

    public function getTimePeriod(): ?array
    {
        return $this->timePeriod;
    }

    public function setTimePeriod($start, $end): TrapFilter
    {
        $this->timePeriod = [$start, $end];
        return $this;
    }

}