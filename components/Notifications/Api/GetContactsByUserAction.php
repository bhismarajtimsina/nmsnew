<?php

namespace WCC\Notifications\Api;

use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Storage\UserStorage;

class GetContactsByUserAction extends AbstractNotificationAction
{
    /**
     * @Inject
     * @var UserStorage
     */
    protected $userStorage;

    protected function action(): Response
    {
        if($this->request->getAttribute('user') === 'self') {
            $user = $this->user;
        } else {
            $user = $this->userStorage->getById($this->request->getAttribute('user'));
        }
        $contacts = $this->controller->getContactsByUser($user);
        $return = [];
        foreach ($contacts as $contact) {
            $return[] = $contact->getAsArray();
        }
        return $this->respondWithData($return);
    }
}