<?php

namespace WCC\SearchDevice\Api;

use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Models\Devices\Device;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\SearchDevice\Controllers\Controller;

class SearchIpAction extends PrivateAction
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


    protected function action(): Response
    {
        $data = $this->getFormData();
        if (!isset($data['devices']) || !is_array($data['devices'])) {
            $devices = $this->deviceStorage->fetchAll();
        } else {
            $devices = $this->prepareDevicesByIdents($data['devices']);
        }
        if (!isset($data['ip'])) {
            throw new HttpBadRequestException($this->request, "Field IP is required");
        }
        $RETURN = $this->controller->searchIp($devices, $data['ip']);
        return  $this->respondWithData($RETURN);
    }
    /**
     * @param string[] $deviceIdents
     * @return Device[]
     */
    protected function prepareDevicesByIdents($deviceIdents)
    {
        $devices = [];
        foreach ($deviceIdents as $deviceIdent) {
            try {
                if(is_numeric($deviceIdent)) {
                    $devices[] = $this->deviceStorage->getById($deviceIdent);
                } else {
                    $devices[] = $this->deviceStorage->getByIp($deviceIdent);
                }
            } catch (\Throwable $e) {
                $this->logger->error("Device with ident '{$deviceIdent}' not found in storage");
            }
        }
        return $devices;
    }
}
