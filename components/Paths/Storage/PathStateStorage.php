<?php

namespace WCC\Paths\Storage;

use WCAA\Storage\AbstractStorage;
use WCC\Paths\Models\PathState;

class PathStateStorage extends AbstractStorage
{
    protected $tableName = 'c_path_states';

    /**
     * @param int $pathId
     * @return PathState|null
     */
    public function getByPathId($pathId)
    {
        $psth = $this->pdo->prepare("SELECT * FROM `{$this->tableName}` WHERE path_id = ?");
        $psth->execute([$pathId]);
        if ($psth->rowCount() === 0) {
            return null;
        }
        return $this->fillByArr(new PathState(), $psth->fetch(\PDO::FETCH_ASSOC));
    }

    /**
     * Write the computed state, preserving last_change unless the state actually
     * changed - that timestamp is what "down for 12 minutes" is measured from.
     *
     * @param int $pathId
     * @param string $state
     * @param array|null $detail
     * @return bool true when the state differs from what was stored
     */
    public function store($pathId, $state, array $detail = null)
    {
        $previous = $this->getByPathId($pathId);
        $changed = $previous === null || $previous->getState() !== $state;
        $now = date("Y-m-d H:i:s");
        $lastChange = $changed ? $now : ($previous ? $previous->getLastChange() : $now);

        $psth = $this->pdo->prepare(
            "INSERT INTO `{$this->tableName}` (path_id, state, last_change, updated_at, detail)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                state = VALUES(state),
                last_change = VALUES(last_change),
                updated_at = VALUES(updated_at),
                detail = VALUES(detail)"
        );
        $psth->execute([
            $pathId,
            $state,
            $lastChange,
            $now,
            $detail === null ? null : json_encode($detail),
        ]);

        return $changed;
    }

    /**
     * @return PathState[] keyed by path_id
     */
    public function fetchAllKeyed()
    {
        $states = [];
        foreach ($this->pdo->query("SELECT * FROM `{$this->tableName}`")->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $states[(int)$row['path_id']] = $this->fillByArr(new PathState(), $row);
        }
        return $states;
    }
}
