<?php

namespace WCC\Links\Api;

use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Infrastructure\PrometheusMetrics;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\Links\Controllers\Controller;
use WCC\Links\Storage\LinkStorage;
use WCC\Pinger\Storage\PingerDeviceStatusStorage;
use WCC\PrometheusWrapper\Controllers\Controller as PrometheusController;
/**
 * @OA\Get(
 *   path="/component/links/view/tree",
 *   tags={"links"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get directional links tree",
 *   @OA\Parameter(name="direction", in="query", required=false, @OA\Schema(type="string", example="up")),
 *   @OA\Parameter(name="device_id", in="query", required=false, @OA\Schema(type="integer", example=101)),
 *   @OA\Parameter(name="device_ip", in="query", required=false, @OA\Schema(type="string", example="10.0.0.1")),
 *   @OA\Response(
 *     response=200,
 *     description="Tree payload",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="object", additionalProperties=true)
 *     )
 *   ),
 *   @OA\Response(response=400, description="Bad Request"),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */

class DirectionTree extends PrivateAction
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
        $query = $this->request->getQueryParams();
        $direction = 'up';
        $device = null;
        if(isset($query['direction'])) {
            $direction = $query['direction'];
        }
        if(isset($query['device_id'])) {
            $device = $this->deviceStorage->getById($query['device_id']);
        } elseif (isset($query['device_ip'])) {
            $device = $this->deviceStorage->getByIp($query['device_ip']);
        } else {
            throw new HttpBadRequestException($this->request, "device_id or device_ip is required");
        }
        $tree = $this->controller->getDirectionTree($device, $direction);
        return $this->respondWithData($tree);
    }

}
