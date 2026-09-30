<?php

namespace WCAA\Infrastructure\Poller\Pollers;

use Prometheus\CollectorRegistry;
use WCAA\Infrastructure\Poller\Interfaces\PollerCardsStatusInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerOpticalStrengthInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerSensorsInterface;
use WCAA\Infrastructure\Poller\PollerAbstract;
use WCAA\Infrastructure\Poller\PollerInterface;
use WCAA\Infrastructure\PrometheusMetrics;
use WCAA\Models\Devices\Device;
use WCAA\Models\Devices\DeviceInterface;
use WCAA\Storage\Devices\DeviceInterfaceStorage;

class CardsStatusPoller extends PollerAbstract implements PollerInterface
{


    /**
     * @Inject
     * @var PrometheusMetrics
     */
    protected $metrics;


    function setManual(Device $device, $data = [])
    {
        $this->sync($device, $data);
        $this->notifyPolledNow($device, 'cards_status');
    }

    function setManualByInterface(Device $device, $data)
    {
        $this->sync($device, $data);
    }

    function sync(Device $device, $data)
    {
        foreach ($data as $dt) {
            $labels = [
              'dev_id' => $device->getId(),
              'ip' => $device->getIp(),
              'shelf' => $dt['shelf'],
              'slot' => $dt['slot'],
            ];
            if(isset($dt['_oper_status'])) {
                $this->metrics->setGauge('card_status_oper_status',
                    (float)$dt['_oper_status'],
                    $labels,
                    "Card operative status",
                    3600
                );
            }
            if(isset($dt['_admin_status'])) {
                $this->metrics->setGauge('card_status_admin_status',
                    (float)$dt['_admin_status'],
                    $labels,
                    "Card administrative status",
                    3600
                );
            }
            if(isset($dt['cpu_load'])) {
                $this->metrics->setGauge('card_status_cpu_load',
                    (float)$dt['cpu_load'],
                    $labels,
                    "Card CPU load",
                    3600
                );
            }
            if(isset($dt['temperature'])) {
                $this->metrics->setGauge('card_status_temperature',
                    (float)$dt['temperature'],
                    $labels,
                    "Card temperature",
                    3600
                );
            }
            if(isset($dt['memory_usage'])) {
                $this->metrics->setGauge('card_status_memory_usage',
                    (float)$dt['memory_usage'],
                    $labels,
                    "Card memory_usage",
                    3600
                );
            }
        }
    }

    function poll(Device $device, $controller)
    {
        if (!$controller instanceof PollerCardsStatusInterface) {
            throw new \Exception("Controller " . get_class($controller) . " not implemented PollerCardsStatusInterface");
        }
        $opticalCurrentInfo = $controller->getCardsStatus('device');
        $this->sync($device, $opticalCurrentInfo);
    }
}
