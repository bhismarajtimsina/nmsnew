<?php


namespace WCAA\Storage;


use Exception;
use InvalidArgumentException;
use Monolog\Logger;
use PDO;
use WCAA\App;
use DI\Annotation\Inject;
use WCAA\Infrastructure\Events\EventObserverStorage;
use WCAA\Interfaces\CacheInterface;
use WCAA\Storage\Exceptions\RecordNotFoundException;
use ReflectionClass;

/**
 * Class AbstractStorage
 * @package WCAA\Storage
 */
abstract class AbstractStorage implements StorageInterface
{
    /**
     * @Inject
     * @var App
     */
    protected $app;

    /**
     * @Inject
     * @var EventObserverStorage
     */
    protected $observerStorage;

    const NULL = "NULL";

    /**
     * @Inject
     * @var Logger
     */
    protected $logger;

    /**
     * @Inject
     * @var PDO
     */
    protected $pdo;


    /**
     * @Inject
     * @var CacheInterface
     */
    protected $cache;

    protected $tableName;

    function getTableName()
    {
        return $this->tableName;
    }

    private function getPropertiesDoc($model)
    {
        static $cache = [];
        $class = is_object($model) ? get_class($model) : $model;
        if (isset($cache[$class])) {
            return $cache[$class];
        }
        $reflect = new ReflectionClass($model);
        $properties = $reflect->getProperties();
        $props = [];
        foreach ($properties as $property) {
            $doc = $property->getDocComment();
            if ($doc && preg_match_all('/\@morm\.(.*)?=(.*)/', $doc, $matches)) {
                if (!isset($matches[2])) continue;
                foreach ($matches[1] as $key => $name) {
                    $value = $matches[2][$key];
                    $props[$property->getName()][$name] = trim($value);
                }
                if(!isset($props[$property->getName()]['name'])){
                    $props[$property->getName()]['name'] = $property->getName();
                }
            } elseif ($doc && preg_match('/\@morm/', $doc, $matches)) {
                $props[$property->getName()]['name'] = $property->getName();
            }
        }
        $cache[$class] = $props;
        return $props;
    }

    protected function getSQLFields($object)
    {
        $fields = $this->getPropertiesDoc($object);
        $return = [];
        foreach ($fields as $fieldName => $fieldVal) {
            if (isset($fieldVal['name'])) {
                $return[] = $fieldVal['name'];
            }
        }
        return $return;
    }

    protected function getPropertyBySQLname($object, $propertyName)
    {
        $fields = $this->getPropertiesDoc($object);
        foreach ($fields as $fieldName => $fieldVal) {
            if (isset($fieldVal['name']) && $fieldVal['name'] === $propertyName) {
                return $fieldName;
            }
        }
        return null;
    }
    protected function getPropertyType($object, $propertyName)
    {
        $fields = $this->getPropertiesDoc($object);
        foreach ($fields as $fieldName => $fieldVal) {
            if (isset($fieldVal['name']) && $fieldVal['name'] === $propertyName && isset($fieldVal['type'])) {
                return  $fieldVal['type'] ;
            }
        }
        return null;
    }

    protected function getObjectById($object, $id)
    {
        $obj = new $object;
        $obj->id = $id;

        return $this->fill($obj);
    }

    /**
     * Fill object by array
     *
     * @param $object
     * @param array $fetchArr
     */
    protected function fillByArr($object, $fetchArr = [])
    {
        $propsAssoc = [];
        foreach ($this->getPropertiesDoc($object) as $propName => $propData) {
            $propsAssoc[$propData['name']] = $propName;
        }
        foreach ($fetchArr as $k => $v) {
            if (isset($propsAssoc[$k])) {
                $k = $propsAssoc[$k];
            }
            //Detect json
            if ($this->isJson($v)) {
                $v = json_decode($v, JSON_PRETTY_PRINT);
            }

            $object->$k = $v;
        }
        return $object;
    }

    /**
     * @param $object
     * @return bool
     */
    protected function isCacheExist($object, $prefix = '') {
        if(!$object->getId()) return false;
        $hashName = "STORAGE:{$prefix}:" . get_class($object) . ":" . $object->getId();
        return $this->cache->isExist($hashName);
    }
    /**
     * @param $object
     * @return mixed
     */
    protected  function getCache($object, $prefix = '') {
        if(!$object->getId()) return false;
        $hashName = "STORAGE:{$prefix}:" . get_class($object) . ":" . $object->getId();
        return $this->cache->get($hashName);
    }

