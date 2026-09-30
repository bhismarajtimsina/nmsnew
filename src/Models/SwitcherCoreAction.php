<?php


namespace WCAA\Models;


use WCAA\Console\AbstractCommand;
use WCAA\Models\Devices\Device;
use WCAA\Models\Devices\DeviceInterface;
use WCAA\Models\User\User;

class SwitcherCoreAction extends AbstractModel
{
    const STATUS_SUCCESS = 'SUCCESS';
    const STATUS_FAILED = 'FAILED';

    /**
     * @morm
     * @var
     */
    protected $time;

    /**
     * @prop.display=no
     * @morm
     * @var
     */
    protected $device_id;

    /**
     * @var Device
     */
    protected $device;

    /**
     * @prop.display=no
     * @morm
     * @var
     */
    protected $user_id;

    /**
     * @var User
     */
    protected $user;

    /**
     * @morm
     * @var string
     */
    protected $status;


    /**
     * @morm
     * @var
     */
    protected $hash;

    /**
     * @morm
     * @var string
     */
    protected $module;

    /**
     * @morm
     * @var array
     */
    protected $arguments;

    /**
     * @morm
     * @var array
     */
    protected $data;

    /**
     * @return array
     */
    public function getMeta(): array
    {
        return $this->meta;
    }


    /**
     * @morm
     * @var array
     */
    protected $meta;

    /**
     * @return mixed
     */
    public function getTime()
    {
        return $this->time;
    }

    /**
     * @param mixed $time
     * @return SwitcherCoreAction
     */
    public function setTime($time)
    {
        $this->time = $time;
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
     * @return SwitcherCoreAction
     */
    public function setDevice(Device $device): SwitcherCoreAction
    {
        $this->device = $device;
        return $this;
    }

    /**
     * @return User
     */
    public function getUser()
    {
        return $this->user;
    }

    /**
     * @param User $user
     * @return SwitcherCoreAction
     */
    public function setUser($user)
    {
        $this->user = $user;
        return $this;
    }

    /**
     * @return string
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * @param string $status
     * @return SwitcherCoreAction
     */
    public function setStatus(string $status): SwitcherCoreAction
    {
        $this->status = $status;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getHash()
    {
        return $this->hash;
    }

    /**
     * @param mixed $hash
     * @return SwitcherCoreAction
     */
    public function setHash($hash)
    {
        $this->hash = $hash;
        return $this;
    }

    /**
     * @return string
     */
    public function getModule(): string
    {
        return $this->module;
    }

    /**
     * @param string $module
     * @return SwitcherCoreAction
     */
    public function setModule(string $module): SwitcherCoreAction
    {
        $this->module = $module;
        return $this;
    }

    /**
     * @return array
     */
    public function getArguments(): array
    {
        return $this->arguments;
    }

    /**
     * @param array $arguments
     * @return SwitcherCoreAction
     */
    public function setArguments(array $arguments): SwitcherCoreAction
    {
        $this->arguments = $arguments;
        return $this;
    }

    /**
     * @return array
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * @param mixed $data
     * @return SwitcherCoreAction
     */
    public function setData($data): SwitcherCoreAction
    {
        $this->data = $data;
        return $this;
    }


    /**
     * @param array $meta
     * @return SwitcherCoreAction
     */
    public function setMeta($meta): SwitcherCoreAction
    {
        $this->meta = $meta;
        return $this;
    }


    public function __construct() {
        $this->hash = "<NOT SETTED>";
        $this->time = date("Y-m-d H:i:s");
        $this->arguments = [];
        $this->data = [];
    }
}