<?php

namespace WCC\Diagnostic\Api;

use DI\Annotation\Inject;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCC\Diagnostic\Controllers\InterfaceDiager;
use WCC\Diagnostic\Controllers\Renderer;

class RenderDiagnosticCard extends PrivateAction
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
        $renderer = (new Renderer());
        $render = '';
        try {
            $from = 'cache';
            if (isset($queryParams['from']) && $queryParams['from']) {
                $from = $queryParams['from'];
            }
            $iface = $this->ifaceStorage->getById($this->request->getAttribute('iface_id'));

            $diagResult = $this->interfaceDiager->diagByInterface($iface, $from);
            $render = $renderer->renderResultFromData($diagResult);
        } catch (\Exception $e) {
            $render = $renderer->renderError($e);
        }

        $this->response->getBody()->write($render);
        return $this->response->withHeader('Content-Type', 'text/html');
    }

}