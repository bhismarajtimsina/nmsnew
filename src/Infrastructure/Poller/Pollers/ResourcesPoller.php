<?php

namespace WCAA\Infrastructure\Poller\Pollers;

use WCAA\Infrastructure\Poller\Interfaces\PollerResourcesInterface;
use WCAA\Infrastructure\Poller\PollerAbstract;
use WCAA\Infrastructure\Poller\PollerInterface;
use WCAA\Infrastructure\PrometheusMetrics;
use WCAA\Models\Devices\Device;
use WCAA\Storage\Devices\DeviceStorage;

class ResourcesPoller extends PollerAbstract implements PollerInterface
{
    /**
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @var PrometheusMetrics
     */
    protected $metrics;

    /**
     * @param DeviceStorage $deviceStorage
     */
    function __construct(DeviceStorage $deviceStorage, PrometheusMetrics $metrics) {
        $this->deviceStorage = $deviceStorage;
        $this->metrics = $metrics;
    }

    function setManual(Device $device, $data = [])
    {
        $this->sync($device, $data);
        $this->notifyPolledNow($device, 'sys_resources');
    }

    function sync(Device $device, $info) {
        if(isset($info['cpu']['util'])) {
            $this->metrics->setGauge('device_resources_cpu_util', $info['cpu']['util'], [
                'dev_id' => $device->getId(),
                'ip' => $device->getIp(),
            ], 'CPU usage', 43200);
        } else {
            $this->logger->warning("No CPU info from device {$device->getIp()}");
        }

        if(isset($info['memory']['util'])) {
            $this->metrics->setGauge('device_resources_memory_util', $info['memory']['util'], [
                'dev_id' => $device->getId(),
                'ip' => $device->getIp(),
            ], 'Memory resources', 43200);
        } else {
            $this->logger->warning("No RAM info from device {$device->getIp()}");
        }

        if(isset($info['temperatures'])) {
            unset($info['temperatures']['main_from']);
            foreach ($info['temperatures'] as $name => $temperature) {
                if(!is_numeric($temperature)) {
                    continue;
                }
                if($name === 'main') {
                    $metricName = 'device_resources_temperature';
                } else {
                    $metricName = "device_resources_temperature_{$name}";
                }
                $this->metrics->setGauge($metricName, $temperature, [
                    'dev_id' => $device->getId(),
                    'ip' => $device->getIp(),
                ], "{$name} temperature", 43200);
            }
        }
        if(isset($info['disk']['util'])) {
            $this->metrics->setGauge('device_resources_disk_util', $info['disk']['util'], [
                'dev_id' => $device->getId(),
                'ip' => $device->getIp(),
            ], 'Disk utilization', 43200);
        }
    }

    function poll(Device $device, $controller) {
        if(!$controller instanceof PollerResourcesInterface) {
            throw new \Exception("Controller ".get_class($controller)." not implemented ResourceWalkerInterface");
        }
        $info = $controller->getResources('device');
        $this->sync($device, $info);
    }
}