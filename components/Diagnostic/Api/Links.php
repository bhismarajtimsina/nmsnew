<?php

namespace WCC\Diagnostic\Api;

use DI\Annotation\Inject;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCC\Diagnostic\Controllers\InterfaceDiager;

class Links extends PrivateAction
{

    /**
     * @Inject
     * @var InterfaceDiager
     */
    protected $interfaceDiager;
    /**
     * @Inject
     * @var DeviceInterfaceStorage
     */
    protected $ifaceStorage;

    protected function action(): Response
    {
        $iface = $this->ifaceStorage->getById($this->request->getAttribute('iface_id'));
        $supportURL =  _env('EXTERNAL_HTTP_ADDRESS', 'http://127.0.0.1:8088');

        return $this->respondWithData([
            'basic_url' => $supportURL,
            'device_url' => "$supportURL/devices/{$iface->getDevice()->getId()}",
            'interface_url' => "$supportURL/devices/{$iface->getDevice()->getId()}/interface/{$iface->getId()}",
            'edit_device_url' => "$supportURL/management/device/{$iface->getDevice()->getId()}",
        ], null);
    }
}