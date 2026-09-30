<?php

namespace WCAA\Infrastructure\Poller\Pollers;

use WCAA\App;
use WCAA\Infrastructure\Poller\Interfaces\PollerOntIdentificationInterface;
use WCAA\Infrastructure\Poller\PollerAbstract;
use WCAA\Infrastructure\Poller\PollerInterface;
use WCAA\Models\Devices\DeviceInterface;
use WCAA\Models\Pollers\OntIdent;
use WCAA\Models\Devices\Device;
use WCAA\Storage\PollerData\OntIdentStorage;
use WCAA\Storage\Devices\DeviceInterfaceStorage;

class OntIdentification extends PollerAbstract implements PollerInterface
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
        if (!$controller instanceof PollerOntIdentificationInterface) {
            throw new \Exception("Controller " . get_class($controller) . " not implemented PollerOntIdentificationInterface");
        }
        $interfaces = $controller->getOntIdentification();
        $this->sync($device, $interfaces);
        $this->notifyPolledNow($device, 'ont_ident');
    }

    function setManual(Device $device, $data = [])
    {
        $this->sync($device, $data, false);
        $this->notifyPolledNow($device, 'ont_ident');
    }
    function setManualByInterface($device, $data = [])
    {
        $this->sync($device, $data, false);
    }

    function sync(Device $device, $interfaces, $checkNotExists = true)
    {
        $currentIdents = [];
        foreach ($interfaces as $iface) {
            $currentIdents[$iface['interface']['id']] = $iface;
        }
        /**
         * @var \WCAA\Infrastructure\Poller\Interfaces\PollerOntIdentificationInterface[]
         */
        $storageIdents = [];
        if(count($currentIdents) > 1) {
            foreach ($this->storage->getByDevice($device) as $interface) {
                $storageIdents[$interface->getInterface()->getBindKey()] = $interface;
            }
        } elseif (count($currentIdents) === 1) {
            $iface = array_values($currentIdents)[0];
            try {
                $interface = $this->interfaceStorage->getByDeviceAndKey($device, $iface['interface']['id']);
                $ident = $this->storage->getByInterface($interface);
                $storageIdents[$iface['interface']['id']] = $ident;
            } catch (\Exception $e) {
                if ($this->logger) $this->logger->warning("Interface not found in storage for ont ident", ['device' => $device->getAsArray(), 'ident' => $iface]);
            }
        }

        foreach ($currentIdents as $bindKey => $current) {
            try {
                if (isset($storageIdents[$bindKey])) {
                    $state = $storageIdents[$bindKey];
                } else {
                    $state = new OntIdent();
                    try {
                        $interface = $this->interfaceStorage->getByDeviceAndKey($device, $bindKey);
                        $state->setInterface($interface);
                    } catch (\Exception $e) {
                        if ($this->logger) $this->logger->warning("Interface not found in storage for ont ident", ['device' => $device->getAsArray(), 'ident' => $current]);
                        continue;
                    }
                }

                $mustUpdate = false;
                if ($current['type'] && $state->getType() != $current['type']) {
                    $state->setType($current['type']);
                    $mustUpdate = true;
                }
                if ($current['ident'] && $state->getIdent() != $current['ident']) {
                    $mustUpdate = true;
                    $state->setIdent($current['ident']);
                }
                if (!$state->getId()) {
                    try {
                        $this->storage->add($state);
                    } catch (\Exception $e) {

                    }
                } elseif ($mustUpdate) {
                    $this->storage->update($state);
                }
            } catch (\Throwable $e) {
                $this->logger->error("error from get ont identification: {$e->getMessage()}");
                foreach (explode("\n", $e->getTraceAsString()) as $line) {
                    $this->logger->debug($line);
                }
            }
        }

        if(App::getInstance()->conf('poller.do_not_clear_interfaces')) {
            return;
        }
        //Check interfaces removed
        if($checkNotExists) {
            foreach ($storageIdents as $bindKey => $interface) {
                if (isset($currentIdents[$bindKey])) {
                    continue;
                }
                $this->storage->delete($interface);
            }
        }
    }
}