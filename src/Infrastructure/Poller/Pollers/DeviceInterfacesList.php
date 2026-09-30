<?php

namespace WCAA\Infrastructure\Poller\Pollers;

use WCAA\App;
use WCAA\Infrastructure\Poller\PollerAbstract;
use WCAA\Infrastructure\Poller\PollerInterface;
use WCAA\Infrastructure\PrometheusMetrics;
use WCAA\Models\Devices\Device;
use WCAA\Models\Devices\DeviceInterface;
use WCAA\Storage\Devices\DeviceInterfaceStorage;

class DeviceInterfacesList extends PollerAbstract implements PollerInterface
{
    /**
     * @var DeviceInterfaceStorage
     */
    protected $storage;

    /**
     * @Inject
     * @var PrometheusMetrics
     */
    protected $metrics;

    /**
     * @param DeviceInterfaceStorage $deviceStorage
     */
    function __construct(DeviceInterfaceStorage $deviceStorage)
    {
        $this->storage = $deviceStorage;
    }


    function setManual(Device $device, $data = [])
    {
        $this->sync($device, $data, false);
        $this->notifyPolledNow($device, 'interfaces_list');
    }

    function setManualByInterface(Device $device, $data = [])
    {
        $this->sync($device, $data, false);
    }


    function poll(Device $device, $controller)
    {
        $interfaces = $controller->getInterfacesList();
        $this->sync($device, $interfaces);
    }

    function sync(Device $device, $interfaces = [], $checkNotExist = true)
    {
        $existInterfaces = [];
        foreach ($interfaces as $iface) {
            $existInterfaces[$iface['id']] = $iface;
        }
        $interfacesInStorage = [];

        if (count($existInterfaces) > 1) {
            foreach ($this->storage->getByDevice($device, null, false) as $interface) {
                $interfacesInStorage[$interface->getBindKey()] = $interface;
            }
        } else if (count($existInterfaces) == 1) {
            $iface = array_values($existInterfaces)[0];
            try {
                $interface = $this->storage->getByDeviceAndKey($device, $iface['id']);
                $interfacesInStorage[$interface->getBindKey()] = $interface;
            } catch (\Exception $e) {
                if ($this->logger) $this->logger->warning("Interface not found in storage", ['device' => $device->getAsArray(), 'iface' => $iface]);
            }
        }

        //Check added new interfaces
        foreach ($existInterfaces as $interface) {
            if (!isset($interface['description'])) {
                $interface['description'] = '';
            }
            if (!isset($interface['type'])) {
                $interface['type'] = 'UNKNOWN';
            }


            try {
                $this->metrics->setGauge(
                    'device_interface',
                    1,
                    [
                        'dev_id' => $device->getId(),
                        'ip' => $device->getIp(),
                        'iface_type' => $interface['type'],
                        'iface_id' => $interface['id'],
                        'iface_name' => $interface['name']
                    ],
                    "Device interfaces list",
                    3600
                );
            } catch (\Throwable $e) {
                if ($this->logger) {
                    $this->logger->error("error collect interface list: {$e->getMessage()}");
                    foreach (explode("\n", $e->getTraceAsString()) as $str) {
                        $this->logger->debug($str);
                    }
                }
            }

            if (
                isset($interfacesInStorage[$interface['id']]) &&
                $interfacesInStorage[$interface['id']]->getDescription() != $interface['description'] &&
                $interface['description'] &&
                (!$device->getParamByName('disable_save_description_on_physical_ifaces') || $interface['type'] == 'ONU')
            ) {
                $this->logger->debug("Update interface {$interface['id']} with device {$device->getId()}");
                $this->storage->update(
                    $interfacesInStorage[$interface['id']]->setDevice($device)->setDescription($interface['description'])
                );
            } elseif (
                isset($interfacesInStorage[$interface['id']]) &&
                isset($interface['parent']) &&
                $interfacesInStorage[$interface['id']]->getParentBindKey() != $interface['parent'] &&
                $interface['parent']
            ) {
                $this->logger->debug("Update interface {$interface['id']} with device {$device->getId()}");
                $iface = $interfacesInStorage[$interface['id']]
                        ->setDevice($device)
                        ->setType($interface['type'])
                        ->setParentBindKey(isset($interface['parent']) ? $interface['parent'] : '');
                if(!$device->getParamByName('disable_save_description_on_physical_ifaces') || $interface['type'] == 'ONU') {
                    $iface->setDescription($interface['description']);
                }
                $this->storage->update($iface);
            } elseif (isset($interfacesInStorage[$interface['id']])) {
                continue;
            } else {
                $this->logger->debug("Added interface {$interface['id']} with device {$device->getId()}");
                $this->storage->add(
                    (new DeviceInterface())
                        ->setDevice($device)->setDescription($interface['description'])
                        ->setName($interface['name'])
                        ->setBindKey($interface['id'])
                        ->setParentBindKey(isset($interface['parent']) ? $interface['parent'] : '')
                        ->setType($interface['type'])
                        ->setStatus(null)
                );
            }
        }

        if(App::getInstance()->conf('poller.do_not_clear_interfaces')) {
            return;
        }

        //Check interfaces removed
        if ($checkNotExist) {
            foreach ($interfacesInStorage as $interface) {
                if (isset($existInterfaces[$interface->getBindKey()])) {
                    continue;
                }
                $this->storage->delete($interface);
            }
        }
    }
}
