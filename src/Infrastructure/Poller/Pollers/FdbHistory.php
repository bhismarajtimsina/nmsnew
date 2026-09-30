<?php

namespace WCAA\Infrastructure\Poller\Pollers;

use WCAA\App;
use WCAA\Infrastructure\Poller\Interfaces\PollerFdbInterface;
use WCAA\Infrastructure\Poller\PollerAbstract;
use WCAA\Infrastructure\Poller\PollerInterface;
use WCAA\Models\Devices\Device;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\PollerData\FdbHistoryStorage;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCC\Links\Storage\LinkStorage;

class FdbHistory extends PollerAbstract implements PollerInterface
{
    /**
     * @var FdbHistoryStorage
     */
    protected $storage;

    /**
     * @var DeviceInterfaceStorage
     */
    protected $deviceInterfaceStorage;

    /**
     * @var LinkStorage
     */
    protected $linkStorage = null;

    /**
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @param FdbHistoryStorage $storage
     */
    function __construct(FdbHistoryStorage $storage, DeviceStorage $deviceStorage, DeviceInterfaceStorage $interfaceStorage, App $app)
    {
        $this->storage = $storage;
        $this->deviceStorage = $deviceStorage;
        $this->deviceInterfaceStorage = $interfaceStorage;
        if($app->getComponentInjector()->isComponentEnabled('links')) {
            $this->linkStorage = $app->getContainer()->get(LinkStorage::class);
        }
    }

    function setManual(Device $device, $data = [])
    {
        $this->sync($device, $data);
        $this->notifyPolledNow($device, 'fdb_table');
    }

    function setManualByInterface(Device $device, $data = [])
    {
        $this->sync($device, $data, false);
    }

    function sync(Device $device, $fdbTable, $checkNotExists = true)
    {
        $excludedLinks = array_merge(
            $this->getExcludedInterfacesByLinks($device),
            $this->getExcludedInterfacesBySystemDevices($fdbTable)
        );
        $currentFDB = [];
        $foundIfaces = [];
        foreach ($fdbTable as $fdb) {
            if (!$fdb['vlan_id']) $fdb['vlan_id'] = 0;

            if(isset($excludedLinks[$fdb['interface']['id']])) {
                continue;
            }
            $currentFDB["{$fdb['mac_address']}.{$fdb['vlan_id']}.{$fdb['interface']['id']}"] = $fdb;
            $foundIfaces[$fdb['interface']['id']] = $fdb['interface']['id'];
        }
        $fdbInStorage = [];
        if (count($foundIfaces) > 1) {
            foreach ($this->storage->getByDevice($device, true) as $fdb) {
                $fdbInStorage["{$fdb->getMacAddress()}.{$fdb->getVlanId()}.{$fdb->getInterface()->getBindKey()}"] = $fdb;
            }
        } else if (count($foundIfaces) === 1) {
            $iface = array_values($foundIfaces)[0];
            try {
                foreach ($this->storage->getByDeviceAndInterfaceBindKey($device, $iface, true) as $fdb) {
                    $fdbInStorage["{$fdb->getMacAddress()}.{$fdb->getVlanId()}.{$fdb->getInterface()->getBindKey()}"] = $fdb;
                }
            } catch (\Exception $e) {
                if ($this->logger) $this->logger->warning("Interface not found in storage", ['device' => $device->getAsArray(), 'iface' => $iface]);
            }
        }

        //Check added new FDB
        foreach ($currentFDB as $fdb) {
            if (isset($fdbInStorage["{$fdb['mac_address']}.{$fdb['vlan_id']}.{$fdb['interface']['id']}"])) {
                continue;
            }
            try {
                $interface = $this->deviceInterfaceStorage->getByDeviceAndKey($device, $fdb['interface']['id']);
                if(isset($interface->getParams()['disable_saving_fdb']) && $interface->getParams()['disable_saving_fdb']) {
                    continue;
                }
                $this->storage->addOrUpdate(
                    (new \WCAA\Models\Pollers\FdbHistory())
                        ->setDevice($device)
                        ->setInterface($interface)
                        ->setStartAt(date("Y-m-d H:i:s"))
                        ->setMacAddress($fdb['mac_address'])
                        ->setVlanId($fdb['vlan_id'])
                );
            } catch (\Throwable $e) {
                $this->logger->warning("Interface not found in storage for fdb", ['device' => $device->getAsArray(), 'fdb' => $fdb]);
            }
        }

        //Check mac not exist
        if ($checkNotExists) {
            foreach ($fdbInStorage as $fdb) {
                if (isset($currentFDB["{$fdb->getMacAddress()}.{$fdb->getVlanId()}.{$fdb->getInterface()->getBindKey()}"])) {
                    continue;
                }
                $this->storage->update($fdb->setStopAt(date("Y-m-d H:i:s")));
            }
        }

        //Clear oldFDB by links
        $this->clearOldFDBByLinks($device);

        //Clear FDB by disabled_saving_fdb
        $this->clearByDisabledIfaces($device);
    }

    function poll(Device $device, $controller)
    {
        if (!$controller instanceof PollerFdbInterface) {
            throw new \Exception("Controller " . get_class($controller) . " not implemented PollerFdbInterface");
        }
        $this->sync($device, $controller->getFdbTable());
    }

    /**
     * Этот метод получает мак-адреса всех устройств в системе и возвращает список портов (массив с ключами интерфейсов)
     * которые нужно исключить на основе наличия хоть одного мак-адреса
     * Таким образом мы исключаем транспортные порты
     *
     * @param $currentFDB
     * @return array
     */
    function getExcludedInterfacesBySystemDevices($currentFDB)
    {
       $allDeviceMacAddresses = $this->deviceStorage->getUniqDeviceMacAddresses();
       $excludedInterfaces = [];
       foreach ($currentFDB as $fdb) {
           if(isset($allDeviceMacAddresses[$fdb['mac_address']])) {
               $excludedInterfaces[$fdb['interface']['id']] = true;
           }
       }
       return $excludedInterfaces;
    }

    function getExcludedInterfacesByLinks(Device $device)
    {
        $links = [];
        if(!$this->linkStorage) {
            return $links;
        }
        foreach ($this->linkStorage->getByDestDevice($device) as $link) {
            if($link->getDestIface()) {
                $links[$link->getDestIface()->getBindKey()] = $link;
            }
        }
        foreach ($this->linkStorage->getBySourceDevice($device) as $link) {
            if($link->getSrcIface()) {
                $links[$link->getSrcIface()->getBindKey()] = $link;
            }
        }
        return  $links;
    }

    function clearByDisabledIfaces(Device $device)
    {
        $fdb = $this->storage->getByDevice($device);
        foreach ($fdb as $f) {
            if(isset($f->getInterface()->getParams()['disable_saving_fdb']) && $f->getInterface()->getParams()['disable_saving_fdb']) {
                $this->storage->delete($f, false);
            }
        }
        $this->storage->flushIds();
    }

    function clearOldFDBByLinks(Device $device)
    {
        if(!$this->linkStorage) return;
        foreach ($this->linkStorage->getByDestDevice($device) as $link) {
            if($link->getDestIface()) {
                $fdbs = $this->storage->getByInterface($link->getDestIface());
                foreach ($fdbs as $fdb) {
                    $this->storage->delete($fdb);
                }
            }
        }
        foreach ($this->linkStorage->getBySourceDevice($device) as $link) {
            if($link->getSrcIface()) {
                $fdbs = $this->storage->getByInterface($link->getSrcIface());
                foreach ($fdbs as $fdb) {
                    $this->storage->delete($fdb);
                }
            }
        }
        return;
    }
}