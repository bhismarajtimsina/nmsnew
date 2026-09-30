<?php
namespace WCAA\SwitcherCore;


use WCAA\Models\Devices\Device;
use WCAA\Models\User\User;

class Request
{

    const STATUS_SUCCESS = 'SUCCESS';
    const STATUS_FAILED = 'FAILED';

    /**
     * @var Device
     */
    protected $device;

    protected $module;
    protected $arguments = [];
    protected $status;
    protected $catchErrors = true;

    function __construct() {
        $this->status = self::STATUS_SUCCESS;
    }

    public static function init(Device $device, string $moduleName, $arguments = [], $catchErrors = true) {
        return (new self())->setDevice($device)->setModule($moduleName)->setArguments($arguments)->setCatchErrors($catchErrors);
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
     * @return Request
     */
    public function setStatus($status)
    {
        $this->status = $status;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getHash()
    {
        $argv = $this->arguments;
        ksort($argv);
        $argvJson = json_encode($argv, JSON_NUMERIC_CHECK);
        return "{$this->device->getId()}:{$this->module}:{$this->status}:".sha1("{$argvJson}");
    }

    /**
     * @return mixed
     */
    public function getDevice()
    {
        return $this->device;
    }

    /**
     * @param Device $device
     * @return Request
     */
    public function setDevice(Device  $device)
    {
        $this->device = $device;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getModule()
    {
        return $this->module;
    }

    /**
     * @param string $module
     * @return Request
     */
    public function setModule(string $module)
    {
        $this->module = $module;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getArguments()
    {
        return $this->arguments;
    }

    /**
     * @param mixed $arguments
     * @return Request
     */
    public function setArguments(array $arguments)
    {
        foreach ($arguments as $k=>$v) {
            if($v === null) unset($arguments[$k]);
        }
        $this->arguments = $arguments;
        return $this;
    }

    /**
     * @return bool
     */
    public function isCatchErrors(): bool
    {
        return $this->catchErrors;
    }

    /**
     * @param bool $catchErrors
     * @return $this
     */
    public function setCatchErrors(bool $catchErrors)
    {
        $this->catchErrors = $catchErrors;
        return $this;
    }


    public function getAsArray() {
        return [
          'device' => $this->device->getAsArray(),
          'module' => $this->module,
          'arguments' => $this->arguments,
        ];
    }
}