<?php

namespace WCC\Notifications\Api;

use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\DomainException\DomainRecordNotFoundException;

class GetEventConfiguration extends AbstractNotificationAction
{
    protected function action(): Response
    {
        return $this->respondWithData(
            array_map(function ($e) {
                $arr = $e->getAsArray();
                $arr['ignored_devices'] = array_map(function ($device) {
                    return [
                        'id' => $device->getId(),
                        'ip' => $device->getIp(),
                        'name' => $device->getName(),
                        'model' => [
                            'id' => $device->getModel()->getId(),
                            'name' => $device->getModel()->getName(),
                        ],
                    ];
                }, $e->getIgnoredDevices());
                return $arr;
            }, $this->controller->getEventsConfiguration())
        );
    }
}