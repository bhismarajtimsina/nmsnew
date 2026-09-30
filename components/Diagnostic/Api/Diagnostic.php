<?php

namespace WCC\Diagnostic\Api;

use DI\Annotation\Inject;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCC\Diagnostic\Controllers\InterfaceDiager;

class Diagnostic extends PrivateAction
{

    /**
     * @Inject
     * @var InterfaceDiager
     */
    protected $interfaceDiager;


    /**
     * @Inject
     * @var DeviceInterfaceStorage
     */
    protected $ifaceStorage;

    protected function action(): Response
    {
        $queryParams = $this->request->getQueryParams();
        $from = 'cache';
        if(isset($queryParams['from'])) {
           $from = $queryParams['from'];
        }
        $iface = $this->ifaceStorage->getById($this->request->getAttribute('iface_id'));

        $loadModules = null;
        if(isset($queryParams['load_modules'])) {
            $loadModules=explode(',',$queryParams['load_modules']);
        }

        $diagResult = $this->interfaceDiager->diagByInterface($iface, $from, $loadModules);
        return $this->respondWithData($diagResult, null);
    }


}