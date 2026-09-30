<?php

namespace WCAA\Infrastructure\Poller\Pollers;

use WCAA\Infrastructure\Poller\Interfaces\PollerInterfaceStatusInterface;
use WCAA\Infrastructure\Poller\PollerAbstract;
use WCAA\Infrastructure\Poller\PollerInterface;
use WCAA\Infrastructure\PrometheusMetrics;
use WCAA\Models\Devices\Device;
use WCAA\Models\Devices\DeviceInterface;
use WCAA\Storage\Devices\DeviceInterfaceHistoryStorage;
use WCAA\Storage\Devices\DeviceInterfaceStorage;

class DeviceInterfacesStatus extends PollerAbstract implements PollerInterface
{


    /**
     * @var DeviceInterfaceStorage
     */
    protected $interfaceStorage;

    /**
     * @var DeviceInterfaceHistoryStorage
     */
    protected $deviceInterfaceHistoryStorage;

    /**
     * @Inject
     * @var PrometheusMetrics
     */
    protected $metrics;

    /**
     * @param DeviceInterfaceStorage $interfaceStorage
     */
    function __construct(DeviceInterfaceStorage $interfaceStorage, DeviceInterfaceHistoryStorage $deviceInterfaceHistoryStorage)
    {
        $this->interfaceStorage = $interfaceStorage;
        $this->deviceInterfaceHistoryStorage = $deviceInterfaceHistoryStorage;
    }

    function setManual(Device $device, $data = [])
    {
        $this->sync($device, $data);
        $this->notifyPolledNow($device, 'interfaces_status');
    }

    function setManualByInterface(Device $device, $data = [])
    {
        $this->sync($device, $data);
    }

