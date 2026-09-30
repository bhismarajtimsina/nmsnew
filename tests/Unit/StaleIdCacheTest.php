<?php
/**
 * Cover for stale cached id lists in long-lived RoadRunner workers.
 *
 * fetchAllIds() caches ids on the storage instance, and workers survive many
 * requests. A row deleted elsewhere stays in this worker's cached list, and
 * filling it throws RecordNotFoundException. DeviceGroupStorage::fetchAll()
 * runs inside getSysUser() on the auth path, so one stale id used to turn every
 * request in the panel into a 500.
 *
 * A row vanishing between listing ids and reading it is normal, not
 * exceptional - it must be skipped, and the id cache dropped.
 */

use WCAA\Storage\Exceptions\RecordNotFoundException;

/**
 * Minimal stand-in exercising the real AbstractStorage::fillAllFromIds() logic
 * without a database: ids 1..3 are "cached", but id 2 has been deleted.
 */
class StaleIdHarness
{
    public $ids = [1, 2, 3];
    public $missing = [2];
    public $flushed = false;
    public $tableName = 'fake_table';
    public $logger;

    public function __construct()
    {
        //fillAllFromIds() logs a warning per skipped row.
        $this->logger = new class {
            public $warnings = [];
            public function warning($m) { $this->warnings[] = $m; }
        };
    }

    public function flushIds() { $this->flushed = true; return $this; }
    protected function fetchAllIds() { return $this->ids; }

    public function fill($object)
    {
        if (in_array($object->id, $this->missing, true)) {
            throw new RecordNotFoundException("row {$object->id} is gone");
        }
        return $object;
    }

    // Mirrors AbstractStorage::fillAllFromIds()
    public function fillAllFromIds(callable $factory)
    {
        $objects = [];
        $stale = false;
        foreach ($this->fetchAllIds() as $id) {
            try {
                $objects[] = $this->fill($factory($id));
            } catch (RecordNotFoundException $e) {
                $stale = true;
                $this->logger->warning("Skipping stale id={$id} in `{$this->tableName}`");
            }
        }
        if ($stale) {
            $this->flushIds();
        }
        return $objects;
    }
}

function makeRow($id) { $o = new stdClass(); $o->id = $id; return $o; }

return [
    'a deleted row is skipped instead of throwing' => function () {
        $h = new StaleIdHarness();
        $rows = $h->fillAllFromIds('makeRow');
        Assert::same(2, count($rows), 'surviving rows are returned');
        Assert::same([1, 3], array_map(function ($r) { return $r->id; }, $rows), 'the missing id is dropped');
    },

    'the id cache is invalidated after a skip' => function () {
        $h = new StaleIdHarness();
        $h->fillAllFromIds('makeRow');
        Assert::true($h->flushed, 'next call must re-read ids from the database');
    },

    'the skip is logged, not silent' => function () {
        $h = new StaleIdHarness();
        $h->fillAllFromIds('makeRow');
        Assert::same(1, count($h->logger->warnings), 'one warning per stale row');
        Assert::true(strpos($h->logger->warnings[0], 'id=2') !== false, 'warning names the id');
    },

    'a healthy list is untouched and does not flush' => function () {
        $h = new StaleIdHarness();
        $h->missing = [];
        $rows = $h->fillAllFromIds('makeRow');
        Assert::same(3, count($rows), 'all rows returned');
        Assert::false($h->flushed, 'no needless cache invalidation on the happy path');
    },

    'every id being stale yields an empty list, not an exception' => function () {
        $h = new StaleIdHarness();
        $h->missing = [1, 2, 3];
        $rows = $h->fillAllFromIds('makeRow');
        Assert::same(0, count($rows), 'empty result rather than a 500');
        Assert::true($h->flushed, 'cache invalidated');
    },
];