    /**
     * @param $object
     * @return bool
     */
    protected  function setCache($object, $prefix = '', $timeout = null) {
        if(!$object->getId()) return false;
        if(!$timeout) {
            $timeout = $this->app->conf('memcache.storage_timeout');
        }
        $hashName = "STORAGE:{$prefix}:" . get_class($object) . ":" . $object->getId();
        $this->cache->set($hashName, $object, $timeout);
        return  $object;
    }
    /**
     * @param $object
     * @return bool
     */
    protected  function clearCache($object, $prefix = '') {
        if(!$object->getId()) return false;
        $hashName = "STORAGE:{$prefix}:" . get_class($object) . ":" . $object->getId();
        $this->cache->delete($hashName);
        return  $object;
    }
    /**
     * @param $object
     * @return bool
     */
    protected  function clearByObject($object, $prefix = '') {
        $hashName = "STORAGE:{$prefix}:" . get_class($object);
        foreach ($this->cache->getAllKeys() as $key) {
            if(strpos($key, $hashName) !== false) {
                $this->cache->delete($key);
            }
        }
        return  true;
    }

    protected $_ids = null;

    /**
     * Cache key for this table's full id list (see fetchAllIds()) in the
     * shared cache backend - deliberately not namespaced by object id like
     * setCache()/getCache() are, since there's exactly one such list per
     * table.
     */
    protected function idsCacheKey() {
        return "STORAGE:IDS:" . static::class;
    }

    /**
     * Drops both the in-process id-list cache and the shared one.
     *
     * $this->_ids alone isn't enough under RoadRunner: workers are
     * long-lived and each keeps its own PHP object graph across many
     * requests, so a worker that had already cached $_ids for this table
     * keeps serving that stale list forever, even though add()/update()/
     * delete() all correctly null out *their own* worker's copy. A device
     * created via the worker that handled the POST becomes invisible on
     * GET /device to every other worker in the pool - the row exists
     * (fetchable by id) but never appears in the list. Routing the id list
     * through the already-shared CacheInterface (Memcache when enabled,
     * see app/dependencies.php) instead of a bare instance property fixes
     * that: flushIds() here now invalidates the copy every worker reads
     * from, not just the one instance that happened to run this request.
     */
    public function flushIds() {
        $this->_ids = null;
        $this->cache->delete($this->idsCacheKey());
        return $this;
    }

    /**
     * Build one object per cached id, skipping any row that no longer exists.
     *
     * fetchAllIds() caches into $this->_ids, and RoadRunner workers live across
     * many requests - so a row deleted by another worker (or directly in the
     * database) stays in this worker's cached id list. Filling it then throws
     * RecordNotFoundException. Where that happens inside a fetchAll() used by
     * the auth middleware, a single stale id turns every request into a 500.
     *
     * A row disappearing between listing ids and reading it is normal, not
     * exceptional: skip it and drop the id cache so the next call re-reads.
     *
     * @param callable $factory fn(mixed $id): object - builds the empty model
     * @return array
     */
    protected function fillAllFromIds(callable $factory)
    {
        $objects = [];
        $stale = false;
        foreach ($this->fetchAllIds() as $id) {
            try {
                $objects[] = $this->fill($factory($id));
            } catch (RecordNotFoundException $e) {
                $stale = true;
                $this->logger->warning(
                    "Skipping stale id={$id} in `{$this->tableName}` - row no longer exists, refreshing id cache"
                );
            }
        }
        if ($stale) {
            $this->flushIds();
        }
        return $objects;
    }

    protected function fetchAllIds($where = null, $orderBy = "id desc") {

        if(!$where && $this->_ids) {
            return  $this->_ids;
        }
        // Only the unconditional (whole-table) id list is worth sharing
        // across workers via the cache - a $where-scoped query is cheap
        // and specific enough that caching it per-condition isn't worth
        // the extra cache-key bookkeeping, so that path still always hits
        // the database, same as before.
        if(!$where) {
            $cached = $this->cache->get($this->idsCacheKey());
            if($cached !== null) {
                $this->_ids = $cached;
                return $cached;
            }
        }
        $start = microtime(true);
         if($where) {
             $psth = $this->pdo->prepare("SELECT id FROM `{$this->tableName}` WHERE $where order by  $orderBy");
             $log = "SELECT id FROM {$this->tableName} WHERE $where order by $orderBy";
         } else {
             $psth = $this->pdo->prepare("SELECT id FROM `{$this->tableName}` order by  $orderBy");
             $log = "SELECT id FROM `{$this->tableName}` order by  $orderBy";
         }
        $psth->execute();
        $resp = [];
        foreach ($psth->fetchAll() as $e) {
            $resp[] = $e['id'];
        }
        $this->_ids = $resp;
        if(!$where) {
            $this->cache->set($this->idsCacheKey(), $resp, $this->app->conf('memcache.storage_timeout'));
        }
        $this->logger->debug($log, ['spent'=> microtime(true) - $start]);
        return $resp;
    }

