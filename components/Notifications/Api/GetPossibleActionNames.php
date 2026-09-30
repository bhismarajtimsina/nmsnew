<?php

namespace WCC\Notifications\Api;

use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Storage\SystemActionsStorage;

class GetPossibleActionNames extends PrivateAction
{

    /**
     * @Inject
     * @var SystemActionsStorage
     */
    protected $actionStorage;

    protected function action(): Response
    {
        return  $this->respondWithData($this->actionStorage->getActionsList());
    }

}