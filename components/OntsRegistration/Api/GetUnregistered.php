<?php

namespace WCC\OntsRegistration\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\OntsRegistration\Controllers\MacrosGateway;
/**
 * @OA\Get(
 *   path="/component/onts_registration/unregistered",
 *   tags={"onts-registration"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get unregistered ONTs",
 *   @OA\Parameter(name="from", in="query", required=false, @OA\Schema(type="string", enum={"device","cache","store"}, default="cache")),
 *   @OA\Response(response=200, description="ONT list", @OA\JsonContent(type="object", @OA\Property(property="data", type="array", @OA\Items(type="object", additionalProperties=true))))
 * )
 *
 * @OA\Get(
 *   path="/component/onts_registration/unregistered/{device_id}",
 *   tags={"onts-registration"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get unregistered ONTs for device",
 *   @OA\Parameter(name="device_id", in="path", required=true, @OA\Schema(type="integer", example=101)),
 *   @OA\Parameter(name="from", in="query", required=false, @OA\Schema(type="string", enum={"device","cache","store"}, default="cache")),
 *   @OA\Response(response=200, description="ONT list", @OA\JsonContent(type="object", @OA\Property(property="data", type="array", @OA\Items(type="object", additionalProperties=true))))
 * )
 */

class GetUnregistered extends PrivateAction
{
    
    /**
     * @Inject
     * @var MacrosGateway
     */
    protected $gateway;

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @return Response
     */
    protected function action(): Response
    {

        $this->gateway->setUser($this->user);
        $device = null;
        if($devId = $this->request->getAttribute('device_id', false)) {
            $device = $this->deviceStorage->getById($devId);
        }
        $from = 'cache';
        if(isset($this->request->getQueryParams()['from'])) {
            $from = $this->request->getQueryParams()['from'];
        }
        return  $this->respondWithData($this->gateway->getUnregistered($device, $from));
    }
}