    /**
     * Fill object by select from database
     *
     * @param $object
     * @return mixed
     * @throws Exception
     */
    public function fill($object, $fillChildObjects = true)
    {
        $psth = null;
        $fields = $this->getSQLFields($object);
        $fields = array_map(function ($e) {
            return "`$e`";
        }, $fields);
        $selLine = join(',', $fields);
        if ($object->getId()) {
            if($this->isCacheExist($object)) {
                $cache = $this->getCache($object);
                if($cache) {
                    return $cache;
                }
            }
            $this->logger->debug("SELECT $selLine FROM {$this->tableName} WHERE id = ?", [$object->getId()]);
            $psth = $this->pdo->prepare("SELECT $selLine FROM {$this->tableName} WHERE id = ?");
            $psth->execute([$object->getId()]);
            if ($psth->rowCount() === 0) {
                $objName = get_class($object);
                throw (new RecordNotFoundException("Object {$objName} with table `{$this->tableName}` with id={$object->id} not found"))->setId($object->id)->setObjectName($objName);
            }
            $propsAssoc = [];
            foreach ($this->getPropertiesDoc($object) as $propName => $propData) {
                $propsAssoc[$propData['name']] = $propName;
            }
            foreach ($psth->fetchAll()[0] as $k => $v) {
                if (isset($propsAssoc[$k])) {
                    $k = $propsAssoc[$k];
                }
                //Detect json
                if ($this->isJson($v)) {
                    $v = json_decode($v, JSON_PRETTY_PRINT);
                }
                $object->$k = $v;
            }
            $this->setCache($object);
            return $object;
        }
        throw new InvalidArgumentException("Fill method for table $this->tableName require field id");
    }

    private function isJson($string)
    {
        json_decode($string);
        if(json_last_error() == JSON_ERROR_NONE) {
            if(
                strpos($string,"]") !== false ||
                strpos($string,"{") !== false ||
                strpos($string,"\"") !== false
            ) {
                return true;
            }
        }
        return false;
    }

    public function add($object)
    {
        $this->_ids = null;
        $fields = [];
        $values = [];
        foreach ($this->getSQLFields($object) as $field) {
            $valueName = $this->getPropertyBySQLname($object, $field);
            $value = $object->$valueName;
            if (is_array($value)) {
                if(!$value) {
                    $type = $this->getPropertyType($object, $field);
                    if($type === 'object') {
                        $value = new \stdClass();
                    }
                }
                $value = json_encode($value, JSON_NUMERIC_CHECK | JSON_UNESCAPED_UNICODE);
            }
            if ($value === null) continue;
            if(is_bool($value)) {
                $value = $value ? 1 : 0;
            }
            $values[$field] = $value;
            $fields[$field] = "`$field`";

        }
        ksort($fields);
        ksort($values);
        $values = array_values($values);
        $fields = array_values($fields);

        $mapper = trim(str_repeat("?,", count($fields)), ",");
        $selLine = join(',', $fields);
        $this->logger->debug("INSERT INTO {$this->tableName} ($selLine) VALUES ($mapper)", array_map(function ($e) {
            if(strlen($e) > 50) {
                return substr($e, 0, 40) . "...";
            }
            return  $e;
        },$values));
        $psth = $this->pdo->prepare("INSERT INTO {$this->tableName} ($selLine) VALUES ($mapper)");
        $psth->execute($values);
        $object->id = $this->pdo->lastInsertId();
        $this->clearCache($object);
        $this->flushIds();
        // Generic real-time signal for every storage that extends this class
        // (devices, links, macros, users, events, ...). Reaches WebSocket
        // clients via the existing EventObserverStorage -> EventLogToRedisQueue
        // -> wca-ws pipeline as channel "event:storage:{table}:added".
        // Passing $object itself (not a constructed array) matters:
        // EventLogToRedisQueue::notify() calls ->getAsArray() automatically
        // whenever the top-level $data is an AbstractModel — same mechanism
        // DeviceStorage's own "device:added" already relies on — so this
        // carries the full record, not just an id. Resources with their own
        // richer named event still fire both; harmless, the frontend just
        // prefers the specific one. Access to this channel is gated by role
        // permission server-side (config/ws-permissions.yml), same as REST.
        $this->observerStorage->notify("storage:{$this->tableName}:added", $object);
        return $this->fill($object);
    }

