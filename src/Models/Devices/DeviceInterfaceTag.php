<?php

namespace WCAA\Models\Devices;

use WCAA\Models\AbstractModel;

class DeviceInterfaceTag extends AbstractModel
{
    /**
     * Allowed types
     * STARRED, TAG
     * @morm
     * @morm string
     */
    protected $type;

    /**
     * @var string|null
     * @morm
     */
    protected $value;

    /**
     * @prop.display=no
     * @morm
     * @var int
     */
    protected $interface_id;

    /**
     * @var DeviceInterface
     */
    protected $interface;

    /**
     * @return mixed
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * @param mixed $type
     * @return DeviceInterfaceTag
     */
    public function setType($type)
    {
        $this->type = $type;
        return $this;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function setValue(?string $value): DeviceInterfaceTag
    {
        $this->value = $value;
        return $this;
    }

    public function getInterface(): DeviceInterface
    {
        return $this->interface;
    }

    public function setInterface(DeviceInterface $interface): DeviceInterfaceTag
    {
        $this->interface = $interface;
        return $this;
    }

    public function isTag() {
        return $this->type === 'TAG';
    }

    public function isStarred() {
        return $this->type === 'STARRED';
    }
}