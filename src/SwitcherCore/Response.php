<?php

namespace WCAA\SwitcherCore;

use WCAA\Exceptions\SupportException;
use WCAA\Models\Devices\Device;
use WCAA\Models\User\User;

class Response
{
    const STATUS_SUCCESS = 'SUCCESS';
    const STATUS_FAILED = 'FAILED';


    protected $data;
    protected $hash;
    protected $time;
    protected $status;
    protected $source;
    protected $spent = null;

    /**
     * @var array
     */
    protected $error;

    /**
     * @var Device
     */
    protected $device;

    /**
     * @var User
     */
    protected $user;
    protected $module;
    protected $arguments = [];
    protected $meta = [];

    function __construct()
    {
        $this->time = date("Y-m-d H:i:s");
        $this->status = self::STATUS_SUCCESS;
    }

    /**
     * @return mixed
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * @param mixed $data
     * @return Response
     */
    public function setData($data)
    {
        $this->data = $data;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getSource()
    {
        return $this->source;
    }

    /**
     * @param mixed $source
     * @return Response
     */
    public function setSource($source)
    {
        $this->source = $source;
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
    public function getTime()
    {
        return $this->time;
    }

    /**
     * @param mixed $time
     * @return Response
     */
    public function setTime($time)
    {
        $this->time = $time;
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
     * @return Response
     */
    public function setStatus($status)
    {
        $this->status = $status;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getDevice()
    {
        return $this->device;
    }

    /**
     * @param mixed $device
     * @return Response
     */
    public function setDevice($device)
    {
        $this->device = $device;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getUser()
    {
        return $this->user;
    }

    /**
     * @param mixed $user
     * @return Response
     */
    public function setUser($user)
    {
        $this->user = $user;
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
     * @param mixed $module
     * @return Response
     */
    public function setModule($module)
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
     * @return Response
     */
    public function setArguments($arguments)
    {
        $this->arguments = $arguments;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getMeta()
    {
        $meta = $this->meta;
        $meta['error'] = $this->error;
        $meta['spent'] = $this->spent;
        return $this->meta;
    }

    /**
     * @param mixed $meta
     * @return Response
     */
    public function setMeta($meta)
    {
        $this->meta = $meta;
        return $this;
    }

    function getDataAsArray()
    {
        return (array)$this->data;
    }


    function setError($e) {
        if($e === null) {
            $this->error = null;
            return  $this;
        }
        if($e instanceof SupportException) {
            $this->error = [
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'type' => $e->getType(),
                'trace' => $e->getTraceAsString(),
            ];
        } elseif ($e instanceof \Throwable) {
            $this->error = [
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'type' => 'EXCEPTION',
                'trace' => $e->getTraceAsString(),
            ];
        } elseif (is_array($e)) {
            $this->error = $e;
        }
        return $this;
    }

    function getError() {
        return $this->error;
    }

    /**
     * @return mixed
     */
    public function getSpent()
    {
        return $this->spent;
    }

    /**
     * @param mixed $spent
     * @return Response
     */
    public function setSpent($spent)
    {
        $this->spent = $spent;
        return $this;
    }


}