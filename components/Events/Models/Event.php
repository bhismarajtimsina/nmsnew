<?php

namespace WCC\Events\Models;

use WCAA\Models\AbstractModel;
use WCAA\Models\Devices\Device;
use WCAA\Models\User\User;

class Event extends AbstractModel
{
    const WARNING = 'WARNING';
    const CRITICAL = 'CRITICAL';
    const INFO = 'INFO';

    /**
     * @var ?Device
     */
    protected $device;


    /**
     * @morm
     * @var string
     */
    protected $key = '';

    /**
     * @morm
     * @prop.display=no
     * @var int
     */
    protected $device_id;


    /**
     * @morm
     * @prop.display=no
     * @var int
     */
    protected $creator_id;

    /**
     * @var User
     */
    protected $creator;

    /**
     * @morm
     * @var string
     */
    protected $created_at;

    /**
     * @morm
     * @var string
     */
    protected $updated_at;

    /**
     * @morm
     * @var string
     */
    protected $name;


    /**
     * @morm
     * @var string[]
     */
    protected $labels;

    /**
     * @morm
     * @var string
     */
    protected $description;

    /**
     * @morm
     * @var ?string
     */
    protected $resolved_at;

    /**
     * @morm
     * @prop.display=no
     * @var int | null
     */
    protected $resolved_by_id;

    /**
     * @var User
     */
    protected $resolved_by;


    /**
     * @morm
     * @var int
     */
    protected $autoresolve_after_min;

    /**
     * @morm
     * @var bool
     */
    protected $is_autoresolved;

    /**
     * @morm
     * @var string
     */
    protected $severity;

    function __construct($id = null)
    {
        parent::__construct($id);
        $this->severity = self::WARNING;
        $this->autoresolve_after_min = 0;
        $this->is_autoresolved = false;
        $this->created_at = date("Y-m-d H:i:s");
        $this->updated_at = date("Y-m-d H:i:s");
    }

    /**
     * @return Device|null
     */
    public function getDevice(): ?Device
    {
        return $this->device;
    }

    /**
     * @param Device|null $device
     * @return Event
     */
    public function setDevice(?Device $device): Event
    {
        $this->device = $device;
        return $this;
    }

    /**
     * @return User
     */
    public function getCreator(): User
    {
        return $this->creator;
    }

    /**
     * @param User $creator
     * @return Event
     */
    public function setCreator(User $creator): Event
    {
        $this->creator = $creator;
        return $this;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param string $name
     * @return Event
     */
    public function setName(string $name): Event
    {
        $this->name = $name;
        return $this;
    }

    /**
     * @return string[]
     */
    public function getLabels(): array
    {
        return $this->labels;
    }

    /**
     * @param string[] $labels
     * @return Event
     */
    public function setLabels(array $labels, $whiteKey = true): Event
    {
        ksort($labels);
        $this->labels = $labels;
        if($whiteKey) $this->key = md5(json_encode($labels));
        return $this;
    }

    /**
     * @return string
     */
    public function getDescription()
    {
        return $this->description;
    }

    /**
     * @param string $description
     * @return Event
     */
    public function setDescription($description): Event
    {
        $this->description = $description;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getResolvedAt(): ?string
    {
        return $this->resolved_at;
    }

    /**
     * @param string|null $resolved_at
     * @return Event
     */
    public function setResolvedAt(?string $resolved_at): Event
    {
        $this->resolved_at = $resolved_at;
        return $this;
    }

    /**
     * @return User | null
     */
    public function getResolvedBy(): ?User
    {
        return $this->resolved_by;
    }

    /**
     * @param User | null $resolved_by
     * @return Event
     */
    public function setResolvedBy(?User $resolved_by): Event
    {
        $this->resolved_by = $resolved_by;
        return $this;
    }

    /**
     * @return string
     */
    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * @param string $key
     * @return Event
     */
    public function setKey(string $key): Event
    {
        $this->key = $key;
        return $this;
    }

    /**
     * @return int
     */
    public function getAutoresolveAfterMin(): int
    {
        return $this->autoresolve_after_min;
    }

    /**
     * @param int $autoresolve_after_min
     * @return Event
     */
    public function setAutoresolveAfterMin(int $autoresolve_after_min): Event
    {
        $this->autoresolve_after_min = $autoresolve_after_min;
        return $this;
    }

    /**
     * @return bool
     */
    public function isIsAutoresolved(): bool
    {
        return $this->is_autoresolved;
    }

    /**
     * @param bool $is_autoresolved
     * @return Event
     */
    public function setIsAutoresolved(bool $is_autoresolved): Event
    {
        $this->is_autoresolved = $is_autoresolved;
        return $this;
    }

    /**
     * @return string
     */
    public function getSeverity(): string
    {
        return $this->severity;
    }

    /**
     * @param string $severity
     * @return Event
     */
    public function setSeverity(string $severity): Event
    {
        $this->severity = $severity;
        return $this;
    }

    /**
     * @return string
     */
    public function getCreatedAt(): string
    {
        return $this->created_at;
    }

    /**
     * @param string $created_at
     * @return Event
     */
    public function setCreatedAt(string $created_at): Event
    {
        $this->created_at = $created_at;
        return $this;
    }

    /**
     * @return string
     */
    public function getUpdatedAt(): string
    {
        return $this->updated_at;
    }

    /**
     * @param string $updated_at
     * @return Event
     */
    public function setUpdatedAt(string $updated_at): Event
    {
        $this->updated_at = $updated_at;
        return $this;
    }



}
