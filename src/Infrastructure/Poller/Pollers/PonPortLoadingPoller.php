<?php

namespace WCAA\Infrastructure\Poller\Pollers;

use WCAA\Infrastructure\Poller\Interfaces\PollerResourcesInterface;
use WCAA\Infrastructure\Poller\Interfaces\PonPortLoadingInterface;
use WCAA\Infrastructure\Poller\PollerAbstract;
use WCAA\Infrastructure\Poller\PollerInterface;
use WCAA\Infrastructure\PrometheusMetrics;
use WCAA\Models\Devices\Device;
use WCAA\Storage\Devices\DeviceStorage;

class PonPortLoadingPoller extends PollerAbstract implements PollerInterface
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
        $this->notifyPolledNow($device, 'pon_port_loading');
    }

    function sync(Device $device, $info) {
        foreach ($info as $port) {
            $this->metrics->setGauge('pon_port_max_onts_count', (float)$port['max'], [
                'dev_id' => $device->getId(),
                'ip' => $device->getIp(),
                'iface_id'=>$port['interface']['id'],
                'iface_name'=>$port['interface']['name']
            ], 'PON port utilization', 86400);
            $this->metrics->setGauge('pon_port_current_onts_count', (float)$port['current'], [
                'dev_id' => $device->getId(),
                'ip' => $device->getIp(),
                'iface_id'=>$port['interface']['id'],
                'iface_name'=>$port['interface']['name']
            ], 'PON port utilization', 86400);
        }
    }

    function poll(Device $device, $controller) {
        if(!$controller instanceof PonPortLoadingInterface) {
            throw new \Exception("Controller ".get_class($controller)." not implemented PonPortLoadingInterface");
        }
        $info = $controller->getPonPortLoadingStat();
        $this->sync($device, $info);
    }
}