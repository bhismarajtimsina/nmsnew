<?php


namespace WCAA\Models\Pollers;


use WCAA\App;
use WCAA\Models\AbstractModel;
use WCAA\Models\Devices\Device;
use WCAA\Models\Devices\DeviceInterface;

class FdbHistory extends AbstractModel
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
     * @prop.display=no
     * @var int
     */
    protected $interface_id;

    /**
     * @var DeviceInterface
     */
    protected $interface;

    /**
     * @morm
     * @var int
     */
    protected $vlan_id;

    /**
     * @morm
     * @var string
     */
    protected $mac_address;

    /**
     * @morm
     * @var string
     */
    protected $start_at;

    /**
     * @morm
     * @var string
     */
    protected $stop_at;

    function __construct($id = null)
    {
        parent::__construct($id);
        $this->start_at = date("Y-m-d H:i:s");
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
     * @return FdbHistory
     */
    public function setDeviceId(int $device_id): FdbHistory
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
     * @return FdbHistory
     */
    public function setDevice(Device $device): FdbHistory
    {
        $this->device = $device;
        return $this;
    }

    /**
     * @return int
     */
    public function getInterfaceId(): int
    {
        return $this->interface_id;
    }

    /**
     * @param int $interface_id
     * @return FdbHistory
     */
    public function setInterfaceId(int $interface_id): FdbHistory
    {
        $this->interface_id = $interface_id;
        return $this;
    }

    /**
     * @return DeviceInterface
     */
    public function getInterface(): DeviceInterface
    {
        return $this->interface;
    }

    /**
     * @param DeviceInterface $interface
     * @return FdbHistory
     */
    public function setInterface(DeviceInterface $interface): FdbHistory
    {
        $this->interface = $interface;
        return $this;
    }

    /**
     * @return int
     */
    public function getVlanId(): int
    {
        return $this->vlan_id;
    }

    /**
     * @param int $vlan_id
     * @return FdbHistory
     */
    public function setVlanId(int $vlan_id): FdbHistory
    {
        $this->vlan_id = $vlan_id;
        return $this;
    }

    /**
     * @return string
     */
    public function getMacAddress(): string
    {
        return $this->mac_address;
    }

    /**
     * @param string $mac_address
     * @return FdbHistory
     */
    public function setMacAddress(string $mac_address): FdbHistory
    {
        $this->mac_address = $mac_address;
        return $this;
    }

    /**
     * @return string
     */
    public function getStartAt()
    {
        return $this->start_at;
    }

    /**
     * @param string $start_at
     * @return FdbHistory
     */
    public function setStartAt($start_at)
    {
        $this->start_at = $start_at;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getStopAt()
    {
        return $this->stop_at;
    }

    /**
     * @param string|null $stop_at
     * @return FdbHistory
     */
    public function setStopAt($stop_at): FdbHistory
    {
        $this->stop_at = $stop_at;
        return $this;
    }


}