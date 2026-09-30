<?php

namespace WCAA\Infrastructure\Poller\Pollers;

use WCAA\Infrastructure\Poller\PollerAbstract;
use WCAA\Infrastructure\Poller\PollerInterface;
use WCAA\Infrastructure\PrometheusMetrics;
use WCAA\Models\Devices\Device;
use WCAA\Storage\Devices\DeviceStorage;

class WalkSystemInfo extends PollerAbstract implements PollerInterface
{
    /**
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @Inject
     * @var PrometheusMetrics
     */
    protected $promMetrics;


    /**
     * @param DeviceStorage $deviceStorage
     */
    function __construct(DeviceStorage $deviceStorage) {
        $this->deviceStorage = $deviceStorage;
    }

    function setManual(Device $device, $data = [])
    {
        $this->sync($device, $data);
        $this->notifyPolledNow($device, 'system');
    }

    function sync(Device $device, $info) {
        $mustUpdate = false;
        if(isset($info['mac_addr']) && $info['mac_addr'] && $info['mac_addr'] !== $device->getMac()) {
            $device->setMac($info['mac_addr']);
            $mustUpdate = true;
        }
        if(isset($info['serial_num']) && $info['serial_num'] && $info['serial_num'] !== $device->getSerial()) {
            $device->setSerial($info['serial_num']);
            $mustUpdate = true;
        }
        if(isset($info['uptime_sec'])) {
            $this->promMetrics->setGauge('device_uptime', $info['uptime_sec'], [
                'dev_id' => $device->getId(),
                'ip' => $device->getIp(),
            ], 'Device uptime', 43200);
        }
        if($mustUpdate) {
            $this->deviceStorage->update($device);
        }
    }

    function poll(Device $device, $controller) {
        $info = $controller->getSystemInfo();
        $this->sync($device, $info);
    }
}