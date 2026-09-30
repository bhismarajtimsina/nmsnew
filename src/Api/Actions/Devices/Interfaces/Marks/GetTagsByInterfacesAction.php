<?php

namespace WCAA\Api\Actions\Devices\Interfaces\Marks;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;

/**
 * @OA\Get(
 *   path="/interface-marks/tags",
 *   tags={"interface-marks"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get tags grouped by interfaces",
 *   @OA\Response(
 *     response=200,
 *     description="Tagged interfaces",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/InterfaceWithTags"))
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 * @OA\Get(
 *   path="/interface-marks/tags/{device_id}",
 *   tags={"interface-marks"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get tagged interfaces by device",
 *   @OA\Parameter(name="device_id", in="path", required=true, @OA\Schema(type="integer", example=101)),
 *   @OA\Response(
 *     response=200,
 *     description="Tagged interfaces for device",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/InterfaceWithTags"))
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class GetTagsByInterfacesAction extends AbstractTaggedInterfacesAction
{
    protected function action(): Response
    {
        if($devId = $this->request->getAttribute('device_id'))  {
            $device = $this->deviceStorage->getById($devId);
            $data = $this->tagsStorage->getTaggedInterfacesByDevice($device);
        } else {
            $data = $this->tagsStorage->getTagsByInterfaces();
        }
        return  $this->respondWithData(array_map(function ($i) {
            $i['interface']  = $i['interface']->getAsArray();
            return $i;
        }, $data));
    }

}
