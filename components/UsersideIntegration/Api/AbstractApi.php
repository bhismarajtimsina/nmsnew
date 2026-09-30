<?php

namespace WCC\UsersideIntegration\Api;

use WCAA\Api\Actions\Action;
use WCAA\Api\Actions\PrivateAction;
use WCC\UsersideIntegration\Controllers\Controller;
use WCC\UsersideIntegration\Storage\BoxObjectStorage;

abstract class AbstractApi extends PrivateAction
{
    /**
     * @Inject
     * @var Controller
     */
    protected $controller;

}