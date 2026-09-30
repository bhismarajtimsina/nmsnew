<?php

namespace WCC\Paths\Storage;

use WCAA\Storage\AbstractStorage;
use WCC\Links\Models\Link;
use WCC\Links\Storage\LinkStorage;
use WCC\Paths\Models\PathSegment;

class PathSegmentStorage extends AbstractStorage
{
    protected $tableName = 'c_path_segments';

    /**
     * @Inject
     * @var LinkStorage
     */
    protected $linkStorage;

    /**
     * Ordered hops of a path, each with its Link resolved.
     *
     * @param int $pathId
     * @return PathSegment[]
     */
    public function getByPath($pathId)
    {
        $psth = $this->pdo->prepare(
            "SELECT * FROM `{$this->tableName}` WHERE path_id = ? ORDER BY position"
        );
        $psth->execute([$pathId]);

        $segments = [];
        foreach ($psth->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $segment = $this->fillByArr(new PathSegment($row['id']), $row);
            try {
                $segment->setLink($this->linkStorage->fill(new Link($segment->getLinkId())));
            } catch (\Throwable $e) {
                //A segment whose link was deleted is reported as unknown by the
                //calculator rather than aborting the whole path.
                $this->logger->warning(
                    "Path segment {$segment->getId()} references missing link {$segment->getLinkId()}"
                );
                $segment->setLink(null);
            }
            $segments[] = $segment;
        }
        return $segments;
    }

    /**
     * Replace the full ordered segment list of a path in one transaction.
     *
     * Segments are positional, so partial updates would leave gaps - callers
     * always submit the complete chain.
     *
     * @param int $pathId
     * @param int[] $linkIds ordered
     * @return PathSegment[]
     */
    public function replaceForPath($pathId, array $linkIds)
    {
        $this->pdo->beginTransaction();
        try {
            $del = $this->pdo->prepare("DELETE FROM `{$this->tableName}` WHERE path_id = ?");
            $del->execute([$pathId]);

            $ins = $this->pdo->prepare(
                "INSERT INTO `{$this->tableName}` (path_id, position, link_id) VALUES (?, ?, ?)"
            );
            foreach (array_values($linkIds) as $position => $linkId) {
                $ins->execute([$pathId, $position, (int)$linkId]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        $this->clearByObject(new PathSegment());
        $this->flushIds();
        return $this->getByPath($pathId);
    }
}
