<?php

namespace WCC\AutoDiscovery\Api;

use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Models\Devices\DeviceAccess;
use WCAA\Models\Devices\DeviceGroup;
use WCC\AutoDiscovery\Models\AutoDiscoveryNetwork;

class UpdateDiscoveryNetworks extends AbstractAutoDiscovery
{
    protected $forbiddenInDemo = true;

    protected function action(): Response
    {
        $data = $this->getFormData();
        $networks = [];
        foreach ($data as $d) {
            if(!isset($d['device_access'])) throw new HttpBadRequestException($this->request, "Device access is required");
            if(!isset($d['device_group'])) throw new HttpBadRequestException($this->request, "Device access is required");
            $networks[] = (new AutoDiscoveryNetwork())
                ->setDeviceGroup(new DeviceGroup($d['device_group']['id']))
                ->setDeviceAccess(new DeviceAccess($d['device_access']['id']))
                ->setCidr($d['cidr'])
                ->setCreatedAt(date("Y-m-d H:i:s"))
            ;
        }
        $response = [];
        foreach ($this->controller->updateDiscoveryNetworks($networks) as $r) {
            $response[] = $r->getAsArray();
        }
        return $this->respondWithData($response);
    }
}