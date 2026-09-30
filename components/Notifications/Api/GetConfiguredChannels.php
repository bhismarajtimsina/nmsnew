<?php

namespace WCC\Notifications\Api;

use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\DomainException\DomainRecordNotFoundException;

class GetConfiguredChannels extends AbstractNotificationAction
{
    protected function action(): Response
    {
        return $this->respondWithData(
            $this->controller->getConfiguredSources()
        );
    }
}