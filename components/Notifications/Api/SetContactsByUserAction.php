<?php

namespace WCC\Notifications\Api;

use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Storage\UserStorage;
use WCC\Notifications\Models\NotificationContact;

class SetContactsByUserAction extends AbstractNotificationAction
{

    protected $forbiddenInDemo = true;

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
        $data = [];
        foreach ($this->getFormData() as $d) {
            $data[] = (new NotificationContact())
            ->setValue($d['value'])
            ->setType($d['type'])
            ->setEnabled($d['enabled'])
            ->setDescription($d['description'])
            ->setUser($user)
            ->setParams($d['params']);
        }
        $contacts = $this->controller->setContactsByUser($user, $data);
        $return = [];
        foreach ($contacts as $contact) {
            $return[] = $contact->getAsArray();
        }
        return $this->respondWithData($return);
    }
}