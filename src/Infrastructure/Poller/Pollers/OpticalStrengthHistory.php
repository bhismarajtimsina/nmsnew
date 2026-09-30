<?php

namespace WCAA\Infrastructure\Poller\Pollers;

use Prometheus\CollectorRegistry;
use WCAA\Infrastructure\Poller\Interfaces\PollerOpticalStrengthInterface;
use WCAA\Infrastructure\Poller\PollerAbstract;
use WCAA\Infrastructure\Poller\PollerInterface;
use WCAA\Infrastructure\PrometheusMetrics;
use WCAA\Models\Devices\Device;
use WCAA\Models\Devices\DeviceInterface;
use WCAA\Storage\Devices\DeviceInterfaceStorage;

class OpticalStrengthHistory extends PollerAbstract implements PollerInterface
{

    /**
     * @Inject
     * @var CollectorRegistry
     */
    protected $promRegistry;

    /**
     * @Inject
     * @var PrometheusMetrics
     */
    protected $metrics;

    /**
     * @var DeviceInterfaceStorage
     */
    protected $deviceInterfaceStorage;

    /**
     * @param DeviceInterfaceStorage $interfaceStorage
     */
    function __construct(DeviceInterfaceStorage $interfaceStorage)
    {
        $this->deviceInterfaceStorage = $interfaceStorage;
    }

    function setManual(Device $device, $data = []) {
        $this->sync($device, $data);
        $this->notifyPolledNow($device, 'optical_strength');
    }

    function setManualByInterface(Device $device, $data)
    {
        $this->sync($device, $data);
    }

    function sync(Device $device, $opticalCurrentInfo) {
        $allowed = $this->getAllowedInterfacesByDevice($device);
        foreach ($opticalCurrentInfo as $current) {
            if(!in_array($current['interface']['id'], $allowed)) {
                continue;
            }
            try {
                $iface = $current['interface'];
                if(!isset($iface['type'])) $iface['type'] = 'UNKNOWN';
                if(isset($current['rx']) && $current['rx'])
                    $this->metrics->setGauge(
                        'optical_rx',
                        (float) $current['rx'],
                        [
                            'dev_id' => $device->getId(),
                            'ip' => $device->getIp(),
                            'iface_id' => $iface['id'],
                            'iface_name' => $iface['name'],
                            'iface_type' => $iface['type'],
                        ],
                        "Device (ONT/OLT/SFP) optical information",
                        86400
                    );


                if(isset($current['tx']) && $current['tx'])
                    $this->metrics->setGauge(
                        'optical_tx',
                        (float)$current['tx'],
                        [
                            'dev_id' => $device->getId(),
                            'ip' => $device->getIp(),
                            'iface_id' => $iface['id'],
                            'iface_name' => $iface['name'],
                            'iface_type' => $iface['type'],
                        ],
                        "Device (ONT/OLT/SFP) optical information",
                        86400
                    );

                if(isset($current['temp']) && $current['temp'])
                    $this->metrics->setGauge(
                        'optical_temperature',
                        (float)$current['temp'],
                        [
                            'dev_id' => $device->getId(),
                            'ip' => $device->getIp(),
                            'iface_id' => $iface['id'],
                            'iface_name' => $iface['name'],
                            'iface_type' => $iface['type'],
                        ],
                        "Device (ONT/OLT/SFP) optical information",
                        86400
                    );

                if(isset($current['voltage']) && $current['voltage'])
                    $this->metrics->setGauge(
                        'optical_voltage',
                        (float)$current['voltage'],
                        [
                            'dev_id' => $device->getId(),
                            'ip' => $device->getIp(),
                            'iface_id' => $iface['id'],
                            'iface_name' => $iface['name'],
                            'iface_type' => $iface['type'],
                        ],
                        "Device (ONT/OLT/SFP) optical information",
                        86400
                    );

                if(isset($current['olt_rx']) && $current['olt_rx'])
                    $this->metrics->setGauge(
                        'optical_olt_rx',
                        (float)$current['olt_rx'],
                        [
                            'dev_id' => $device->getId(),
                            'ip' => $device->getIp(),
                            'iface_id' => $iface['id'],
                            'iface_name' => $iface['name'],
                            'iface_type' => $iface['type'],
                        ],
                        "Device (ONT/OLT/SFP) optical information",
                        86400
                    );


                if(isset($current['olt_tx']) && $current['olt_tx'])
                    $this->metrics->setGauge(
                        'optical_olt_tx',
                        (float)$current['olt_tx'],
                        [
                            'dev_id' => $device->getId(),
                            'ip' => $device->getIp(),
                            'iface_id' => $iface['id'],
                            'iface_name' => $iface['name'],
                            'iface_type' => $iface['type'],
                        ],
                        "Device (ONT/OLT/SFP) optical information",
                        86400
                    );

                if(isset($current['attenuation']) && $current['attenuation'])
                    $this->metrics->setGauge(
                        'optical_attenuation',
                        (float)$current['attenuation'],
                        [
                            'dev_id' => $device->getId(),
                            'ip' => $device->getIp(),
                            'iface_id' => $iface['id'],
                            'iface_name' => $iface['name'],
                            'iface_type' => $iface['type'],
                        ],
                        "Device (ONT/OLT/SFP) optical information",
                        86400
                    );

                if(isset($current['distance']) && $current['distance']) {
                    $this->metrics->setGauge(
                        'optical_distance',
                        (float)$current['distance'],
                        [
                            'dev_id' => $device->getId(),
                            'ip' => $device->getIp(),
                            'iface_id' => $iface['id'],
                            'iface_name' => $iface['name'],
                            'iface_type' => $iface['type'],
                        ],
                        "Device (ONT/OLT/SFP) optical information",
                        86400
                    );
                }
            } catch (\Throwable $e) {
                $this->logger->error("error from get ont optical information: {$e->getMessage()}");
                foreach (explode("\n", $e->getTraceAsString()) as $line) {
                    $this->logger->debug($line);
                }
            }
        }
    }

    function poll(Device $device, $controller)
    {
        if (!$controller instanceof PollerOpticalStrengthInterface) {
            throw new \Exception("Controller " . get_class($controller) . " not implemented PollerOpticalStrengthInterface");
        }
        $opticalCurrentInfo = $controller->getOpticalStrength();
        $this->sync($device, $opticalCurrentInfo);
    }
}
