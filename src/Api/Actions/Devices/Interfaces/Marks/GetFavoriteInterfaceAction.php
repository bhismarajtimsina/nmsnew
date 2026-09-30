<?php

namespace WCAA\Api\Actions\Devices\Interfaces\Marks;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;

/**
 * @OA\Get(
 *   path="/interface-marks/favorite",
 *   tags={"interface-marks"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get favorite interfaces",
 *   @OA\Response(
 *     response=200,
 *     description="Favorite interfaces",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/DeviceInterface"))
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 * @OA\Get(
 *   path="/interface-marks/favorite/{device_id}",
 *   tags={"interface-marks"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get favorite interfaces by device",
 *   @OA\Parameter(name="device_id", in="path", required=true, @OA\Schema(type="integer", example=101)),
 *   @OA\Response(
 *     response=200,
 *     description="Favorite interfaces for device",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/DeviceInterface"))
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class GetFavoriteInterfaceAction extends AbstractTaggedInterfacesAction
{
    protected function action(): Response
    {
        $data = [];
        if ($devId = $this->request->getAttribute('device_id')) {
            $device = $this->deviceStorage->getById($devId);
            $data = $this->tagsStorage->getFavoriteInterfacesByDevice($device);
        } else {
            $data = $this->tagsStorage->getFavoriteInterfaces();
        }
        return $this->respondWithData(array_map(function ($i) {
            return $i->getAsArray();
        }, $data));
    }

}
