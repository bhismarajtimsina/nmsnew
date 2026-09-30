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

class PollDeviceAction extends PrivateAction
{
    protected $forbiddenInDemo = true;
    /**
     * @Inject
     * @var PollerProcessor
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

        $pollers = $this->getFormData();
        if(!$pollers) {
            $pollers = [];
        }
        $this->poller->setLogger(App::getInstance()->getContainer()->get(Logger::class));
        $response = $this->poller
            ->setDevice($device)
            ->setUser($this->user)
            ->poll($pollers, true);
        return $this->respondWithData($response);
    }
}