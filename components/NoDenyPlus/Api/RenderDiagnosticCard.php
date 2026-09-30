<?php

namespace WCC\NoDenyPlus\Api;

use DI\Annotation\Inject;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCC\Diagnostic\Controllers\InterfaceDiager;
use WCC\NoDenyPlus\Controllers\Renderer;
use WCC\NoDenyPlus\Controllers\Controller;

class RenderDiagnosticCard extends PrivateAction
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
        $renderer = (new Renderer());
        $render = '';
        try {
            $queryParams = $this->request->getQueryParams();
            if (!isset($queryParams['id'])) {
                throw new HttpBadRequestException($this->request, "ID is required parameter");
            }
            $from = 'cache';
            if (isset($queryParams['from']) && $queryParams['from']) {
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
            $render = $renderer->renderResultFromData($response);
        } catch (\Exception $e) {
            $render = $renderer->renderError($e);
        }

        $this->response->getBody()->write($render);
        return $this->response->withHeader('Content-Type', 'text/html');
    }

}