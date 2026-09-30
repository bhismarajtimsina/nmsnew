<?php

namespace WCAA\Api\Actions\Poller;

use Cocur\BackgroundProcess\BackgroundProcess;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Infrastructure\Poller\PollerProcessor;

class PollDeviceBackgroundAction extends PrivateAction
{
    protected $forbiddenInDemo = true;
    /**
     * @Inject
     * @var PollerProcessor
     */
    protected $poller;


    protected function action(): Response
    {
        $deviceId = $this->request->getAttribute('device-id');
        $proccess = new BackgroundProcess("wca poller:poll {$deviceId} -d");
        $proccess->run();
        return $this->respondWithData(['pid' => $proccess->getPid()]);
    }

}