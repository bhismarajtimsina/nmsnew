<?php

namespace WCC\Pinger\Controllers;

use WCAA\Infrastructure\Components\AbstractComponentController;
use WCAA\Infrastructure\Events\EventObserverStorage;
use WCAA\Infrastructure\PrometheusMetrics;
use WCAA\Models\Devices\Device;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\Pinger\Models\DownLog;
use WCC\Pinger\Models\PingerDeviceStatus;
use WCC\Pinger\Storage\DownLogStorage;
use WCC\Pinger\Storage\PingerDeviceStatusStorage;

class Controller extends AbstractComponentController
{

    /**
     * @Inject
     * @var PingerDeviceStatusStorage
     */
    protected $statusStorage;


    /**
     * @Inject
     * @var PrometheusMetrics
     */
    protected $metricExporter;

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @Inject
     * @var DownLogStorage
     */
    protected $logsStorage;


    /**
     * @Inject
     * @var EventObserverStorage
     */
    protected $eventObserver;


    /**
     * @return \WCC\Pinger\Models\PingerDeviceStatus[]
     * @throws \Exception
     */
    function getPingerDeviceStatuses($fillDevice = true)
    {
        return $this->statusStorage->getAllStatuses($fillDevice);
    }

    /**
     * @param Device $device
     * @return PingerDeviceStatus|null
     * @throws \Exception
     */
    function getDeviceStatus(Device $device)
    {
        return $this->statusStorage->getDeviceStatus($device);
    }

    function getDownLogsByDevice(Device $device)
    {
        $data = [];
        foreach ($this->logsStorage->getHistoryByDevice($device) as $dev) {
            $dt = $dev->getAsArray();
            unset($dt['device']);
            $stop = time();
            if($dt['stop']) $stop = \DateTime::createFromFormat("Y-m-d H:i:s", $dt['stop'])->getTimestamp();
            $start = \DateTime::createFromFormat("Y-m-d H:i:s", $dt['start'])->getTimestamp();
            $dt['duration_sec'] = $stop - $start;
            $data[] = $dt;
        }
        return $data;
    }


    function getPingerHosts()
    {
        $data = [];
        foreach (array_filter($this->deviceStorage->fetchAll(), function ($dev) {
            return $dev->isEnabled();
        }) as $dev) {
            $devStatus = $this->statusStorage->getDeviceStatus($dev);
            $data[] = [
                'ip' => $dev->getIp(),
                'id' => $dev->getId(),
                'status' => $devStatus !== null ? $devStatus->getLatency() : -1,
            ];
            if (!$devStatus) {
                $this->statusStorage->add((new PingerDeviceStatus())->setDevice($dev)->setLatency(-1));
            }
        }
        return $data;
    }

    function getPingerStatusStat()
    {
        return $this->statusStorage->getAllStatuses();
    }

    function recalcHostStatusMetrics()
    {
        foreach ($this->getPingerDeviceStatuses() as $status) {
            $this->metricExporter->setGauge('pinger_host_status',
                $status->getLatency(),
                [
                    'dev_id' => $status->getDevice()->getId(),
                    'ip' => $status->getDevice()->getIp(),
                ],
                'Pinger ICMP statuses',
                600
            );
        }
        return $this;
    }

    function updatePingerHosts($data = [])
    {
        foreach ($data as $d) {
            //Get last status
            $lastStatus = $this->statusStorage->getDeviceStatusByDeviceIP($d['ip']);
            if (!$lastStatus) {
                //Its new device
                $this->logger->debug("Device with ip {$d['ip']} not found in statusStorage");
                $this->statusStorage->add(
                    (new PingerDeviceStatus())->setDevice(
                        $this->deviceStorage->getByIp($d['ip'])
                    )->setLatency($d['status'])
                );
                continue;
            }

            $this->metricExporter->setGauge('pinger_host_status',
                $d['status'],
                [
                    'dev_id' => $lastStatus->getDevice()->getId(),
                    'ip' => $lastStatus->getDevice()->getIp(),
                ],
                'Pinger ICMP statuses',
                300
            );

            if($lastStatus->isUp() && $lastStatus->isNoICMP() && $d['status'] > 0 && $d['status'] != 999) {
                //Update device status
                $lastStatus->setLatency($d['status']);
                $this->statusStorage->update($lastStatus);
                continue;
            }

            if ($lastStatus->getLatency() == $d['status']) continue;
            if ($lastStatus->isUp() && $d['status'] > 0) continue;
            if (!$lastStatus->isUp() && $d['status'] <= 0) continue;

            //Update device status
            $lastStatus->setLastChange(date("Y-m-d H:i:s"))->setLatency($d['status']);
            $this->statusStorage->update($lastStatus);


            if ($d['status'] <= 0) {
                //Device is change to down, new log must be created
                $this->logsStorage->add(
                    (new DownLog())
                        ->setDevice($lastStatus->getDevice())
                        ->setStart(date("Y-m-d H:i:s"))
                );
                $this->eventObserver->notify("pinger:host-status-changed", [
                    // getAsArrayLite(), not the raw Device object: this
                    // event's $data is an array with the device NESTED
                    // inside it, not the model itself at the top level —
                    // EventLogToRedisQueue's is_object/instanceof AbstractModel
                    // check (which calls getAsArray() for it) only applies
                    // to the top-level payload, so a nested model here would
                    // otherwise hit a plain json_encode() and lose every
                    // protected/private property (i.e. everything), same
                    // shape PollerProcessor already uses for its own device
                    // field for the same reason.
                    'device' => $lastStatus->getDevice()->getAsArrayLite(),
                    'status' => 'down',
                ]);
            } else {
                $noClosedDownLogs = $this->logsStorage->getNoClosedLogsByDevice($lastStatus->getDevice());
                $this->eventObserver->notify("pinger:host-status-changed", [
                    'device' => $lastStatus->getDevice()->getAsArrayLite(),
                    'status' => 'up',
                ]);
                foreach ($noClosedDownLogs as $noClose) {
                    $this->logsStorage->update($noClose->setStop(date("Y-m-d H:i:s")));
                }
            }
        }
    }
}
