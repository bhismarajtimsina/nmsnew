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
 *   path="/component/oxidized/is-oxidized-supported/{device_id}",
 *   tags={"oxidized"},
 *   security={{"XAuthKey": {}}},
 *   summary="Check if device model is supported by oxidized",
 *   @OA\Parameter(name="device_id", in="path", required=true, @OA\Schema(type="integer", example=101)),
 *   @OA\Response(
 *     response=200,
 *     description="Support flag",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="boolean", example=true)
 *     )
 *   )
 * )
 */

class IsDeviceSupportedByOxidized extends PrivateAction
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

    protected function action(): Response
    {
        $devId = $this->request->getAttribute('device_id');
        $device = $this->deviceStorage->getById($devId);
        $modelKey = $this->controller->getParametersByModel($device);

        return  $this->respondWithData($modelKey !== null);
    }

}
