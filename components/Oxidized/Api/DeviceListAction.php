<?php

namespace WCC\Oxidized\Api;

use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpNotFoundException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\Oxidized\Controllers\Controller;
/**
 * @OA\Get(
 *   path="/component/oxidized/internal/devices-list",
 *   tags={"oxidized"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get oxidized devices list (internal format)",
 *   @OA\Response(
 *     response=200,
 *     description="Devices list for oxidized",
 *     @OA\JsonContent(type="array", @OA\Items(type="object", additionalProperties=true))
 *   ),
 *   @OA\Response(response=404, description="No devices yet - oxidized stays parked")
 * )
 */

class DeviceListAction extends PrivateAction
{
    
    /**
     * @Inject
     * @var Controller
     */
    protected $controller;

    protected function action(): Response
    {
        // The 404 on an empty list is a deliberate startup gate, not a bug:
        // docker-entrypoint.sh in the oxidized container starts the daemon only
        // on an exact 200, and oxidized 0.29.1 aborts with NoNodesFound when the
        // source yields no nodes. Answering 200 with [] makes it crash-loop every
        // ~2s; the 404 keeps it parked on a 300s poll until devices exist.
        $list = $this->controller->getDevicesList();
        if (count($list) === 0) {
            throw new HttpNotFoundException($this->request, "Nodes not found");
        }
        $this->response->getBody()->write(
            json_encode($list, JSON_NUMERIC_CHECK | JSON_PRETTY_PRINT)
        );
        return $this->response->withHeader('Content-Type', 'application/json');
    }

}
