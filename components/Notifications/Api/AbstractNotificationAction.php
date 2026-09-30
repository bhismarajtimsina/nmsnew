<?php

namespace WCC\Notifications\Api;

use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCC\Notifications\Controllers\Controller;

abstract class AbstractNotificationAction extends PrivateAction
{
    /**
     * @Inject
     * @var Controller
     */
    protected $controller;
}