    function sync(Device $device, $currIfacesStatus)
    {
        $lastStatuses = [];
        if (count($currIfacesStatus) > 1) {
            foreach ($this->interfaceStorage->getByDevice($device) as $iface) {
                $lastStatuses["{$iface->getBindKey()}"] = $iface;
            }
        } else if (count($currIfacesStatus) === 1) {
            $iface = array_values($currIfacesStatus)[0];
            try {
                $interface = $this->interfaceStorage->getByDeviceAndKey($device, $iface['interface']['id']);
                $lastStatuses["{$interface->getBindKey()}"] = $interface;
            } catch (\Exception $e) {
                if ($this->logger) $this->logger->warning("Interface not found in storage for ont ident", ['device' => $device->getAsArray(), 'iface' => $iface]);
            }
        }

        $allowed = $this->getAllowedInterfacesByDevice($device);
        $currentInterfaceStatuses = [];
        foreach ($currIfacesStatus as $current) {
            if (!isset($currentInterfaceStatuses[$current['interface']['id']])) {
                $currentInterfaceStatuses[$current['interface']['id']] = $current;
            }
            if($currentInterfaceStatuses[$current['interface']['id']]['status'] === 'Down') {
                $currentInterfaceStatuses[$current['interface']['id']] = $current;
            }
        }

        foreach ($currentInterfaceStatuses as $current) {
            if (!isset($current['interface']['type'])) {
                $current['interface']['type'] = 'UNKNOWN';
            }
            if (isset($current['type'])) {
                $current['interface']['type'] = $current['type'];
            }
            $status = $current['status'];

            $promStatus = in_array($current['status'], ['Online', 'Up']) ? 1 : 0;
            if (isset($current['bind_status']) && $current['bind_status']) {
                if ($current['bind_status'] == 'Online') {
                    $status = 'Online';
                    $promStatus = 1;
                } elseif ($current['bind_status'] == 'Offline') {
                    $status = 'Offline';
                    $promStatus = 0;
                } elseif (strpos(strtolower($current['bind_status']), 'los') !== false) {
                    $status = 'LOS';
                    $promStatus = -2;
                } elseif (in_array($current['bind_status'], ['PowerOff'])) {
                    $status = 'PowerOff';
                    $promStatus = -1;
                }
            } else if ($current['interface']['type'] !== 'ONU') {
                $status = in_array($current['status'], ['Online', 'Up']) ? 'Up' : 'Down';
            }

            $state = $current['admin_state'] == 'Enabled' ? 'Enabled' : 'Disabled';
            if (isset($lastStatuses[$current['interface']['id']]) && $lastStatuses[$current['interface']['id']]->getStatus() !== $status) {

                if (isset($current['bind_status']) && $current['bind_status'] && !in_array($status, ['Up', 'Online'])) {
                    $iface = $this->interfaceStorage
                        ->update(
                            $lastStatuses[$current['interface']['id']]
                                ->setUpdatedAt(date("Y-m-d H:i:s"))
                                ->setStatus($status)
                            , false);
                    foreach ($this->deviceInterfaceHistoryStorage->getLastActive($iface) as $active) {
                        $this->deviceInterfaceHistoryStorage->update($active->setDown(date("Y-m-d H:i:s"))->setDownReason($current['bind_status']));
                    };
                } else {
                    $this->interfaceStorage
                        ->update(
                            $lastStatuses[$current['interface']['id']]
                                ->setUpdatedAt(date("Y-m-d H:i:s"))
                                ->setStatus($status)
                        );
                }


            }
            if (!in_array($current['interface']['id'], $allowed)) {
                continue;
            }

            try {
                $ifaceSpeed = null;
                if(isset($current['nway_status']) && $current['nway_status']) {
                    if(preg_match('/^([0-9]{1,5})([MG]).*?$/', $current['nway_status'], $m)) {
                        if($m[2] == "M") {
                            $ifaceSpeed = (int)$m[1] ;
                        }  elseif ($m[2] == "G") {
                            $ifaceSpeed = (int)$m[1] * 1024;
                        }
                    }
                }

                if($ifaceSpeed !== null) {
                    $this->metrics->setGauge(
                        'device_interface_speed',
                        $ifaceSpeed,
                        [
                            'dev_id' => $device->getId(),
                            'ip' => $device->getIp(),
                            'iface_type' => $current['interface']['type'],
                            'iface_id' => $current['interface']['id'],
                            'iface_name' => $current['interface']['name']
                        ],
                        "Device interface speed in mbits",
                        43200
                    );
                }
            } catch (\Exception $e) {
                if ($this->logger) {
                    $this->logger->error("error collect interface status: {$e->getMessage()}");
                    foreach (explode("\n", $e->getTraceAsString()) as $str) {
                        $this->logger->debug($str);
                    }
                }
            }

            try {
                $this->metrics->setGauge(
                    'device_interface_status',
                    $promStatus,
                    [
                        'dev_id' => $device->getId(),
                        'ip' => $device->getIp(),
                        'iface_type' => $current['interface']['type'],
                        'iface_id' => $current['interface']['id'],
                        'iface_name' => $current['interface']['name']
                    ],
                    "Device interface status",
                    43200
                );
                $this->metrics->setGauge(
                    'device_interface_admin_state',
                    $state == 'Enabled' ? 1 : 0,
                    [
                        'dev_id' => $device->getId(),
                        'ip' => $device->getIp(),
                        'iface_type' => $current['interface']['type'],
                        'iface_id' => $current['interface']['id'],
                        'iface_name' => $current['interface']['name']
                    ],
                    "Device interface admin stat",
                    43200
                );

            } catch (\Throwable $e) {
                if ($this->logger) {
                    $this->logger->error("error collect interface status: {$e->getMessage()}");
                    foreach (explode("\n", $e->getTraceAsString()) as $str) {
                        $this->logger->debug($str);
                    }
                }
            }
        }
    }

    function poll(Device $device, $controller)
    {
        if (!$controller instanceof PollerInterfaceStatusInterface) {
            throw new \Exception("Controller " . get_class($controller) . " not implemented PollerInterfaceStatusInterface");
        }
        $currentInterfaceStatuses = $controller->getInterfaceStatuses();
        $this->sync($device, $currentInterfaceStatuses);
    }
}
