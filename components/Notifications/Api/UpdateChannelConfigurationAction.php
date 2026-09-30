<?php

namespace WCC\Notifications\Api;

use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\DomainException\DomainRecordNotFoundException;

class UpdateChannelConfigurationAction extends AbstractNotificationAction
{
    protected $forbiddenInDemo = true;

    protected function action(): Response
    {
        $this->addActionSuccess("notifications:channel-updated", "User {$this->user->getName()} updated channel configuration");
        return $this->respondWithData(
            $this->controller->updateConfiguration($this->request->getAttribute('channel'), $this->getFormData())
        );
    }
}