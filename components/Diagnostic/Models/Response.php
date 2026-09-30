<?php

namespace WCC\Diagnostic\Models;

use WCAA\Models\Devices\Device;

class Response
{
    protected $statusFlags = [];
    protected ?Device $device = null;
    protected $interface = null;
    protected $type = null;
    protected ?ResponseTypesInterface $ethernet = null;
    protected ?ResponseTypesInterface $ont = null;

    public static function init(Device $device, $interface,  ResponseTypesInterface $interfaceInfo) {
        $obj = new self();
        $obj->device = $device;
        $obj->interface = $interface;
        $obj->type = $device->getModel()->getType();
        switch ($device->getModel()->getType()) {
            case 'SWITCH': $obj->ethernet = $interfaceInfo;  break;
            case 'OLT': $obj->ont = $interfaceInfo; break;
            default:
                throw new \Exception("Invalid device type");
        }
        return $obj;
    }

    /**
     * @return array
     */
    public function getStatusFlags(): array
    {
        return $this->statusFlags;
    }

    /**
     * @param array $statusFlags
     * @return Response
     */
    public function setStatusFlags(array $statusFlags): Response
    {
        $this->statusFlags = $statusFlags;
        return $this;
    }

    public function addStatusFlag($flag) {
        $this->statusFlags[] = $flag;
        return $this;
    }

    /**
     * @return null
     */
    public function getDevice()
    {
        return $this->device;
    }

    /**
     * @param null $device
     * @return Response
     */
    public function setDevice($device)
    {
        $this->device = $device;
        return $this;
    }

    /**
     * @return ResponseTypesInterface|null
     */
    public function getEthernet(): ?ResponseTypesInterface
    {
        return $this->ethernet;
    }

    /**
     * @param ResponseTypesInterface|null $ethernet
     * @return Response
     */
    public function setEthernet(?ResponseTypesInterface $ethernet): Response
    {
        $this->ethernet = $ethernet;
        return $this;
    }

    /**
     * @return ResponseTypesInterface|null
     */
    public function getOnt(): ?ResponseTypesInterface
    {
        return $this->ont;
    }

    /**
     * @param ResponseTypesInterface|null $ont
     * @return Response
     */
    public function setOnt(?ResponseTypesInterface $ont): Response
    {
        $this->ont = $ont;
        return $this;
    }

    /**
     * @return array
     */
    public function getAsArray() {
        return [
            'detailed' => [
                'switch' => $this->ethernet ? $this->ethernet->getAsArray() : null,
                'olt' => $this->ont ? $this->ont->getAsArray() : null,
            ],
            'type' => $this->type,
            'status_flags' => $this->statusFlags,
            'interface' => $this->interface,
            'device' => $this->device->getAsArray(),
        ];
    }

}
