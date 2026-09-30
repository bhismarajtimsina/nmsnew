<?php

namespace WCC\LiveTraffic\Api;

use DI\Annotation\Inject;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Models\Devices\Device;
use WCAA\Models\Devices\DeviceInterface;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\LiveTraffic\Controllers\Controller;

class GetTraffic extends PrivateAction
{
    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @Inject
     * @var Controller
     */
    protected $controller;

    /**
     * @Inject
     * @var DeviceInterfaceStorage
     */
    protected $deviceInterfaceStorage;

    protected function action(): Response
    {
        $this->controller->setUser($this->user);
        $queries = $this->request->getQueryParams();
        if(isset($queries['interface_id'])) {
            return $this->getData($this->deviceInterfaceStorage->getById($queries['interface_id']));
        }
        if(isset($queries['device_id']) && isset($queries['interface'])) {
            $device = $this->deviceStorage->getById($queries['device_id']);
            $interface = $this->deviceInterfaceStorage->getByDeviceAndKey($device, $queries['interface']);
            return  $this->getData($interface);
        }
        throw new HttpBadRequestException($this->request, "interface_id or device with interface is required");
    }

    protected function getData(DeviceInterface $iface) {
        return $this->respondWithData($this->controller->getTraffic($iface));
    }
}
