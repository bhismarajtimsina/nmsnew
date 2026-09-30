<?php


namespace WCC\Console\Models;


use WCAA\Models\AbstractModel;
use WCAA\Models\Devices\Device;
use WCAA\Models\User\User;

class ConsoleHistory extends AbstractModel
{

    function __construct($id = null)
    {
        parent::__construct($id);
        $this->start_at = date("Y-m-d H:i:s");
    }

    /**
     * @var Device|null
     */
    protected $device = null;

    /**
     * @morm
     * @prop.display=no
     * @var int | null
     */
    protected $device_id = null;


    /**
     * @var User|null
     */
    protected $user = null;

    /**
     * @morm
     * @prop.display=no
     * @var int | null
     */
    protected $user_id = null;

    /**
     * @morm
     * @var string
     */
    protected $start_at;

    /**
     * @morm
     * @var string | null
     */
    protected $stop_at = null;

    /**
     * @morm
     * @var bool
     */
    protected $is_autologin = false;

    /**
     * @morm
     * @var string | null
     */
    protected $log;
    /**
     * @morm
     * @var array|null
     */
    protected $error;

    /**
     * @morm
     * @var int|null
     */
    protected $pid = null;

    public function getPid(): ?int
    {
        return $this->pid;
    }

    public function setPid(?int $pid): ConsoleHistory
    {
        $this->pid = $pid;
        return $this;
    }



    public function getError(): ?array
    {
        return $this->error;
    }

    public function setError(?array $error): ConsoleHistory
    {
        $this->error = $error;
        return $this;
    }

    public function getStopAt(): ?string
    {
        return $this->stop_at;
    }

    public function setStopAt(?string $stop_at): ConsoleHistory
    {
        $this->stop_at = $stop_at;
        return $this;
    }


    public function getDevice(): ?Device
    {
        return $this->device;
    }

    public function setDevice(?Device $device): ConsoleHistory
    {
        $this->device = $device;
        return $this;
    }

    public function getDeviceId(): ?int
    {
        return $this->device_id;
    }

    public function setDeviceId(?int $device_id): ConsoleHistory
    {
        $this->device_id = $device_id;
        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): ConsoleHistory
    {
        $this->user = $user;
        return $this;
    }

    public function getUserId(): ?int
    {
        return $this->user_id;
    }

    public function setUserId(?int $user_id): ConsoleHistory
    {
        $this->user_id = $user_id;
        return $this;
    }

    /**
     * @return false|string
     */
    public function getStartAt()
    {
        return $this->start_at;
    }

    /**
     * @param false|string $start_at
     * @return ConsoleHistory
     */
    public function setStartAt($start_at)
    {
        $this->start_at = $start_at;
        return $this;
    }

    public function isIsAutologin(): bool
    {
        return $this->is_autologin;
    }

    public function setIsAutologin(bool $is_autologin): ConsoleHistory
    {
        $this->is_autologin = $is_autologin;
        return $this;
    }

    public function getLog(): ?string
    {
        return $this->log;
    }

    public function setLog(?string $log): ConsoleHistory
    {
        $this->log = $log;
        return $this;
    }


}
