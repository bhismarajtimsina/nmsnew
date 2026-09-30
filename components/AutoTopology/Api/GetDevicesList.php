<?php

namespace WCC\AutoTopology\Api;

use Psr\Http\Message\ResponseInterface as Response;
use SwitcherCore\Config\ModelCollector;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\SwitcherCore\SwitcherCore;
use WCC\AutoTopology\Controllers\Controller;
use WCC\Links\Models\Link;
use WCC\Pinger\Models\PingerDeviceStatus;
use WCC\Pinger\Storage\PingerDeviceStatusStorage;

class GetDevicesList extends PrivateAction
{
    protected $forbiddenInDemo = true;

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
     * @var ModelCollector
     */
    protected $modelCollector;

    /**
     * @Inject
     * @var PingerDeviceStatusStorage
     */
    protected $pinger;

    protected function action(): Response
    {

        $statuses = [];
        foreach ($this->pinger->getAllStatuses(false) as $status) {
            $statuses[$status->getDeviceId()] = $status;
        }

        $devices = [];
        foreach ($this->deviceStorage->fetchAll() as $device) {
            if(!isset($statuses[$device->getId()])) continue;
            if(!$device->isEnabled()) continue;
            if($statuses[$device->getId()]->getLatency() <= 0) {
                continue;
            }
            $dev = $device->getAsArray();
            try {
                $dev['methods'] = $this->modelCollector->getModelByKey($device->getModel()->getKey())->getModulesList();
            } catch (\Exception $e) {}
            $devices[] = $dev;
        }

        return $this->respondWithData($devices);
    }
}