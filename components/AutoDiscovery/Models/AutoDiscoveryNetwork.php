<?php

namespace WCC\AutoDiscovery\Models;

use WCAA\Models\AbstractModel;
use WCAA\Models\Devices\Device;
use WCAA\Models\Devices\DeviceAccess;
use WCAA\Models\Devices\DeviceGroup;
use WCAA\Models\User\User;
use WCAA\Storage\Devices\DeviceGroupStorage;
use WCC\Events\Models\Event;

class AutoDiscoveryNetwork extends AbstractModel
{

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
     * @prop.display=no
     * @var int
     */
    protected $device_group_id;

    /**
     * @var DeviceGroup
     */
    protected $device_group;

    /**
     * @morm
     * @prop.display=no
     * @var int
     */
    protected $access_id;

    /**
     * @var DeviceAccess
     */
    protected $device_access;

    /**
     * @morm
     * @var string
     */
    protected $cidr;

    /**
     * @morm
     * @var string
     */
    protected $last_scan;

    /**
     * @return string
     */
    public function getCreatedAt()
    {
        return $this->created_at;
    }

    /**
     * @param string $created_at
     * @return AutoDiscoveryNetwork
     */
    public function setCreatedAt($created_at)
    {
        $this->created_at = $created_at;
        return $this;
    }

    /**
     * @return DeviceGroup
     */
    public function getDeviceGroup(): DeviceGroup
    {
        return $this->device_group;
    }

    /**
     * @param DeviceGroup $device_group
     * @return AutoDiscoveryNetwork
     */
    public function setDeviceGroup(DeviceGroup $device_group): AutoDiscoveryNetwork
    {
        $this->device_group = $device_group;
        return $this;
    }

    /**
     * @return DeviceAccess
     */
    public function getDeviceAccess(): DeviceAccess
    {
        return $this->device_access;
    }

    /**
     * @param DeviceAccess $device_access
     * @return AutoDiscoveryNetwork
     */
    public function setDeviceAccess(DeviceAccess $device_access): AutoDiscoveryNetwork
    {
        $this->device_access = $device_access;
        return $this;
    }

    /**
     * @return string
     */
    public function getCidr(): string
    {
        return $this->cidr;
    }

    /**
     * @param string $cidr
     * @return AutoDiscoveryNetwork
     */
    public function setCidr(string $cidr): AutoDiscoveryNetwork
    {
        $this->cidr = $cidr;
        return $this;
    }

    /**
     * @return string
     */
    public function getLastScan(): string
    {
        return $this->last_scan;
    }

    /**
     * @param string $last_scan
     * @return AutoDiscoveryNetwork
     */
    public function setLastScan(string $last_scan): AutoDiscoveryNetwork
    {
        $this->last_scan = $last_scan;
        return $this;
    }



}
