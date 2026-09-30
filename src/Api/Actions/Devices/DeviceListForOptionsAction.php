<?php

namespace WCAA\Api\Actions\Devices;

use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Storage\Devices\DeviceStorage;

/**
 * @OA\Get(
 *   path="/device/options",
 *   tags={"device"},
 *   security={{"XAuthKey": {}}},
 *   summary="List devices for options",
 *   description="Returns device list formatted for selector controls. Optional query: type.",
 *   @OA\Parameter(
 *     name="type",
 *     in="query",
 *     required=false,
 *     description="Device model type filter",
 *     @OA\Schema(type="string", example="OLT")
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Options list",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(
 *         property="data",
 *         type="array",
 *         @OA\Items(
 *           allOf={
 *             @OA\Schema(ref="#/components/schemas/Device"),
 *             @OA\Schema(
 *               type="object",
 *               @OA\Property(property="display_name", type="string", example="192.168.1.10 - Core-Switch-01")
 *             )
 *           }
 *         )
 *       )
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class DeviceListForOptionsAction extends PrivateAction
{
    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;
    protected function action(): Response
    {
        $type = isset($this->request->getQueryParams()['type']) ? $this->request->getQueryParams()['type'] : null;

        $deviceGroupIds = $this->getDeviceGroupsIdsFromUser();
        if($type) {
            $devices = $this->deviceStorage->fetchByModelType($type);
        } else {
            $devices = $this->deviceStorage->fetchAll();
        }

        $data = [];
        foreach ($devices as $device) {
            if(!in_array($device->getGroup()->getId(), $deviceGroupIds)) {
                continue;
            }
            $dev =  $device->getAsArray();
            $dev['display_name'] = "{$dev['ip']} - ";
            if($dev['name']) {
                $dev['display_name'] .= "{$dev['name']}";
            } else {
                $dev['display_name'] .= " N/A";
            }
            $data[] = $dev;
        }
        return $this->respondWithData($data);
    }


}
