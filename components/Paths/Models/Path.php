<?php

namespace WCC\Paths\Models;

use WCAA\Models\AbstractModel;
use WCAA\Models\Devices\Device;

/**
 * A named transport route between two endpoint devices.
 *
 * Paths sharing a group_key are alternates for the same service, which is what
 * makes the "still up but no longer protected" state detectable.
 */
class Path extends AbstractModel
{
    /**
     * @morm
     * @prop.lite
     * @var string
     */
    protected $created_at;

    /**
     * @morm
     * @prop.lite=1
     * @var string
     */
    protected $updated_at;

    /**
     * @morm
     * @prop.lite
     * @var string
     */
    protected $name;

    /**
     * @morm
     * @prop.lite
     * @var string | null
     */
    protected $group_key;

    /**
     * @morm
     * @prop.lite
     * @var int
     */
    protected $priority;

    /**
     * @morm
     * @prop.display=no
     * @var int
     */
    protected $endpoint_a_id;

    /**
     * @prop.lite
     * @var Device | null
     */
    protected $endpoint_a;

    /**
     * @morm
     * @prop.display=no
     * @var int
     */
    protected $endpoint_b_id;

    /**
     * @prop.lite
     * @var Device | null
     */
    protected $endpoint_b;

    /**
     * @morm
     * @prop.lite
     * @var bool
     */
    protected $enabled;

    /**
     * @morm
     * @var ?array
     */
    protected $params;

    /**
     * Ordered hops. Not a DB column - filled by the storage.
     *
     * @var PathSegment[]
     */
    protected $segments = [];

    /**
     * Current computed state. Not a DB column - filled by the storage.
     *
     * @prop.lite
     * @var PathState | null
     */
    protected $state;

    function __construct($id = null)
    {
        $this->created_at = date("Y-m-d H:i:s");
        $this->updated_at = date("Y-m-d H:i:s");
        $this->priority = 100;
        $this->enabled = true;
        parent::__construct($id);
    }

    public function getCreatedAt(): string
    {
        return $this->created_at;
    }

    public function setCreatedAt(string $created_at): Path
    {
        $this->created_at = $created_at;
        return $this;
    }

    public function getUpdatedAt(): string
    {
        return $this->updated_at;
    }

    public function setUpdatedAt(string $updated_at): Path
    {
        $this->updated_at = $updated_at;
        return $this;
    }

    public function getName(): string
    {
        return (string)$this->name;
    }

    public function setName(string $name): Path
    {
        $this->name = $name;
        return $this;
    }

    public function getGroupKey(): ?string
    {
        return $this->group_key;
    }

    public function setGroupKey(?string $group_key): Path
    {
        $this->group_key = $group_key;
        return $this;
    }

    public function getPriority(): int
    {
        return (int)$this->priority;
    }

    public function setPriority(int $priority): Path
    {
        $this->priority = $priority;
        return $this;
    }

    public function getEndpointAId()
    {
        return $this->endpoint_a_id;
    }

    public function setEndpointAId($endpoint_a_id): Path
    {
        $this->endpoint_a_id = $endpoint_a_id;
        return $this;
    }

    public function getEndpointA(): ?Device
    {
        return $this->endpoint_a;
    }

    public function setEndpointA(?Device $endpoint_a): Path
    {
        $this->endpoint_a = $endpoint_a;
        return $this;
    }

    public function getEndpointBId()
    {
        return $this->endpoint_b_id;
    }

    public function setEndpointBId($endpoint_b_id): Path
    {
        $this->endpoint_b_id = $endpoint_b_id;
        return $this;
    }

    public function getEndpointB(): ?Device
    {
        return $this->endpoint_b;
    }

    public function setEndpointB(?Device $endpoint_b): Path
    {
        $this->endpoint_b = $endpoint_b;
        return $this;
    }

    public function isEnabled(): bool
    {
        return (bool)$this->enabled;
    }

    public function setEnabled(bool $enabled): Path
    {
        $this->enabled = $enabled;
        return $this;
    }

    public function getParams(): ?array
    {
        return $this->params;
    }

    public function setParams(?array $params): Path
    {
        $this->params = $params;
        return $this;
    }

    /**
     * @return PathSegment[]
     */
    public function getSegments(): array
    {
        return $this->segments;
    }

    /**
     * @param PathSegment[] $segments
     */
    public function setSegments(array $segments): Path
    {
        $this->segments = $segments;
        return $this;
    }

    public function getState(): ?PathState
    {
        return $this->state;
    }

    public function setState(?PathState $state): Path
    {
        $this->state = $state;
        return $this;
    }
}
