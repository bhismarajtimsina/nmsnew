<?php

namespace WCAA\Infrastructure\Poller\Pollers;

use WCAA\Infrastructure\Poller\Interfaces\PollerOntIdentificationInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerOntVendorInfoInterface;
use WCAA\Infrastructure\Poller\PollerAbstract;
use WCAA\Infrastructure\Poller\PollerInterface;
use WCAA\Models\Devices\DeviceInterface;
use WCAA\Models\Pollers\OntIdent;
use WCAA\Models\Devices\Device;
use WCAA\Storage\PollerData\OntIdentStorage;
use WCAA\Storage\Devices\DeviceInterfaceStorage;

class OntVendorInfo extends PollerAbstract implements PollerInterface
{

    /**
     * @var OntIdentStorage
     */
    protected $storage;

    /**
     * @var DeviceInterfaceStorage
     */
    protected $interfaceStorage;

    /**
     * @param OntIdentStorage $storage
     * @param DeviceInterfaceStorage $ifaceStorage
     */
    function __construct(OntIdentStorage $storage, DeviceInterfaceStorage $ifaceStorage)
    {
        $this->storage = $storage;
        $this->interfaceStorage = $ifaceStorage;
    }

    function poll(Device $device, $controller)
    {
        if (!$controller instanceof PollerOntVendorInfoInterface) {
            throw new \Exception("Controller " . get_class($controller) . " not implemented PollerOntVendorInfoInterface");
        }
        $interfaces = $controller->getOntVendorInfo();
        $this->sync($device, $interfaces);
        $this->notifyPolledNow($device, 'ont_vendor_info');
    }

    function setManual(Device $device, $data = [])
    {
        $this->sync($device, $data);
        $this->notifyPolledNow($device, 'ont_vendor_info');
    }

    function setManualByInterface($device, $data = [])
    {
        $this->sync($device, $data);
    }

    function sync(Device $device, $interfaces)
    {
        $currentVendorInfo = [];
        foreach ($interfaces as $iface) {
            $currentVendorInfo[$iface['interface']['id']] = $iface;
        }
        /**
         * @var \WCAA\Infrastructure\Poller\Interfaces\PollerOntVendorInfoInterface[]
         */
        $storageIdents = [];
        if (count($currentVendorInfo) > 1) {
            foreach ($this->storage->getByDevice($device) as $interface) {
                $storageIdents[$interface->getInterface()->getBindKey()] = $interface;
            }
        } elseif (count($currentVendorInfo) === 1) {
            $iface = array_values($currentVendorInfo)[0];
            try {
                $interface = $this->interfaceStorage->getByDeviceAndKey($device, $iface['interface']['id']);
                $ident = $this->storage->getByInterface($interface);
                $storageIdents[$iface['interface']['id']] = $ident;
            } catch (\Exception $e) {
                if ($this->logger) $this->logger->warning("Interface not found in storage for ont ident", ['device' => $device->getAsArray(), 'ident' => $iface]);
            }
        }

        foreach ($currentVendorInfo as $bindKey => $current) {
            if (isset($storageIdents[$bindKey])) {
                $state = $storageIdents[$bindKey];
            } else {
                if ($this->logger) $this->logger->warning("Ident not found by interface", ['device' => $device->getAsArray(), 'ident' => $current]);
                continue;
            }
            unset($current['interface']);
            ksort($current);
            if(json_encode($current) !== json_encode($state->getVendorInfo())) {
                $state->setVendorInfo($current);
                $this->storage->update($state);
            }
        }

    }
}