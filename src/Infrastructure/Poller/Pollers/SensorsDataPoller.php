<?php

namespace WCAA\Infrastructure\Poller\Pollers;

use Prometheus\CollectorRegistry;
use WCAA\Infrastructure\Poller\Interfaces\PollerOpticalStrengthInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerSensorsInterface;
use WCAA\Infrastructure\Poller\PollerAbstract;
use WCAA\Infrastructure\Poller\PollerInterface;
use WCAA\Infrastructure\PrometheusMetrics;
use WCAA\Models\Devices\Device;
use WCAA\Models\Devices\DeviceInterface;
use WCAA\Storage\Devices\DeviceInterfaceStorage;

class SensorsDataPoller extends PollerAbstract implements PollerInterface
{


    /**
     * @Inject
     * @var PrometheusMetrics
     */
    protected $metrics;


    function setManual(Device $device, $data = [])
    {
        $this->sync($device, $data);
        $this->notifyPolledNow($device, 'sensors_data');
    }

    function setManualByInterface(Device $device, $data)
    {
        $this->sync($device, $data);
    }

    function sync(Device $device, $sensorsData)
    {
        foreach ($sensorsData as $dt) {
            $this->metrics->setGauge(
                'device_sensor',
                (float)$dt['value'],
                [
                    'dev_id' => $device->getId(),
                    'ip' => $device->getIp(),
                    'sensor_id' => $dt['id'],
                    'type' => $dt['type'],
                    'name' => $dt['name'],
                ],
                "Sensors data",
                1800
            );
        }
    }

    function poll(Device $device, $controller)
    {
        if (!$controller instanceof PollerSensorsInterface) {
            throw new \Exception("Controller " . get_class($controller) . " not implemented PollerOpticalStrengthInterface");
        }
        $opticalCurrentInfo = $controller->getAllSensorsData();
        $this->sync($device, $opticalCurrentInfo);
    }
}
