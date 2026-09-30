<?php

namespace WCC\Analytics\Api;

use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Storage\Devices\DeviceGroupStorage;
use WCAA\Storage\Devices\DeviceStorage;
/**
 * @OA\Get(
 *   path="/component/analytics/parameters/device-groups",
 *   tags={"analytics"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get allowed device groups",
 *   @OA\Response(
 *     response=200,
 *     description="Device groups list",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(type="object", additionalProperties=true))
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */

class DeviceGroupsAction extends PrivateAction
{

    
    /**
     * @Inject
     * @var DeviceGroupStorage
     */
    protected $deviceGroupStorage;

    protected function action(): Response
    {
        $groups = [];
        $deviceGroupIds = $this->getDeviceGroupsIdsFromUser();
        foreach ($this->deviceGroupStorage->fetchAll() as $group) {
            if (!in_array($group->getId(), $deviceGroupIds)) {
                continue;
            }
            $data = $group->getAsArray();
            $groups[] = $data;
        }
        return $this->respondWithData($groups);
    }


}
