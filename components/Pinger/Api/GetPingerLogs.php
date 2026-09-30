<?php

namespace WCC\Pinger\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Models\Devices\Device;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\Pinger\Controllers\Controller;
/**
 * @OA\Get(
 *   path="/component/pinger/logs/{device_id}",
 *   tags={"pinger"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get pinger down logs by device",
 *   @OA\Parameter(name="device_id", in="path", required=true, @OA\Schema(type="integer", example=101)),
 *   @OA\Response(
 *     response=200,
 *     description="Logs list",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(type="object", additionalProperties=true)),
 *       @OA\Property(property="meta", type="object", @OA\Property(property="count", type="integer", example=10))
 *     )
 *   )
 * )
 */

class GetPingerLogs extends PrivateAction
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
        $data = $this->controller->getDownLogsByDevice(new Device($this->request->getAttribute('device_id')));
        return  $this->respondWithData($data, ['count' => count($data)]);
    }

}
