<?php

namespace WCC\PrometheusWrapper\Controllers;

use WCAA\Infrastructure\PrometheusMetrics;
use WCAA\Models\Devices\Device;
use WCAA\Models\Devices\DeviceInterface;

class PrometheusMetricsTempStore
{
    /**
     * @Inject
     * @var PrometheusMetrics
     */
    protected $metrics;


    function getLastMetricsByDevice(Device $device, $metricNames = []) {
        $metrics = [];
        foreach ($metricNames as $metricName) {
            $metrics[$metricName] = $this->metrics->getLastValues($metricName, [
                'ip' => $device->getIp(),
                'dev_id' => $device->getId(),
            ]);
        }
        return $metrics;
    }

    function getLastMetricsByInterface(DeviceInterface $iface, $metricNames = []) {
        $metrics = [];
        foreach ($metricNames as $metricName) {
            $metrics[$metricName] = $this->metrics->getLastValues($metricName, [
                'ip' => $iface->getDevice()->getIp(),
                'dev_id' => $iface->getDevice()->getId(),
                'iface_id' => $iface->getBindKey(),
            ]);
        }
        return $metrics;
    }

    function getLastByMetrics($metricNames = []) {
        $metrics = [];
        foreach ($metricNames as $metricName) {
            $metrics[$metricName] = $this->metrics->getLastValues($metricName);
        }
        return $metrics;
    }
}