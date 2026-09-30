<?php

namespace WCC\RouterOS\Api;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\RouterOS\Controllers\Controller;

abstract class AbstractRouterOSAction extends PrivateAction
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


    function getDevice() {
        if(is_numeric($this->request->getAttribute('id'))) {
            return $this->deviceStorage->getById($this->request->getAttribute('id'));
        } else {
            return $this->deviceStorage->getByIp($this->request->getAttribute('id'));
        }
    }

    function getFrom() {
        $params = $this->request->getQueryParams();
        if(isset($params['from']) && in_array($params['from'], ['cache', 'device', 'store'])) {
            return  $params['from'];
        } else {
            return  'cache';
        }
    }
}
