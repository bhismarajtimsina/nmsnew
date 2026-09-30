<?php

namespace WCC\Paths\Models;

use WCAA\Models\AbstractModel;

/**
 * Denormalised current state of a path, so map reads stay a single query.
 */
class PathState extends AbstractModel
{
    const STATE_UP = 'up';
    const STATE_DEGRADED = 'degraded';
    const STATE_DOWN = 'down';
    const STATE_UNKNOWN = 'unknown';

    /**
     * Numeric encoding used for the Prometheus gauge, so Alertmanager rules can
     * compare directly (path_state == 0 means down).
     */
    const NUMERIC = [
        self::STATE_UP => 1,
        self::STATE_DEGRADED => 0.5,
        self::STATE_DOWN => 0,
        self::STATE_UNKNOWN => -1,
    ];

    /**
     * @morm
     * @prop.display=no
     * @var int
     */
    protected $path_id;

    /**
     * @morm
     * @prop.lite
     * @var string
     */
    protected $state;

    /**
     * @morm
     * @prop.lite
     * @var string | null
     */
    protected $last_change;

    /**
     * @morm
     * @prop.lite=1
     * @var string
     */
    protected $updated_at;

    /**
     * @morm
     * @prop.lite
     * @var ?array
     */
    protected $detail;

    function __construct($id = null)
    {
        $this->state = self::STATE_UNKNOWN;
        $this->updated_at = date("Y-m-d H:i:s");
        parent::__construct($id);
    }

    public function getPathId()
    {
        return $this->path_id;
    }

    public function setPathId($path_id): PathState
    {
        $this->path_id = $path_id;
        return $this;
    }

    public function getState(): string
    {
        return (string)$this->state;
    }

    public function setState(string $state): PathState
    {
        $this->state = $state;
        return $this;
    }

    public function getLastChange(): ?string
    {
        return $this->last_change;
    }

    public function setLastChange(?string $last_change): PathState
    {
        $this->last_change = $last_change;
        return $this;
    }

    public function getUpdatedAt(): string
    {
        return $this->updated_at;
    }

    public function setUpdatedAt(string $updated_at): PathState
    {
        $this->updated_at = $updated_at;
        return $this;
    }

    public function getDetail(): ?array
    {
        return $this->detail;
    }

    public function setDetail(?array $detail): PathState
    {
        $this->detail = $detail;
        return $this;
    }

    /**
     * @return float
     */
    public function getNumericState()
    {
        $state = $this->getState();
        return isset(self::NUMERIC[$state]) ? self::NUMERIC[$state] : -1;
    }

    public function isUsable(): bool
    {
        return in_array($this->getState(), [self::STATE_UP, self::STATE_DEGRADED], true);
    }
}
