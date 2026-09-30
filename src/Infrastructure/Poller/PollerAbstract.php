<?php

namespace WCAA\Infrastructure\Poller;

use Monolog\Logger;
use WCAA\Infrastructure\Events\EventObserverStorage;
use WCAA\Models\Devices\Device;
use WCAA\Models\Devices\DeviceInterface;
use WCAA\Models\Pollers\PollerProcessing;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\PollerData\PollerProcessingStorage;

class PollerAbstract
{
    /**
     * @Inject
     * @var Logger
     */
    protected $logger;

    /**
     * @Inject
     * @var PollerProcessingStorage
     */
    protected $pollerStorage;

    /**
     * @Inject
     * @var EventObserverStorage
     */
    protected $events;


    /**
     * @Inject
     * @var DeviceInterfaceStorage
     */
    protected $interfaceStorage;


    protected $_allowedIfaces = [];
    function getAllowedInterfacesByDevice(Device $device) {
        if(isset($this->_allowedIfaces[$device->getId()])) {
            return  $this->_allowedIfaces[$device->getId()];
        }
        $allowed = [];
        foreach ($this->interfaceStorage->getByDevice($device, null, false) as $iface) {
            if($iface->isPollEnabled()) {
                $allowed[] = $iface->getBindKey();
            }
        }
        $this->_allowedIfaces[$device->getId()] = $allowed;
        return $allowed;
    }

    function setManual(Device $device, $data = []) {
        throw new \Exception("Set data by device to poller not implemented");
    }

    /**
     * Method waiting array with
     *
     * @param Device $device
     * @param mixed $data
     * @return mixed
     * @throws \Exception
     */
    function setManualByInterface(Device $device, $data)
    {
        throw new \Exception("Set data by interface to poller not implemented");
    }

    protected function notifyPolledNow(Device $device, $pollerName) {
        $collectData = $this->pollerStorage->addOrUpdate(
            (new PollerProcessing())
                ->setDevice($device)
                ->setStartAt(date("Y-m-d H:i:s"))
                ->setStopAt(date("Y-m-d H:i:s"))
                ->setStatus(PollerProcessing::STATUS_SUCCESS)
                ->setPoller($pollerName)
        );
        $this->events->notify('poller:finished', [
            'name' => $pollerName,
            'device' => $device->getAsArrayLite(),
            'status' => 'success',
            'worked_time' => 0,
            'id' => $collectData->getId(),
        ]);
        return true;
    }

}