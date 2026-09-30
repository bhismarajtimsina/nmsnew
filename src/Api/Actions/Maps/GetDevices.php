<?php

namespace WCAA\Api\Actions\Maps;

use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Models\Devices\DeviceGroup;
use WCAA\Storage\Devices\DeviceGroupStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\Links\Models\Link;
use WCC\Links\Storage\LinkStorage;
use WCC\Pinger\Models\PingerDeviceStatus;
use WCC\Pinger\Storage\PingerDeviceStatusStorage;

class GetDevices extends PrivateAction
{
    /**
     * @Inject
     * @var DeviceGroupStorage
     */
    protected $deviceGroupStorage;

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @Inject
     * @var PingerDeviceStatusStorage
     */
    protected $pingerStorage;

    protected function action(): Response
    {
        $allowedGroups = $this->getDeviceGroupsIdsFromUser();

        $data = $this->getFormData();
        $groups = [];
        if (isset($data['groups'])) {
            foreach ($data['groups'] as $gr) {
                if (in_array($gr['id'], $allowedGroups)) {
                    $groups[] = new DeviceGroup($gr['id']);
                }
            }
        } else {
            foreach ($allowedGroups as $gr) {
                $groups[] = new DeviceGroup($gr);
            }
        }
        $response = [];

        $pinger = $this->getPingerStatuses();

        foreach ($groups as $group) {
            foreach ($this->deviceStorage->fetchByDeviceGroup($group, true) as $device) {
                if(!$device->getCoordinates()) continue;
                $resp = $device->getAsArrayLite();
                // `model.type` is marked `@prop.display=root` on DeviceModel,
                // so it's deliberately stripped from every NESTED model
                // sub-object app-wide by getAsArrayLite() — added back here
                // explicitly since the map marker needs it (device-type
                // colour split into each pin), same fix already applied to
                // the links endpoints for the same reason.
                if ($device->getModel()) {
                    $resp['model']['type'] = $device->getModel()->getType();
                }
                if(isset($pinger[$device->getId()])) {
                    $pgs = $pinger[$device->getId()];
                    $resp['pinger'] = [
                        'id' => $pgs->getId(),
                        'latency' => $pgs->getLatency(),
                        'last_change' => $pgs->getLastChange(),
                    ];
                } else {
                    $resp['pinger'] = null;
                }

                //Remove some elements
                unset($resp['access']);
                unset($resp['mac']);
                $response[] = $resp;
            }
        }
        return $this->respondWithData($response);
    }

    /**
     * @return PingerDeviceStatus[]
     * @throws \Exception
     */
    function getPingerStatuses() {
        $data = [];
        foreach ($this->pingerStorage->getAllStatuses(false) as $status) {
            $data[$status->getDeviceId()] = $status;
        };
        return $data;
    }
}