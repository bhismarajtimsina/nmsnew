<?php

namespace WCC\TrapService\Api;

use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\TrapService\Controllers\Controller;

class GetObjectNames extends PrivateAction
{
    /**
     * @Inject
     * @var Controller
     */
    protected $controller;

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    protected function action(): Response
    {
       return  $this->respondWithData($this->controller->getObjectNames());
    }


}