<?php

namespace WCAA\Api\Actions\System;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Storage\Devices\DeviceGroupStorage;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\UserRoleStorage;
use WCAA\Storage\UserStorage;

/**
 * @OA\Get(
 *   path="/system/usage-stat",
 *   tags={"system"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get usage statistics",
 *   @OA\Response(
 *     response=200,
 *     description="Usage statistics",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(
 *         property="data",
 *         type="object",
 *         @OA\Property(property="version", type="string", example="1.2.3"),
 *         @OA\Property(property="count_groups", type="integer", example=8),
 *         @OA\Property(property="count_devices", type="integer", example=120),
 *         @OA\Property(property="count_users", type="integer", example=6),
 *         @OA\Property(property="count_interfaces", type="integer", example=3240),
 *         @OA\Property(property="count_by_models", type="object", additionalProperties=@OA\Schema(type="integer"))
 *       )
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class GetSystemStatAction extends PrivateAction
{
    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @Inject
     * @var DeviceInterfaceStorage
     */
    protected $deviceInterfaceStorage;

    /**
     * @Inject
     * @var UserStorage
     */
    protected $userStorage;

    /**
     * @Inject
     * @var UserRoleStorage
     */
    protected $roleStorage;

    /**
     * @Inject
     * @var DeviceGroupStorage
     */
    protected $deviceGroupStorage;

    protected function action(): Response
    {
        return $this->respondWithData($this->getUsageStat());
    }


    protected function getUsageStat() {
        $byModels = null;
        $devices = $this->deviceStorage->fetchAll();
        foreach ($devices as $dev) {
            if(!isset($byModels[$dev->getModel()->getKey()]))  {
                $byModels[$dev->getModel()->getKey()] = 0;
            }
            $byModels[$dev->getModel()->getKey()] += 1;
        }
//        $interfaces = $this->deviceInterfaceStorage->fetchAll(false);
//        $byIfaceType = [];
//        foreach ($interfaces as $iface) {
//            if(!isset($byIfaceType[$iface->getType()]))  {
//                $byIfaceType[$iface->getType()] = 0;
//            }
//            $byIfaceType[$iface->getType()] += 1;
//        }
        return [
            'version' => _env('VERSION', '0.0.0'),
            'count_groups' => count($this->deviceGroupStorage->fetchAll()),
            'count_devices' => $this->deviceStorage->getCountDevices(),
            'count_users' => $this->userStorage->getCountUsers(),
//            'count_interfaces' => $this->deviceInterfaceStorage->count(),
            'count_interfaces' => $this->deviceInterfaceStorage->countEnableInterfaces(),
            'count_by_models' => $byModels,
//            'interfaces_stat' => $byIfaceType,
        ];
    }

}
