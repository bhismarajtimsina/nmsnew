<?php

namespace WCAA\Api\Actions\Poller;

use Monolog\Logger;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\App;
use WCAA\Infrastructure\Poller\PollerProcessor;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\PollerData\PollerProcessingStorage;

class GetLatestPollersByDeviceAction extends PrivateAction
{
    /**
     * @Inject
     * @var PollerProcessingStorage
     */
    protected $poller;

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;


    protected function action(): Response
    {
        $device = $this->deviceStorage->getById($this->request->getAttribute('device-id'));
        $response = [];
        foreach ($this->poller->getLatests($device) as $resp) {
            $r = $resp->getAsArray();
            unset($r['device']);
            $response[$resp->getPoller()] = $r;
        }
        ksort($response);
        return $this->respondWithData(array_values($response));
    }

}