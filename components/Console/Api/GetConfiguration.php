<?php

namespace WCC\Console\Api;

use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\Action;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCC\Console\Controllers\Controller;

class GetConfiguration extends PrivateAction
{
    /**
     * @Inject
     * @var Controller
     */
    protected $controller;

    protected function action(): Response
    {
       return $this->respondWithData(
           $this->controller->getConfiguration(),
       );
    }


}