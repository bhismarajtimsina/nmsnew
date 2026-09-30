<?php

namespace WCAA\Infrastructure\Poller\Pollers;

use WCAA\Infrastructure\Poller\Interfaces\PollerArpsInterface;
use WCAA\Infrastructure\Poller\PollerAbstract;
use WCAA\Infrastructure\Poller\PollerInterface;
use WCAA\Infrastructure\Events\EventObserverStorage;
use WCAA\Infrastructure\PrometheusMetrics;
use WCAA\Models\Devices\DeviceInterface;
use WCAA\Models\Pollers\ArpHistory;
use WCAA\Models\Devices\Device;
use WCAA\Storage\PollerData\ArpHistoryStorage;

class ArpsPoller extends PollerAbstract implements PollerInterface
{
    /**
     * @var ArpHistoryStorage
     */
    protected ArpHistoryStorage $storage;

    /**
     * @var PrometheusMetrics
     */
    protected PrometheusMetrics $metrics;

    function __construct(EventObserverStorage $events, ArpHistoryStorage $storage, PrometheusMetrics $metrics) {
        $this->storage = $storage;
        $this->metrics =  $metrics;
        $this->events =  $events;
    }




    function poll(Device $device, $controller) {
        if(!$controller instanceof PollerArpsInterface) {
            throw new \Exception("Controller ".get_class($controller)." not implemented PollerArpsInterface");
        }
        $this->sync($device, $controller->getArps([], 'device'));
    }

    function setManual(Device $device, $data = [])
    {
        $this->sync($device, $data);
        $this->notifyPolledNow($device, 'arp_table');
    }

    function sync(Device $device,  $data = []) {
        $currentARP = [];
        foreach ($data as $arp) {
            if(!$arp['ip']) continue;
            if(!$arp['mac']) continue;
            $currentARP["{$arp['ip']}-{$arp['mac']}-{$arp['interface']}"] = $arp;
        }
        $arpInStorage = [];
        /**
         * @var $arp ArpHistory
         */
        foreach ($this->storage->getByDevice($device, true) as $arp) {
            $arpInStorage["{$arp->getIp()}-{$arp->getMac()}-{$arp->getInterface()}"] = $arp;
        }

        //Check added new ARP
        foreach ($currentARP as $arp) {
            if(isset($arpInStorage["{$arp['ip']}-{$arp['mac']}-{$arp['interface']}"])) {
                continue;
            }
            $dynamic = 'UNKNOWN';
            if(isset($arp['dynamic']) && $arp['dynamic']) {
                $dynamic = 'YES';
            } elseif (isset($arp['dynamic']) && !$arp['dynamic']) {
                $dynamic = 'NO';
            }
            $newArp = (new \WCAA\Models\Pollers\ArpHistory())
                ->setDevice($device)
                ->setDynamic($dynamic)
                ->setStatus(isset($arp['status']) ? $arp['status'] : 'OK')
                ->setComment(isset($arp['comment']) ? $arp['comment'] : null)
                ->setInterface(isset($arp['interface']) ? $arp['interface'] : $arp['vlan_id'])
                ->setStartAt(date("Y-m-d H:i:s"))
                ->setMac($arp['mac'] )
                ->setIp($arp['ip'] )
                ->setVlanId($arp['vlan_id']);
            $this->storage->add(
                $newArp
            );
            $this->events->notify("arp:added", $newArp);
        }
        $this->metrics->setGauge('router_arps_count', count(array_filter($currentARP, function ($e) {
            return isset($e['dynamic']) && $e['dynamic'];
        })), [
            'dev_id' =>  $device->getId(),
            'router_ip' => $device->getIp(),
            'dynamic' => 'yes',
        ], "Return count dynamic ARPs on router");
        $this->metrics->setGauge('router_arps_count', count(array_filter($currentARP, function ($e) {
            return isset($e['dynamic']) && !$e['dynamic'];
        })), [
            'dev_id' =>  $device->getId(),
            'router_ip' => $device->getIp(),
            'dynamic' => 'no',
        ], "Return count static  ARPs on router");
        $this->metrics->setGauge('router_arps_count', count(array_filter($currentARP, function ($e) {
            return !isset($e['dynamic']);
        })), [
            'dev_id' =>  $device->getId(),
            'router_ip' => $device->getIp(),
            'dynamic' => 'unknown',
        ], "Return count unknown ARPs on router");

        //Check mac not exist
        foreach ($arpInStorage as $arp) {
            if(isset($currentARP["{$arp->getIp()}-{$arp->getMac()}-{$arp->getInterface()}"])) {
                continue;
            }
            $this->events->notify('arp:removed', $arp );
            $this->storage->update($arp->setStopAt(date("Y-m-d H:i:s")));
        }
    }
}