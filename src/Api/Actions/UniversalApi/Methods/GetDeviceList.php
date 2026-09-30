<?php

namespace WCAA\Api\Actions\UniversalApi\Methods;


use SwitcherCore\Modules\Helper;
use WCAA\App;
use WCAA\Storage\Devices\DeviceModelStorage;
use WCAA\Storage\Devices\DeviceStorage;

class GetDeviceList extends AbstractMethod
{
    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @Inject
     * @var App
     */
    protected $app;

    protected function action()
    {
        $resp = [];
        $modelMapTypes = $this->app->conf('universal_api.type_mapper');
        foreach ($this->deviceStorage->fetchAll() as $device) {
            $resp[$device->getId()] = [
                'id' => $device->getId(),
                 'type_id' => $modelMapTypes[$device->getModel()->getType()],
                 'model_id' => $device->getModel()->getId(),
                 'ip' => ip2long($device->getIp()),
                 'mac' => $this->simplifyMac($device->getMac()),
                 'house_id' =>null,
                 'entrance' =>null,
                 'floor' =>null,
                 'node_id' =>null,
                 'location' =>$device->getLocation(),
                 'geo' => $device->getCoordinates() ? [$device->getCoordinates()['lat'], $device->getCoordinates()['lon']] : "",
                 'comment' => $device->getDescription(),
                 'date_activity' => $device->getUpdatedAt(),
                 'date_create' => $device->getCreatedAt(),
                 'snmp_version' => '2c',
                 'snmp_port' => 161,
                 'snmp_read_community' => '',
                 'software_version' => null,
            ];
        }
        if(isset($this->request->getQueryParams()['device_type'])) {
            $types = explode(',', $this->request->getQueryParams()['device_type']);
            $resp = array_filter($resp, function ($e) use ($types) {
                return in_array($e['type_id'], $types);
            });
        }
        ksort($resp);
        return $resp;
    }

    function simplifyMac($mac)
    {
        return strtolower(str_replace(['-', ':', '.', ','], '',$mac));
    }
}