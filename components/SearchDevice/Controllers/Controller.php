<?php


namespace WCC\SearchDevice\Controllers;


use SwitcherCore\Config\ModelCollector;
use WCAA\Infrastructure\Components\AbstractComponentController;
use WCAA\Models\Pollers\FdbHistory;
use WCAA\Models\Devices\Device;
use WCAA\Storage\PollerData\FdbHistoryStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\SwitcherCore\Request;
use WCAA\SwitcherCore\SwitcherCore;

/**
 * Class Controller
 * @package WCC\SearchDevice
 */
class Controller extends AbstractComponentController
{

    /**
     * @Inject
     * @var FdbHistoryStorage
     */
    protected $fdbHistory;

    /**
     * @Inject
     * @var ModelCollector
     */
    protected $modelCollector;

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
     * @param Device[] $devices
     * @param $mac
     * @return array
     * @throws \ErrorException
     */
    function searchMac(array $devices, string $mac, $vlanId = null)
    {
        $data = [];
        foreach (explode(',', _env('SEARCH_DEVICE_SOURCES', 'device,history')) as $source) {
            switch ($source) {
                case 'history':
                    $data = array_merge($data, $this->searchInHistory($devices, $mac, $vlanId));
                    break;
                case 'device':
                    $data = array_merge($data, $this->searchOnDevice($devices, $mac, $vlanId));
                    break;
                default:
                    throw new \Exception("Incorrect source, support only history|device");
            }
            if ($data && !_env('SEARCH_DEVICE_CHECK_ALL_SOURCES', false)) {
                return $data;
            }
        }
        return $data;
    }

    /**
     * @param Device[] $routers
     * @param $searchIP
     * @return array
     */
    function searchIp(array $routers, $searchIP) {
        $return = [];
        $requests = [];
        foreach ($routers as $router) {
            $modules = $this->modelCollector->getModelByKey($router->getModel()->getKey())->getModulesList();
            if(in_array('arp_info', $modules)) {
                $requests[] = (new Request())
                    ->setModule('arp_info')
                    ->setDevice($router)
                    ->setArguments(['ip' => $searchIP]);
            }
        }
        $responseCollector = $this->switcherCore->fromDeviceMultiCall($requests);
        foreach ($requests as $req) {
            try {
                $response = $responseCollector->findByRequest($req);
                if($err = $response->getError()) {
                    throw new \Exception($err['description']);
                }
                $return = array_merge($return, $response->getData());
            } catch (\Throwable $e) {
                $this->logger->error($e->getMessage(), ['device'=>$req->getDevice()->getAsArray(), 'error'=>$e->getMessage(), 'error_trace' => $e->getTraceAsString()]);
            }
        }
        return $return;
    }


    /**
     * @param Device[] $devices
     * @param string $mac
     * @return array
     */
    protected function searchInHistory(array $devices, string $mac, $vlanId = null)
    {
        /**
         * @var FdbHistory[] $macAddresses
         */
        $macAddresses = $this->fdbHistory->getByMac($mac, _env('SEARCH_MAC_HISTORY_ONLY_ACTIVE', true));
        $macAddresses = array_filter($macAddresses, function ($e) use ($devices, $vlanId) {
            if($vlanId && (int)$vlanId !== (int)$e->getVlanId()) {
                return  false;
            }
            foreach ($devices as $dev) {
                if ($dev->getId() === $e->getDevice()->getId()) {
                    return true;
                }
            }
            return false;
        });
        $RETURN = [];
        foreach ($macAddresses as $macAddress) {
            $RETURN[] = [
                'vlan_id' => $macAddress->getVlanId(),
                'mac' => $macAddress->getMacAddress(),
                'device' => $macAddress->getInterface()->getDevice()->getAsArray(),
                'interface' => [
                    'id' => $macAddress->getInterface()->getBindKey(),
                    'name' => $macAddress->getInterface()->getName(),
                ],
                'source' => 'history',
            ];
        }
        return $RETURN;
    }

    /**
     * @param Device[] $devices
     * @param string $mac
     * @return array
     * @throws \ErrorException
     */
    protected function searchOnDevice(array $devices, string $mac, $vlanId = null)
    {
        $requests = $this->prepareSwitcherCoreRequestsForMacSearching($devices, $mac, $vlanId);
        $responses = $this->switcherCore->fromDeviceMultiCall($requests, _env('SEARCH_DEVICE_REQUEST_CONCURRENCY', 50));
        $return = [];
        foreach ($devices as $dev) {
            try {
                $disabledInterfaces = [];
                $modules = $this->modelCollector->getModelByKey($dev->getModel()->getKey())->getModulesList();
                $respByDev = $responses->filterByDevice($dev);
                if (_env('SEARCH_DEVICE_IGNORE_TAG_PORTS', true) && in_array('vlans_by_port', $modules)) {
                    $vlans = $respByDev->getFirstByModule('vlans_by_port');

                    if ($vlans->getError()) {
                        $this->logger->error("Error get vlans for device {$dev->getIp()} ({$dev->getName()})", ['error' => $vlans->getError(), 'mac' => $mac, 'device' => $dev]);
                        throw new \Exception($vlans->getError()['description']);
                    }
                    foreach ($vlans->getData() as $iface) {
                        if (isset($iface['tagged']) && $iface['tagged']) {
                            $disabledInterfaces[] = $iface['interface']['id'];
                        }
                    }
                }
                if (in_array('fdb', $modules)) {
                    $fdb = $respByDev->getFirstByModule('fdb');
                    if ($fdb->getError()) {
                        $this->logger->error("Error get FDB for device {$dev->getIp()} ({$dev->getName()})", ['error' => $vlans->getError(), 'mac' => $mac, 'device' => $dev]);
                        throw new \Exception($fdb->getError()['description']);
                    }
                    $fdbData = array_filter($fdb->getData(), function ($e) use ($mac, $disabledInterfaces, $vlanId) {
                      if(in_array($e['interface']['id'], $disabledInterfaces)) return false;
                      if($vlanId && (int)$vlanId != (int)$e['vlan_id']) return false;

                      return $mac == $e['mac_address'];
                    });
                    foreach ($fdbData as $f) {
                        $f['source'] = 'device';
                        $f['mac'] = $f['mac_address'];
                        unset($f['mac_address']);
                        $f['device'] = $dev->getAsArray();
                        $return[] = $f;
                    }
                } else {
                    throw new \Exception("Device not supported module 'fdb' {$dev->getIp()}");
                }
            } catch (\Throwable $e) {
                $this->logger->error("Error scrab info from device {$dev->getIp()}", ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString(), 'device' => $dev]);
            }
        }
        return $return;
    }

    /**
     * @param Device[] $devices
     * @return \WCAA\SwitcherCore\Request[]
     */
    protected function prepareSwitcherCoreRequestsForMacSearching(array $devices, $mac, $vlanId = null)
    {
        $requests = [];
        foreach ($devices as $dev) {
            $modules = $this->modelCollector->getModelByKey($dev->getModel()->getKey())->getModulesList();
            if (in_array('fdb', $modules)) {
                $requests[] = (new \WCAA\SwitcherCore\Request())->setDevice($dev)->setModule('fdb')->setArguments(['mac' => $mac, 'vlan_id' => $vlanId]);
            }
            if (_env('SEARCH_MAC_IGNORE_TAG_PORTS', true) && in_array('vlans_by_port', $modules)) {
                $requests[] = (new \WCAA\SwitcherCore\Request())->setDevice($dev)->setModule('vlans_by_port')->setArguments(['mac' => $mac]);
            }
        }
        return $requests;
    }
}
