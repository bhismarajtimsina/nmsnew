<?php

namespace WCC\TrapService\Models;

use WCAA\Models\AbstractModel;
use WCAA\Models\Devices\Device;
use WCAA\Models\Devices\DeviceInterface;

class TrapLog extends AbstractModel
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
    protected $created_at;

    /**
     * @morm
     * @var string
     */
    protected $object = '';

    /**
     * @morm
     * @var string
     */
    protected $name = '';

    /**
     * @morm
     * @var string
     */
    protected $description = '';

    /**
     * @morm
     * @morm.type=object
     * @var array | null
     */
    protected $modules;

    /**
     * @var DeviceInterface | null
     */
    protected $interface;

    /**
     * @morm
     * @prop.display=no
     * @var int | null
     */
    protected $interface_id;

    /**
     * @morm
     * @morm.type=object
     * @var array
     */
    protected $parsed;

    /**
     * @morm
     * @var array
     */
    protected $errors;

    /**
     * @morm
     * @morm.type=object
     * @var array
     */
    protected $raw;


    public function __construct($id = null)
    {
        parent::__construct($id);
        $this->created_at = date("Y-m-d H:i:s");
        $this->parsed = [];
        $this->errors = [];
        $this->name = '';
        $this->description = '';
    }

    public function getRaw(): array
    {
        return $this->raw;
    }

    public function setRaw(array $raw): TrapLog
    {
        $this->raw = $raw;
        return $this;
    }





    public function getObject(): string
    {
        return $this->object;
    }

    public function setObject(string $object): TrapLog
    {
        $this->object = $object;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): TrapLog
    {
        $this->name = $name;
        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): TrapLog
    {
        $this->description = $description;
        return $this;
    }


    public function getDevice(): Device
    {
        return $this->device;
    }

    public function setDevice(Device $device): TrapLog
    {
        $this->device = $device;
        return $this;
    }

    public function getDeviceId(): int
    {
        return $this->device_id;
    }

    public function setDeviceId(int $device_id): TrapLog
    {
        $this->device_id = $device_id;
        return $this;
    }

    public function getCreatedAt(): string
    {
        return $this->created_at;
    }

    public function setCreatedAt(string $created_at): TrapLog
    {
        $this->created_at = $created_at;
        return $this;
    }

    public function getModules(): ?array
    {
        return $this->modules;
    }

    public function setModules(?array $modules): TrapLog
    {
        $this->modules = $modules;
        return $this;
    }

    public function getInterface(): ?DeviceInterface
    {
        return $this->interface;
    }

    public function setInterface(DeviceInterface $interface = null): TrapLog
    {
        $this->interface = $interface;
        return $this;
    }

    public function getInterfaceId(): int
    {
        return $this->interface_id;
    }

    public function setInterfaceId(int $interface_id): TrapLog
    {
        $this->interface_id = $interface_id;
        return $this;
    }

    public function getParsed(): array
    {
        return $this->parsed;
    }

    public function setParsed(array $parsed): TrapLog
    {
        $this->parsed = $parsed;
        return $this;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function setErrors(array $errors): TrapLog
    {
        $this->errors = $errors;
        return $this;
    }


}
