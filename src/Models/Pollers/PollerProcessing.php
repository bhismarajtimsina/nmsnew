<?php


namespace WCAA\Models\Pollers;


use WCAA\App;
use WCAA\Models\AbstractModel;
use WCAA\Models\Devices\Device;

class PollerProcessing extends AbstractModel
{
    const STATUS_SUCCESS='SUCCESS';
    const STATUS_FAILED='FAILED';

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
     * @var string
     */
    protected $start_at;

    /**
     * @morm
     * @var null|string
     */
    protected $stop_at;


    /**
     * @morm
     * @var string
     */
    protected $status;


    /**
     * @morm
     * @var array|null
     */
    protected $error = null;

    /**
     * @morm
     * @var string
     */
    protected $poller = '';


    function __construct($id = null)
    {
        parent::__construct($id);
        $this->start_at = date("Y-m-d H:i:s");
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
     * @return PollerProcessing
     */
    public function setDevice(Device $device): PollerProcessing
    {
        $this->device = $device;
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
     * @return PollerProcessing
     */
    public function setDeviceId(int $device_id): PollerProcessing
    {
        $this->device_id = $device_id;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getStartAt()
    {
        return $this->start_at;
    }

    /**
     * @param mixed $start_at
     * @return PollerProcessing
     */
    public function setStartAt($start_at): PollerProcessing
    {
        $this->start_at = $start_at;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getStopAt()
    {
        return $this->stop_at;
    }

    /**
     * @param mixed $stop_at
     * @return PollerProcessing
     */
    public function setStopAt($stop_at): PollerProcessing
    {
        $this->stop_at = $stop_at;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getStatus()
    {
        return $this->status;
    }

    /**
     * @param mixed $status
     * @return PollerProcessing
     */
    public function setStatus($status): PollerProcessing
    {
        $this->status = $status;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getError()
    {
        return $this->error;
    }



    /**
     * @return PollerProcessing
     */
    public function setError(\Throwable $error): PollerProcessing
    {
        $this->error = [
            'message' => $error->getMessage(),
            'code' => $error->getCode(),
            'file' => $error->getFile(),
            'line' => $error->getLine(),
            'trace' => $error->getTraceAsString(),
        ];
        return $this;
    }

    /**
     * @return string
     */
    public function getPoller(): string
    {
        return $this->poller;
    }

    /**
     * @param string $poller
     * @return PollerProcessing
     */
    public function setPoller(string $poller): PollerProcessing
    {
        $this->poller = $poller;
        return $this;
    }

}