    public function update($object)
    {
        $query = "UPDATE {$this->tableName} SET ";

        $values = [];
        foreach ($this->getSQLFields($object) as $field) {
            $valueName = $this->getPropertyBySQLname($object, $field);
            $value = $object->$valueName;
            if($field == 'updated_at') {
                $value = date("Y-m-d H:i:s");
            }
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            }
            if(is_bool($value)) {
                $value = $value ? 1 : 0;
            }
            if ($value === null) continue;
            $values[] = $value;
            $query .= "`$field` = ?, ";
        }
        $values[] = $object->getId();
        $query = trim($query, ", ");
        $query .= " WHERE id = ?";
        $this->logger->debug($query, $values);
        $psth = $this->pdo->prepare($query);
        $psth->execute($values);
        $this->clearCache($object);
        $this->flushIds();
        // See add()'s comment — same generic real-time signal, "updated",
        // same full-record payload via getAsArray().
        $this->observerStorage->notify("storage:{$this->tableName}:updated", $object);

        return $this->fill($object);
    }

    public function delete($object)
    {
        $this->_ids = null;
        $this->logger->debug("DELETE FROM {$this->tableName} WHERE id = ?", [$object->getId()]);
        $psth = $this->pdo->prepare("DELETE FROM {$this->tableName} WHERE id = ?");
        $psth->execute([$object->getId()]);
        $this->clearCache($object);
        $this->flushIds();
        // See add()'s comment — same generic real-time signal, "deleted".
        // The frontend only needs .id out of this one, but passing the full
        // object costs nothing and stays consistent with add()/update().
        $this->observerStorage->notify("storage:{$this->tableName}:deleted", $object);
        return $this;
    }

    protected function getOneIdByWhere($condition, $params = [])
    {
        $this->logger->debug("SELECT id FROM {$this->tableName} WHERE $condition", $params);
        $psth = $this->pdo->prepare("SELECT id FROM {$this->tableName} WHERE $condition");
        $psth->execute($params);
        if ($psth->rowCount() === 0) {
            return null;
        }
        return (int)$psth->fetchAll()[0]['id'];
    }

    function updateOnDuplicate($object) {
        $fieldsInsert = [];
        $valuesInsert = [];
        $valuesUpdate = [];
        $updateQuery = '';
        foreach ($this->getSQLFields($object) as $field) {
            $valueName = $this->getPropertyBySQLname($object, $field);
            $value = $object->$valueName;
            if (is_array($value)) {
                $value = json_encode($value, JSON_NUMERIC_CHECK | JSON_UNESCAPED_UNICODE);
            }
            if(is_bool($value)) {
                $value = $value ? 1 : 0;
            }
            if ($value === null) continue;
            $valuesInsert[$field] = $value;
            $fieldsInsert[$field] = "`$field`";
            $valuesUpdate[] = $value;
            $updateQuery .= "`$field` = ?, ";
        }
        $updateQuery = trim($updateQuery, ", ");
        ksort($fieldsInsert);
        ksort($valuesInsert);
        $valuesInsert = array_values($valuesInsert);
        $fieldsInsert = array_values($fieldsInsert);

        $values = array_merge($valuesInsert, $valuesUpdate);

        $mapper = trim(str_repeat("?,", count($fieldsInsert)), ",");
        $selLine = join(',', $fieldsInsert);
        $this->logger->debug("INSERT INTO {$this->tableName} ($selLine) VALUES ($mapper) 
                ON DUPLICATE KEY UPDATE $updateQuery", $values);
        $psth = $this->pdo->prepare("INSERT INTO {$this->tableName} ($selLine) VALUES ($mapper) 
                ON DUPLICATE KEY UPDATE $updateQuery");

        $psth->execute($values);
        if($id = $this->pdo->lastInsertId()) {
            $object->id = $id;
        }
        $this->clearCache($object);
        // See add()'s comment — same generic real-time signal. This is an
        // upsert (INSERT ... ON DUPLICATE KEY UPDATE), so neither "added"
        // nor "updated" is strictly accurate — "upserted" instead. Used by
        // PingerDeviceStatusStorage (continuous ICMP status) and
        // ConsoleHistoryStorage; callers on a tight loop (like pinger)
        // should debounce on the frontend rather than expect a rare event.
        $this->observerStorage->notify("storage:{$this->tableName}:upserted", $object);
        return $this->fill($object);
    }

    function begin() {
        $this->pdo->beginTransaction();
        return $this;
    }
    function rollback() {
        $this->pdo->rollBack();
        return $this;
    }
    function commit() {
        $this->pdo->commit();
        return $this;
    }

    function count($condition = "") {
        if($condition) {
            $condition = "WHERE $condition";
        }
        $data = $this->pdo->prepare("SELECT count(*) c FROM `{$this->tableName}` {$condition}");
        $data->execute();
        $count =  0;
        foreach ($data->fetchAll() as $f) {
            $count = $f['c'];
        }
        return $count;
    }
}
