<?php

namespace WCC\Notifications\Api;

use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\DomainException\DomainRecordNotFoundException;

class GetDefaultChannelConfigurationAction extends AbstractNotificationAction
{
    protected function action(): Response
    {
        return $this->respondWithData(
            $this->controller->getDefaultConfig($this->request->getAttribute('channel'))
        );
    }
}