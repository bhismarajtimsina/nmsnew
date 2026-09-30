<?php

namespace WCAA\Storage\Devices;

use WCAA\App;
use WCAA\Infrastructure\PrometheusMetrics;
use WCAA\Models\Devices\Device;
use WCAA\Models\Devices\DeviceInterface;
use WCAA\Models\Devices\DeviceInterfaceTag;
use WCAA\Storage\AbstractStorage;

class DeviceInterfaceTagStorage extends AbstractStorage
{
    /**
     * @Inject
     * @var DeviceInterfaceStorage
     */
    protected $deviceInterfaceStorage;

    protected $tableName = 'device_interfaces_tags';

    protected $_ids = [];

    protected $_ifacesTags = [];
    protected $_ifacesStarred = [];

    protected $timeout = 600;

    /**
     * @Inject
     * @var PrometheusMetrics
     */
    protected $prometheusMetrics;

    /**
     * Returning array of tags(strings) by interface
     *
     * @param DeviceInterface $deviceInterface
     * @return array|mixed
     */
    function getInterfaceTags(DeviceInterface $deviceInterface)
    {
        if (isset($this->_ifacesTags[$deviceInterface->getId()])) {
            return $this->_ifacesTags[$deviceInterface->getId()];
        }
        if ($tags = $this->cache->get("STORAGE:IFACE_TAGS:{$deviceInterface->getId()}")) {
            return $tags;
        }
        $psth = $this->pdo->prepare("SELECT value FROM device_interfaces_tags WHERE interface_id = ? and type = 'TAG'");
        $psth->execute([$deviceInterface->getId()]);
        $tags = array_map(function ($e) {
            return $e['value'];
        }, $psth->fetchAll());
        $this->_ifacesTags[$deviceInterface->getId()] = $tags;
        $this->cache->set("STORAGE:IFACE_TAGS:{$deviceInterface->getId()}", $tags, $this->timeout);
        return $tags;
    }

    /**
     * Returning interface status
     *
     * @param DeviceInterface $deviceInterface
     * @return bool
     */
    function isInterfaceFavorite(DeviceInterface $deviceInterface)
    {
        if (isset($this->_ifacesStarred[$deviceInterface->getId()])) {
            return $this->_ifacesStarred[$deviceInterface->getId()];
        }
        if ($favorite = $this->cache->get("STORAGE:IFACE_FAVORITE:{$deviceInterface->getId()}")) {
            return $favorite;
        }
        $psth = $this->pdo->prepare("SELECT interface_id FROM device_interfaces_tags WHERE interface_id = ? and type = 'FAVORITE'");
        $psth->execute([$deviceInterface->getId()]);
        $favorite = false;
        if ($psth->rowCount() > 0) {
            $favorite = true;
        }
        $this->_ifacesStarred[$deviceInterface->getId()] = $favorite;
        $this->cache->set("STORAGE:IFACE_FAVORITE:{$deviceInterface->getId()}", $favorite, $this->timeout);
        return $favorite;
    }

    /**
     * Update tags by interface
     *
     * @param DeviceInterface $device
     * @param $tags
     * @return array
     */
    function setTags(DeviceInterface $device, $tags = [])
    {
        $tags = array_map(function ($tag) {
            $tag = str_replace([" ", '-', '"', ",", "#", ';'], '', $tag);
            return trim($tag);
        }, $tags);
        $this->pdo->beginTransaction();
        $psth = $this->pdo->prepare("DELETE FROM device_interfaces_tags WHERE interface_id = ? and type = 'TAG'");
        $psth->execute([$device->getId()]);

        $psth = $this->pdo->prepare("INSERT INTO device_interfaces_tags (interface_id, type, value) VALUES (?, 'TAG', ?)");
        foreach ($tags as $tag) {
            if (!$tag) continue;
            $psth->execute([$device->getId(), $tag]);
        }
        $this->promUpdateTagMetric($device, $tags);
        $this->pdo->commit();
        $this->cache->delete("STORAGE:IFACE_TAGS:{$device->getId()}");
        unset($this->_ifacesTags[$device->getId()]);
        return $this->getInterfaceTags($device);
    }

    /**
     * Set interface favorite
     *
     * @param DeviceInterface $iface
     * @param $isStarred
     * @return bool
     */
    function setFavoriteStatusByInterface(DeviceInterface $iface, $isStarred = false)
    {
        if ($isStarred) {
            $this->_ifacesStarred[$iface->getId()] = true;
            $this->cache->set("STORAGE:IFACE_FAVORITE:{$iface->getId()}", true);
            $this->pdo->prepare("INSERT IGNORE INTO device_interfaces_tags (interface_id, type, value) VALUES (?, 'FAVORITE', 'true')")->execute([$iface->getId()]);
            $this->promSetInterfaceStarred($iface);
        } else {
            $this->_ifacesStarred[$iface->getId()] = false;
            $this->cache->set("STORAGE:IFACE_FAVORITE:{$iface->getId()}", false);
            $this->pdo->prepare("DELETE FROM device_interfaces_tags WHERE interface_id = ? and type = 'FAVORITE'")->execute([$iface->getId()]);
        }
        return $isStarred;
    }

