<?php


namespace WCC\LiveTraffic\Controllers;


use DI\Annotation\Inject;
use Slim\Exception\HttpBadRequestException;
use SwitcherCore\Config\ModelCollector;
use SwitcherCore\Exceptions\ModuleNotFoundException;
use WCAA\Infrastructure\CacheSystems\MemCache;
use WCAA\Infrastructure\Components\AbstractComponentController;
use WCAA\Interfaces\CacheInterface;
use WCAA\Models\Devices\DeviceInterface;
use WCAA\Models\Pollers\FdbHistory;
use WCAA\Models\Devices\Device;
use WCAA\Models\User\User;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\PollerData\FdbHistoryStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\SwitcherCore\Request;
use WCAA\SwitcherCore\SwitcherCore;

/**
 * Class Controller
 * @package WCC\LiveTraffic
 */
class Controller extends AbstractComponentController
{
    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @Inject
     * @var SwitcherCore
     */
    protected $switcherCore;

    /**
     * @Inject
     * @var CacheInterface
     */
    protected $cache;

    /**
     * @Inject
     * @var DeviceInterfaceStorage
     */
    protected $deviceInterfaceStorage;

    /**
     * @var User
     */
    protected $user;


    function setUser(User $user)
    {
        $this->user = $user;
        return $this;
    }

    function getTraffic(DeviceInterface $iface)
    {
        if(!$this->isHasDeviceCountersModule($iface->getDevice())) {
            throw new ModuleNotFoundException("Device {$iface->getDevice()->getIp()} not supported module interface_counters");
        }
        $current = $this->getTrafficCounters($iface);
        $rate = [
             'in_bps' => 0,
             'out_bps' => 0,
        ];
        $interval = 0;
        if($oldData = $this->cache->get("COMPONENT:live_traffic:{$iface->getId()}")) {
            $interval = time() - $oldData['time'];
            if(!$interval) {
                $interval = 10;
            }
            if(
                $oldData['counters']['in_octets'] <= $current['in_octets'] &&
                $oldData['counters']['out_octets'] <= $current['out_octets']
            ) {
                $rate = [
                    'in_bps' => $current['in_octets'] > $oldData['counters']['in_octets'] ? round(($current['in_octets'] - $oldData['counters']['in_octets']) / $interval) * 8 : 0,
                    'out_bps' => $current['out_octets'] >  $oldData['counters']['out_octets'] ? round(($current['out_octets'] - $oldData['counters']['out_octets']) / $interval) * 8 : 0,
                ];
            }
        }

        $this->cache->set("COMPONENT:live_traffic:{$iface->getId()}", [
            'time' => time(),
            'counters' => $current,
        ], 600);
        return [
            'counters' => $current,
            'rate' => $rate,
            'interval' => $interval,
            'time' => date("H:i:s"),
        ];
    }

    protected function getTrafficCounters(DeviceInterface $iface) {

        $responses = $this->switcherCore->setUser($this->user)->fromDevice([
            (new Request())
               ->setDevice($iface->getDevice())
               ->setArguments(['interface' => $iface->getBindKey()])
               ->setModule('interface_counters')
        ]);
        $response = $responses->getFirstByModule('interface_counters');
        if($response->getError()) {
            throw new \Exception($response->getError()['message']);
        }
        $resp = $response->getData();
        if(count($resp) > 1) {
            throw new \Exception("Incorrect response by module interface_counters");
        } else if  (count($resp) === 0)  {
            throw new \Exception("Current device or interface not support counters");
        }
        unset($resp[0]['interface']);
        return $resp[0];
    }

    protected function isHasDeviceCountersModule(Device $device) {
        return  in_array('interface_counters', array_map(function ($m) { return $m['name']; }, $this->switcherCore->setUser($this->user)->getCore($device)->getModulesData()));
    }
}
