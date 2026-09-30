<?php

namespace WCC\SearchDevice\Api;

use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use SwitcherCore\Config\ModelCollector;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Models\Devices\Device;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\SwitcherCore\Request;
use WCAA\SwitcherCore\SwitcherCore;
use WCC\SearchDevice\Controllers\Controller;

class SearchMacAction extends PrivateAction
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
        if (!isset($data['mac'])) {
            throw new HttpBadRequestException($this->request, "Field mac is required");
        }
        $RETURN = [];

        $RETURN = $this->controller->searchMac($devices, $data['mac']);

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
