<?php

namespace WCAA\Models\Devices;

use WCAA\Models\AbstractModel;
use WCAA\Models\User\User;

class DeviceGroup extends AbstractModel
{
    /**
     * @morm
     * @prop.display=root
     * @var string
     */
    protected $created_at;

    /**
     * @morm
     * @var string
     */
    protected $name;
    /**
     * @morm
     * @prop.display=root
     * @var string
     */
    protected $description;

    /**
     * @return string
     */
    public function getCreatedAt(): string
    {
        return $this->created_at;
    }

    /**
     * @param string $created_at
     * @return DeviceGroup
     */
    public function setCreatedAt(string $created_at): DeviceGroup
    {
        $this->created_at = $created_at;
        return $this;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param string $name
     * @return DeviceGroup
     */
    public function setName(string $name): DeviceGroup
    {
        $this->name = $name;
        return $this;
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * @param string $description
     * @return DeviceGroup
     */
    public function setDescription(string $description): DeviceGroup
    {
        $this->description = $description;
        return $this;
    }

    function __construct($id = null)
    {
        $this->created_at = date("Y-m-d H:i:s");
        parent::__construct($id);
    }
}


