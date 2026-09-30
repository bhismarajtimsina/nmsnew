<?php

namespace WCC\Notifications\Api;

use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCC\Events\Storage\EventsStorage;

class GetPossibleEventNames extends PrivateAction
{

    /**
     * @Inject
     * @var EventsStorage
     */
    protected $eventStorage;

    protected function action(): Response
    {
        return  $this->respondWithData($this->eventStorage->getEventNames());
    }

}