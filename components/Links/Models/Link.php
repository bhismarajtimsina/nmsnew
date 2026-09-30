<?php

namespace WCC\Links\Models;

use WCAA\Models\AbstractModel;
use WCAA\Models\Devices\Device;
use WCAA\Models\Devices\DeviceInterface;

class Link extends AbstractModel
{
    /**
     * @morm
     * @prop.lite
     * @var string
     */
    protected $created_at;

    /**
     * @prop.lite=1
     * @morm
     * @var string
     */
    protected $updated_at;

    /**
     * @morm
     * @prop.lite
     * @prop.display=no
     * @var
     */
    protected $src_device_id;

    /**
     * @prop.lite
     * @var Device
     */
    protected $src_device;

    /**
     * @prop.display=no
     * @morm
     * @var int | null
     */
    protected $src_iface_id;

    /**
     * @var DeviceInterface | null
     */
    protected $src_iface;

    /**
     * @morm
     * @prop.display=no
     * @var
     */
    protected $dest_device_id;

    /**
     * @var Device
     */
    protected $dest_device;
    /**
     * @morm
     * @prop.display=no
     * @var
     */
    protected $dest_iface_id;

    /**
     * @var DeviceInterface | null
     */
    protected $dest_iface;

    /**
     * @morm
     * @var string
     */
    protected $source;


    /**
     * @morm
     * @var ?array
     */
    protected $params;

    public function getParams(): ?array
    {
        return $this->params;
    }

    public function setParams(?array $params): Link
    {
        $this->params = $params;
        return $this;
    }




    function __construct($id = null)
    {
        $this->created_at = date("Y-m-d H:i:s");
        $this->updated_at = date("Y-m-d H:i:s");
        $this->source = 'manual';
        parent::__construct($id);
    }

    /**
     * @return string
     */
    public function getCreatedAt(): string
    {
        return $this->created_at;
    }

    /**
     * @param string $created_at
     * @return Link
     */
    public function setCreatedAt(string $created_at): Link
    {
        $this->created_at = $created_at;
        return $this;
    }

    /**
     * @return string
     */
    public function getUpdatedAt(): string
    {
        return $this->updated_at;
    }

    /**
     * @param string $updated_at
     * @return Link
     */
    public function setUpdatedAt(string $updated_at): Link
    {
        $this->updated_at = $updated_at;
        return $this;
    }



    /**
     * @return Device
     */
    public function getDestDevice(): Device
    {
        return $this->dest_device;
    }

    /**
     * @param Device $dest_device
     * @return Link
     */
    public function setDestDevice(Device $dest_device): Link
    {
        $this->dest_device = $dest_device;
        return $this;
    }

    /**
     * @return DeviceInterface|null
     */
    public function getDestIface(): ?DeviceInterface
    {
        return $this->dest_iface;
    }

    /**
     * @param DeviceInterface|null $dest_iface
     * @return Link
     */
    public function setDestIface(?DeviceInterface $dest_iface): Link
    {
        $this->dest_iface = $dest_iface;
        return $this;
    }



    /**
     * @return string
     */
    public function getSource(): string
    {
        return $this->source;
    }

    /**
     * @param string $source
     * @return Link
     */
    public function setSource(string $source): Link
    {
        $this->source = $source;
        return $this;
    }

    /**
     * @return Device
     */
    public function getSrcDevice(): Device
    {
        return $this->src_device;
    }

    /**
     * @param Device $src_device
     * @return Link
     */
    public function setSrcDevice(Device $src_device): Link
    {
        $this->src_device = $src_device;
        return $this;
    }

    /**
     * @return DeviceInterface|null
     */
    public function getSrcIface(): ?DeviceInterface
    {
        return $this->src_iface;
    }

    /**
     * @param DeviceInterface|null $src_iface
     * @return Link
     */
    public function setSrcIface(?DeviceInterface $src_iface): Link
    {
        $this->src_iface = $src_iface;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getSrcDeviceId()
    {
        return $this->src_device_id;
    }

    /**
     * @param mixed $src_device_id
     * @return Link
     */
    public function setSrcDeviceId($src_device_id)
    {
        $this->src_device_id = $src_device_id;
        return $this;
    }

    public function getSrcIfaceId(): ?int
    {
        return $this->src_iface_id;
    }

    public function setSrcIfaceId(?int $src_iface_id): Link
    {
        $this->src_iface_id = $src_iface_id;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getDestDeviceId()
    {
        return $this->dest_device_id;
    }

    /**
     * @param mixed $dest_device_id
     * @return Link
     */
    public function setDestDeviceId($dest_device_id)
    {
        $this->dest_device_id = $dest_device_id;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getDestIfaceId()
    {
        return $this->dest_iface_id;
    }

    /**
     * @param mixed $dest_iface_id
     * @return Link
     */
    public function setDestIfaceId($dest_iface_id)
    {
        $this->dest_iface_id = $dest_iface_id;
        return $this;
    }




}