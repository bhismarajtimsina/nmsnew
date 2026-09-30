<?php

namespace WCC\AutoTopology\Api;

use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\AutoTopology\Controllers\Controller;
use WCC\Links\Models\Link;

class UpdateAction extends PrivateAction
{
    protected $forbiddenInDemo = true;

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @Inject
     * @var DeviceInterfaceStorage
     */
    protected $deviceInterfaceStorage;

    /**
     * @Inject
     * @var Controller
     */
    protected $controller;

    protected function action(): Response
    {
        $form = $this->getFormData();

        $link = new Link();

        $locDevice = $this->deviceStorage->getById($form['device']['id']);
        $remoteDevice = $this->deviceStorage->getById($form['neighbor_device']['id']);
        $link
            ->setSrcDevice($locDevice)
            ->setDestDevice($remoteDevice)
            ->setSource($form['detect_method_type']);

        if(isset($form['interface']['id'])) {
            $deviceInterface = $this->deviceInterfaceStorage->getByDeviceAndKey($locDevice, $form['interface']['id']);
            $link->setSrcIface($deviceInterface);
        }

        if(isset($form['neighbor_interface']['id'])) {
            $remoteDeviceInterface = $this->deviceInterfaceStorage->getByDeviceAndKey($remoteDevice, $form['neighbor_interface']['id']);
            $link->setDestIface($remoteDeviceInterface);
        }

        $this->controller->updateLink($link);

        return $this->respondWithData($link->getAsArrayLite());
    }
}