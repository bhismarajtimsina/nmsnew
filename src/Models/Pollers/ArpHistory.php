<?php


namespace WCAA\Models\Pollers;


use WCAA\App;
use WCAA\Models\AbstractModel;
use WCAA\Models\Devices\Device;
use WCAA\Models\Devices\DeviceInterface;

class ArpHistory extends AbstractModel
{

    const APR_DINAMIC_UNKNOWN = 'UNKNOWN';
    const APR_DINAMIC_YES = 'YES';
    const APR_DINAMIC_NO = 'NO';

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
     * @var string
     */
    protected $mac;

    /**
     * @morm
     * @var string
     */
    protected $ip;

    /**
     * @morm
     * @var string
     */
    protected $interface;

    /**
     * @morm
     * @var string
     */
    protected $dynamic;

    /**
     * @morm
     * @var string
     */
    protected $status;

    /**
     * @morm
     * @var int | null
     */
    protected $vlan_id;

    /**
     * @morm
     * @var string
     */
    protected $start_at;

    /**
     * @morm
     * @var string | null
     */
    protected $stop_at;

    /**
     * @morm
     * @var string | null
     */
    protected $comment;

    function __construct($id = null)
    {
        parent::__construct($id);
        $this->start_at = date("Y-m-d H:i:s");
        $this->dynamic = self::APR_DINAMIC_UNKNOWN;
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
     * @return ArpHistory
     */
    public function setDevice(Device $device): ArpHistory
    {
        $this->device = $device;
        return $this;
    }

    /**
     * @return string
     */
    public function getMac(): string
    {
        return $this->mac;
    }

    /**
     * @param string $mac
     * @return ArpHistory
     */
    public function setMac(string $mac): ArpHistory
    {
        $this->mac = $mac;
        return $this;
    }

    /**
     * @return string
     */
    public function getIp(): string
    {
        return $this->ip;
    }

    /**
     * @param string $ip
     * @return ArpHistory
     */
    public function setIp(string $ip): ArpHistory
    {
        $this->ip = $ip;
        return $this;
    }

    /**
     * @return string
     */
    public function getInterface(): string
    {
        return $this->interface;
    }

    /**
     * @param string $interface
     * @return ArpHistory
     */
    public function setInterface(string $interface): ArpHistory
    {
        $this->interface = $interface;
        return $this;
    }

    /**
     * @return string
     */
    public function getDynamic(): string
    {
        return $this->dynamic;
    }

    /**
     * @param string $dynamic
     * @return ArpHistory
     */
    public function setDynamic(string $dynamic): ArpHistory
    {
        $this->dynamic = $dynamic;
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
     * @return ArpHistory
     */
    public function setStatus(string $status): ArpHistory
    {
        $this->status = $status;
        return $this;
    }

    /**
     * @return int|null
     */
    public function getVlanId(): ?int
    {
        return $this->vlan_id;
    }

    /**
     * @param int|null $vlan_id
     * @return ArpHistory
     */
    public function setVlanId(?int $vlan_id): ArpHistory
    {
        $this->vlan_id = $vlan_id;
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
     * @return ArpHistory
     */
    public function setStartAt($start_at)
    {
        $this->start_at = $start_at;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getStopAt(): ?string
    {
        return $this->stop_at;
    }

    /**
     * @param string|null $stop_at
     * @return ArpHistory
     */
    public function setStopAt(?string $stop_at): ArpHistory
    {
        $this->stop_at = $stop_at;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getComment(): ?string
    {
        return $this->comment;
    }

    /**
     * @param string|null $comment
     * @return ArpHistory
     */
    public function setComment(?string $comment): ArpHistory
    {
        $this->comment = $comment;
        return $this;
    }



}
