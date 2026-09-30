<?php


namespace WCC\SensorDevices\Api;


use Psr\Http\Message\ServerRequestInterface as Request;
use WCAA\Api\Actions\PrivateAction;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Models\Devices\Device;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\SensorDevices\Controllers\Controller;

abstract class SensorApiControllerAbstract extends PrivateAction
{
    /**
     * @Inject
     * @var Controller
     */
    protected $controller;

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @var Device
     */
    protected $device;

    function action(): Response
    {
        $this->controller->setUser($this->user);

        if($deviceId = $this->request->getAttribute('device_id', null)) {
            $this->device = $this->deviceStorage->getById($deviceId);
            $this->controller->setDevice($this->device);
        }

        return $this->call();
    }

    /**
     * @return Response
     */
    abstract function call();
}