    /**
     * Get favorite interfaces by device
     *
     * @param Device $device
     * @return DeviceInterface[]
     */
    function getFavoriteInterfacesByDevice(Device $device)
    {
        $ifaces = $this->deviceInterfaceStorage->getByDevice($device, null, false);
        foreach ($ifaces as $k => $v) {
            if (!$this->isInterfaceFavorite($v)) {
                unset($ifaces[$k]);
            }
        }
        return array_values($ifaces);
    }

    /**
     * Get favorite interfaces by device
     *
     * @param Device $device
     * @return DeviceInterface[]
     */
    function getTaggedInterfacesByDevice(Device $device)
    {
        $ifaces = $this->deviceInterfaceStorage->getByDevice($device, null, false);
        $respond = [];
        foreach ($ifaces as $v) {
            $tags = $this->getInterfaceTags($v);
            if (!$tags) continue;
            $respond[] = [
                'interface' => $v,
                'tags' => $tags,
            ];
        }
        return array_values($respond);
    }

    /**
     * @param $obj
     * @return DeviceInterfaceTag
     */
    function fill($obj, $fillParentObjects = false)
    {
        $p = parent::fill($obj);
        $p->setInterface($this->deviceInterfaceStorage->getById($p->interface_id));
        return $p;
    }

    /**
     * Returning all objects
     *
     * @return DeviceInterfaceTag[]
     */
    function getAll()
    {
        return array_map(function ($i) {
            return $this->fill($i, true);
        }, $this->fetchAllIds());
    }

    /**
     * Returning list of favorite interfaces
     *
     * @return DeviceInterface[]
     */
    function getFavoriteInterfaces()
    {
        return array_map(function ($i) {
            $filled = $this->fill(new DeviceInterfaceTag($i));
            return $filled->getInterface();
        }, $this->fetchAllIds("type = 'FAVORITE'"));
    }

    /**
     * Returning list of tags from all interfaces
     *
     * @return array
     */
    function getExistedTags($query = '')
    {
        $where = '';
        if ($query) {
            $query = $this->pdo->quote($query . '%');
            $where = "and value like $query";
        }
        $psth = $this->pdo->prepare("SELECT max(id) id, value FROM device_interfaces_tags WHERE type = 'TAG' {$where} GROUP BY value order by  id desc");
        $psth->execute();
        return array_map(function ($v) {
            return $v['value'];
        }, $psth->fetchAll());
    }

    /**
     * Return map[string] => [
     *      'interface' => DeviceInterface,
     *      'tags' => []string,
     * ]
     *
     * @return array
     */
    function getTagsByInterfaces()
    {
        $psth = $this->pdo->prepare("SELECT interface_id, value FROM device_interfaces_tags WHERE type = 'TAG'");
        $psth->execute();
        $ifaces = [];
        foreach ($psth->fetchAll() as $vl) {
            $iface = $this->deviceInterfaceStorage->getById($vl['interface_id']);
            $ifaces[$iface->getId()]['interface'] = $iface;
            $ifaces[$iface->getId()]['tags'][] = $vl['value'];
        }
        return array_values($ifaces);
    }

    /**
     * Return map[string] => [
     *      'interface' => DeviceInterface,
     *      'tags' => []string,
     * ]
     *
     * @return array
     */
    function searchInterfacesByTagLike($tag = '')
    {
        $psth = $this->pdo->prepare("SELECT interface_id, value FROM device_interfaces_tags WHERE type = 'TAG' and value like ?");
        $psth->execute(["%{$tag}%"]);
        $ifaces = [];
        foreach ($psth->fetchAll() as $vl) {
            $iface = $this->deviceInterfaceStorage->getById($vl['interface_id']);
            $ifaces[$iface->getId()]['interface'] = $iface;
            $ifaces[$iface->getId()]['tags'][] = $vl['value'];
        }
        return array_values($ifaces);
    }

    function promSetInterfaceStarred(DeviceInterface $iface)
    {
        $this->prometheusMetrics->setGauge("storage_iface_favorite", 1, [
            'dev_id' => $iface->getDevice()->getId(),
            'ip' => $iface->getDevice()->getIp(),
            'iface_type' => $iface->getType(),
            'iface_id' => $iface->getBindKey(),
            'iface_name' => $iface->getName(),
        ], "Favorite interfaces", 180);
    }

    function promUpdateTagMetric(DeviceInterface $iface, $tags)
    {
        $tags = join(",", $tags);
        $this->prometheusMetrics->setGauge("storage_iface_tags", 1, [
            'tags' => $tags,
            'dev_id' => $iface->getDevice()->getId(),
            'ip' => $iface->getDevice()->getIp(),
            'iface_type' => $iface->getType(),
            'iface_id' => $iface->getBindKey(),
            'iface_name' => $iface->getName(),
        ], "Interface tags", 180);
    }

    function __construct()
    {
        $this->timeout = App::getInstance()->conf('memcache.storage_timeout');
    }

}