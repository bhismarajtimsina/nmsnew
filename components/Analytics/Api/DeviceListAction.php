<?php

namespace WCC\Analytics\Api;

use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Storage\Devices\DeviceStorage;
/**
 * @OA\Get(
 *   path="/component/analytics/parameters/device-list",
 *   tags={"analytics"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get devices list for analytics filters",
 *   @OA\Parameter(name="type", in="query", required=false, @OA\Schema(type="string", example="OLT")),
 *   @OA\Parameter(name="device_groups", in="query", required=false, @OA\Schema(type="string", example="1,2,3")),
 *   @OA\Response(
 *     response=200,
 *     description="Devices list",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(type="object", additionalProperties=true))
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */

class DeviceListAction extends PrivateAction
{

    
    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;
    protected function action(): Response
    {
        $params = $this->request->getQueryParams();
        $type = $params['type'] ?? null;
        if(isset($params['device_groups'])) {
            $deviceGroups = explode(",", $params['device_groups']);
        } else {
            $deviceGroups = [];
        }


        $devices = [];
        $deviceGroupIds = $this->getDeviceGroupsIdsFromUser();

        $devicesList = [];
        if($type) {
            $devicesList = $this->deviceStorage->fetchByModelType($type);
        } else {
            $devicesList = $this->deviceStorage->fetchAll();
        }

        foreach ($devicesList as $device) {
            if(!in_array($device->getGroup()->getId(), $deviceGroupIds)) {
                continue;
            }
            if($deviceGroups && !in_array($device->getGroup()->getId(), $deviceGroups)) {
                continue;
            }
            $data =  $device->getAsArray();
            $data['_device_group_name'] = $device->getGroup()->getName();
            $devices[] = $data;
        }
        return $this->respondWithData($devices);
    }


}
