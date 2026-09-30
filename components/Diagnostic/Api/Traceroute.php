<?php

namespace WCC\Diagnostic\Api;

use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotImplementedException;
use SwitcherCore\Config\ModelCollector;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Models\Devices\Device;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\SwitcherCore\Request;
use WCAA\SwitcherCore\SwitcherCore;
use WCC\Diagnostic\Controllers\Controller;

class Traceroute extends PrivateAction
{
    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @Inject
     * @var Controller
     */
    protected $controller;


    protected function action(): Response
    {
        /**
         * @TODO Realize in the future
         */
        throw new HttpNotImplementedException($this->request, "Not realized");
        return  $this->respondWithData([]);
    }
}
