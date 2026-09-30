<?php


namespace WCAA\Models\Pollers;


use WCAA\Models\AbstractModel;
use WCAA\Models\Devices\Device;

class BgpSession extends AbstractModel
{

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
     * @prop.display=no
     * @var int
     */
    protected $device_id;

    /**
     * @var Device
     */
    protected $device;

    /**
     * @morm
     * @var string|null
     */
    protected $name;

    /**
     * @morm
     * @var string|null
     */
    protected $instance;

    /**
     * @morm
     * @var string|null
     */
    protected $started_at;

    /**
     * @morm
     * @var string|null
     */
    protected $remote_address;

    /**
     * @morm
     * @var string|null
     */
    protected $local_address;

    /**
     * @morm
     * @var string|null
     */
    protected $remote_id;


    /**
     * @morm
     * @var int|null
     */
    protected $remote_as;

    /**
     * @morm
     * @var string
     */
    protected $state;

    /**
     * @morm
     * @var bool
     */
    protected $disabled;

    function __construct($id = null)
    {
        parent::__construct($id);
        $this->created_at = date("Y-m-d H:i:s");
    }

    /**
     * @return string
     */
    public function getCreatedAt()
    {
        return $this->created_at;
    }

    /**
     * @param string $created_at
     * @return BgpSession
     */
    public function setCreatedAt($created_at)
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
     * @return BgpSession
     */
    public function setUpdatedAt(string $updated_at): BgpSession
    {
        $this->updated_at = $updated_at;
        return $this;
    }

    /**
     * @return int
     */
    public function getDeviceId(): int
    {
        return $this->device_id;
    }

    /**
     * @param int $device_id
     * @return BgpSession
     */
    public function setDeviceId(int $device_id): BgpSession
    {
        $this->device_id = $device_id;
        return $this;
    }

    /**
     * @return Device
     */
    public function getDevice(): Device
    {
        return $this->device;
    }

    /**
     * @param Device $device
     * @return BgpSession
     */
    public function setDevice(Device $device): BgpSession
    {
        $this->device = $device;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * @param string|null $name
     * @return BgpSession
     */
    public function setName(?string $name): BgpSession
    {
        $this->name = $name;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getInstance(): ?string
    {
        return $this->instance;
    }

    /**
     * @param string|null $instance
     * @return BgpSession
     */
    public function setInstance(?string $instance): BgpSession
    {
        $this->instance = $instance;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getStartedAt(): ?string
    {
        return $this->started_at;
    }

    /**
     * @param int|null $started_at
     * @return BgpSession
     */
    public function setStartedAt(?int $started_at): BgpSession
    {
        $this->started_at = $started_at;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getRemoteAddress(): ?string
    {
        return $this->remote_address;
    }

    /**
     * @param string|null $remote_address
     * @return BgpSession
     */
    public function setRemoteAddress(?string $remote_address): BgpSession
    {
        $this->remote_address = $remote_address;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getLocalAddress(): ?string
    {
        return $this->local_address;
    }

    /**
     * @param string|null $local_address
     * @return BgpSession
     */
    public function setLocalAddress(?string $local_address): BgpSession
    {
        $this->local_address = $local_address;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getRemoteId(): ?string
    {
        return $this->remote_id;
    }

    /**
     * @param string|null $remote_id
     * @return BgpSession
     */
    public function setRemoteId(?string $remote_id): BgpSession
    {
        $this->remote_id = $remote_id;
        return $this;
    }

    /**
     * @return int|null
     */
    public function getRemoteAs(): ?int
    {
        return $this->remote_as;
    }

    /**
     * @param int|null $remote_as
     * @return BgpSession
     */
    public function setRemoteAs(?int $remote_as): BgpSession
    {
        $this->remote_as = $remote_as;
        return $this;
    }

    /**
     * @return string
     */
    public function getState(): string
    {
        return $this->state;
    }

    /**
     * @param string $state
     * @return BgpSession
     */
    public function setState(string $state): BgpSession
    {
        $this->state = $state;
        return $this;
    }

    /**
     * @return bool
     */
    public function isDisabled(): bool
    {
        return $this->disabled;
    }

    /**
     * @param bool $disabled
     * @return BgpSession
     */
    public function setDisabled(bool $disabled): BgpSession
    {
        $this->disabled = $disabled;
        return $this;
    }

}
