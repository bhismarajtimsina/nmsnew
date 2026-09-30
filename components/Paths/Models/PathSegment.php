<?php

namespace WCC\Paths\Models;

use WCAA\Models\AbstractModel;
use WCC\Links\Models\Link;

/**
 * One hop of a path, pointing at an existing c_links row.
 */
class PathSegment extends AbstractModel
{
    /**
     * @morm
     * @prop.display=no
     * @var int
     */
    protected $path_id;

    /**
     * @morm
     * @prop.lite
     * @var int
     */
    protected $position;

    /**
     * @morm
     * @prop.display=no
     * @var int
     */
    protected $link_id;

    /**
     * Not a DB column - filled by the storage.
     *
     * @prop.lite
     * @var Link | null
     */
    protected $link;

    public function getPathId()
    {
        return $this->path_id;
    }

    public function setPathId($path_id): PathSegment
    {
        $this->path_id = $path_id;
        return $this;
    }

    public function getPosition(): int
    {
        return (int)$this->position;
    }

    public function setPosition(int $position): PathSegment
    {
        $this->position = $position;
        return $this;
    }

    public function getLinkId()
    {
        return $this->link_id;
    }

    public function setLinkId($link_id): PathSegment
    {
        $this->link_id = $link_id;
        return $this;
    }

    public function getLink(): ?Link
    {
        return $this->link;
    }

    public function setLink(?Link $link): PathSegment
    {
        $this->link = $link;
        return $this;
    }
}
