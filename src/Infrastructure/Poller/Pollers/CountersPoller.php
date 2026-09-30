<?php

namespace WCAA\Infrastructure\Poller\Pollers;

use Prometheus\CollectorRegistry;
use WCAA\Infrastructure\Poller\Interfaces\PollerCountersInterface;
use WCAA\Infrastructure\Poller\PollerAbstract;
use WCAA\Infrastructure\Poller\PollerInterface;
use WCAA\Infrastructure\PrometheusMetrics;
use WCAA\Models\Devices\Device;
use WCAA\Models\Devices\DeviceInterface;
use WCAA\Storage\Devices\DeviceInterfaceStorage;

class CountersPoller extends PollerAbstract implements PollerInterface
{

    /**
     * @var CollectorRegistry
     */
    protected $promRegistry;

    /**
     * @param PrometheusMetrics $collectorRegistry
     */
    function __construct(PrometheusMetrics $collectorRegistry)
    {
        $this->promRegistry = $collectorRegistry;
    }

    function setManual(Device $device, $data = [])
    {
        $this->sync($device, $data);
        $this->notifyPolledNow($device, 'counters');
    }
    function setManualByInterface(Device $device, $data = [])
    {
        $this->sync($device, $data);
    }


    function poll(Device $device, $controller)
    {
        if (!$controller instanceof PollerCountersInterface) {
            throw new \Exception("Controller " . get_class($controller) . " not implemented PollerCountersInterface");
        }
        $interfaces = $controller->getCounters();
        $this->sync($device, $interfaces);
    }

    function sync(Device $device, $interfaces = []) {
        $allowed = $this->getAllowedInterfacesByDevice($device);
        if($interfaces) {
            foreach ($interfaces as $interface) {
                if(!in_array($interface['interface']['id'], $allowed)) {
                    continue;
                }
                foreach ($interface as $key=>$value) {
                    if($key === 'interface') continue;
                    $this->promRegistry->setGauge('iface_stat_' .$key,
                        (float) $value,
                        [
                            'dev_id' => $device->getId(),
                            'ip'=>$device->getIp(),
                            'iface_id'=>$interface['interface']['id'],
                            'iface_name'=>$interface['interface']['name']
                        ],
                        "Interface stat for {$key}", 86400
                    );
                }
            }
        }
    }
}
