<?php

namespace WCC\Paths\Storage;

use WCAA\Models\Devices\Device;
use WCAA\Storage\AbstractStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\Paths\Models\Path;

class PathStorage extends AbstractStorage
{
    protected $tableName = 'c_paths';

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @Inject
     * @var PathSegmentStorage
     */
    protected $segmentStorage;

    /**
     * @Inject
     * @var PathStateStorage
     */
    protected $stateStorage;

    /**
     * @param Path $object
     * @param bool $fillChildObjects
     * @return Path
     */
    public function fill($object, $fillChildObjects = true)
    {
        /** @var Path $object */
        $object = parent::fill($object);
        if (!$fillChildObjects) {
            return $object;
        }
        return $this->fillRelations($object);
    }

    /**
     * @param Path $path
     * @return Path
     */
    public function fillRelations(Path $path)
    {
        $path->setEndpointA($this->safeDevice($path->getEndpointAId()));
        $path->setEndpointB($this->safeDevice($path->getEndpointBId()));
        $path->setSegments($this->segmentStorage->getByPath($path->getId()));
        $path->setState($this->stateStorage->getByPathId($path->getId()));
        return $path;
    }

    /**
     * A path referencing a removed device should not take the whole list down.
     *
     * @param int|null $deviceId
     * @return Device|null
     */
    private function safeDevice($deviceId)
    {
        if (!$deviceId) {
            return null;
        }
        try {
            return $this->deviceStorage->fill(new Device($deviceId));
        } catch (\Throwable $e) {
            $this->logger->warning("Path references missing device id={$deviceId} - {$e->getMessage()}");
            return null;
        }
    }

    /**
     * @return Path[]
     */
    public function fetchAll($onlyEnabled = false, $fillChildObjects = true)
    {
        $sql = "SELECT * FROM `{$this->tableName}` ";
        if ($onlyEnabled) {
            $sql .= " WHERE enabled = 1 ";
        }
        $sql .= " ORDER BY group_key, priority, id";

        $response = [];
        foreach ($this->pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $path = $this->fillByArr(new Path($row['id']), $row);
            if ($fillChildObjects) {
                $path = $this->fillRelations($path);
            }
            $response[] = $path;
        }
        return $response;
    }

    /**
     * @param int $id
     * @return Path|null
     */
    public function getById($id)
    {
        $psth = $this->pdo->prepare("SELECT * FROM `{$this->tableName}` WHERE id = ?");
        $psth->execute([$id]);
        if ($psth->rowCount() === 0) {
            return null;
        }
        $row = $psth->fetch(\PDO::FETCH_ASSOC);
        return $this->fillRelations($this->fillByArr(new Path($row['id']), $row));
    }

    /**
     * All enabled paths that share a group key, ordered by priority.
     *
     * @return array<string, Path[]>
     */
    public function fetchGroupedByKey()
    {
        $grouped = [];
        foreach ($this->fetchAll(true) as $path) {
            //Ungrouped paths stand alone - key them by id so they never appear
            //to protect one another.
            $key = $path->getGroupKey() ?: ('__single_' . $path->getId());
            $grouped[$key][] = $path;
        }
        return $grouped;
    }

    public function add($object)
    {
        /** @var Path $object */
        $object->setUpdatedAt(date("Y-m-d H:i:s"));
        return parent::add($object);
    }

    public function update($object)
    {
        /** @var Path $object */
        $object->setUpdatedAt(date("Y-m-d H:i:s"));
        return parent::update($object);
    }
}
