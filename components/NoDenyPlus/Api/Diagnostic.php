<?php

namespace WCC\NoDenyPlus\Api;

use DI\Annotation\Inject;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCC\Diagnostic\Controllers\InterfaceDiager;
use WCC\NoDenyPlus\Controllers\Controller;

class Diagnostic extends PrivateAction
{


    /**
     * @Inject
     * @var Controller
     */
    protected $controller;

    /**
     * @Inject
     * @var InterfaceDiager
     */
    protected $interfaceDiager;
    protected function action(): Response
    {
        $queryParams = $this->request->getQueryParams();
        if(!isset($queryParams['id'])) {
            throw new HttpBadRequestException($this->request, "ID is required parameter");
        }
        $from = 'cache';
        if(isset($queryParams['from'])) {
           $from = $queryParams['from'];
        }

        $data = $this->controller->findMacAddressesInSupport($queryParams['id']);
        $response = [];
        foreach ($data as $d) {
            $diagResult = $this->interfaceDiager->diagByInterface($d->getInterface(), $from);
            $response[] = [
                'fdb' => $d->getAsArray(),
                'result' => $diagResult,
            ];
        }

        return $this->respondWithData($response, null);
    }


}