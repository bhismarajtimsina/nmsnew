<?php

namespace WCC\Notifications\Api;

use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\Events\Storage\EventsStorage;

class GetDevicesList extends PrivateAction
{

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $storage;

    protected function action(): Response
    {
        $userDeviceGroups = $this->user->getDeviceGroups();
        $devices = [];
        foreach ($this->storage->fetchAll() as $device) {
            if(!array_filter($userDeviceGroups, function ($e) use ($device){
                    return $device->getGroup()->getId() === $e->getId();
                }) && ($this->user->getId() > 0  && $this->user->getRole()->getId() > 0)) {
                continue;
            }
            $devices[] = [
                'id' => $device->getId(),
                'ip' => $device->getIp(),
                'name' => $device->getName(),
                'model' => [
                    'id' => $device->getModel()->getId(),
                    'name' => $device->getModel()->getName(),
                ],
                'selected' => true,
                'searching_string' => "{$device->getIp()}\n{$device->getName()}\n{$device->getModel()->getName()}\n{$device->getModel()->getType()}",
            ];
        }
        return $this->respondWithData($devices);
    }

}