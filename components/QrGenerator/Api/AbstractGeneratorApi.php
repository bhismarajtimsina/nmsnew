<?php

namespace WCC\QrGenerator\Api;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface;
use Slim\Exception\HttpNotFoundException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\App;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\SystemActionsStorage;
use WCAA\Storage\UserStorage;
use WCC\QrGenerator\Controllers\Controller;
use WCC\UtelsIntegration\Storage\BoxObjectStorage;

abstract class AbstractGeneratorApi extends PrivateAction
{
    /**
     * @Inject
     * @var Controller
     */
    protected $controller;


    protected function getObjectByRequest(Request $request)
    {
        $storage = $this->getStorageByRequest($request);
        return $storage->getById($request->getAttribute('id'));
    }

    /**
     * @param Request $request
     * @return mixed|DeviceInterfaceStorage|DeviceStorage|UserStorage|BoxObjectStorage
     * @throws \DI\DependencyException
     * @throws \DI\NotFoundException
     */
    protected function getStorageByRequest(Request $request)
    {
        $storage = null;
        $di = App::getInstance()->getContainer();
        switch($request->getAttribute('type')) {
            case 'device': $storage = $di->get(DeviceStorage::class); break;
            case 'interface': $storage = $di->get(DeviceInterfaceStorage::class); break;
            case 'pon-box': $storage = $di->get(BoxObjectStorage::class); break;
            case 'user': $storage = $di->get(UserStorage::class); break;
            default: throw new HttpNotFoundException($this->request, 'Unknown request type');
        }
        return $storage;
    }

}