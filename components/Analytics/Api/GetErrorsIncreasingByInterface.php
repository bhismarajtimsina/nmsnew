<?php

namespace WCC\Analytics\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpForbiddenException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Infrastructure\PrometheusMetrics;
use WCAA\Models\Devices\Device;
use WCAA\Models\Devices\DeviceGroup;
use WCAA\Storage\Devices\DeviceGroupStorage;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\PollerData\OntIdentStorage;
use WCC\Analytics\Controllers\Controller;
use WCC\Analytics\Controllers\DuplicatesStat;
use WCC\PrometheusWrapper\Controllers\PrometheusMetricsTempStore;
/**
 * @OA\Get(
 *   path="/component/analytics/errors/increasing-data/by-interface/{interface_id}",
 *   tags={"analytics"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get increasing errors data by interface",
 *   @OA\Parameter(name="interface_id", in="path", required=true, @OA\Schema(type="integer", example=2435)),
 *   @OA\Response(
 *     response=200,
 *     description="Errors data by period",
 *     @OA\JsonContent(type="object", @OA\Property(property="data", type="object", additionalProperties=true))
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */

class GetErrorsIncreasingByInterface extends PrivateAction
{
    

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @Inject
     * @var DeviceGroupStorage
     */
    protected $deviceGroupStorage;

    /**
     * @Inject
     * @var DeviceInterfaceStorage
     */
    protected $deviceInterfaceStorage;

    /**
     * @Inject
     * @var Controller
     */
    protected $controller;


    protected function action(): Response
    {
        $iface = $this->deviceInterfaceStorage->getById($this->request->getAttribute('interface_id'));
        return $this->respondWithData([
            '10m' => $this->controller->getErrorsByInterface($iface,'10m'),
            '1h' => $this->controller->getErrorsByInterface($iface,'1h'),
            '12h' => $this->controller->getErrorsByInterface($iface,'12h'),
            '1d' => $this->controller->getErrorsByInterface($iface,'1d'),
            '3d' => $this->controller->getErrorsByInterface($iface,'3d'),
        ]);
    }

}
