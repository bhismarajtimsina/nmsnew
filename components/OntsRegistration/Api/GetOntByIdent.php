<?php

namespace WCC\OntsRegistration\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\OntsRegistration\Controllers\MacrosGateway;
/**
 * @OA\Get(
 *   path="/component/onts_registration/by-ident/{device_id}/{ident}",
 *   tags={"onts-registration"},
 *   security={{"XAuthKey": {}}},
 *   summary="Find ONT by identifier",
 *
 *   @OA\Parameter(
 *     name="device_id",
 *     in="path",
 *     required=true,
 *     @OA\Schema(type="integer", example=101)
 *   ),
 *   @OA\Parameter(
 *     name="ident",
 *     in="path",
 *     required=true,
 *     @OA\Schema(type="string", example="48575443A1B2")
 *   ),
 *
 *   @OA\Response(
 *     response=200,
 *     description="ONT data",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="data", type="object", nullable=true, additionalProperties=true)
 *     )
 *   ),
 *
 *   @OA\Response(
 *     response=400,
 *     description="Device does not support ONT search by ident"
 *   ),
 *
 *   @OA\Response(
 *     response=404,
 *     description="Device not found"
 *   )
 * )
 */

class GetOntByIdent extends PrivateAction
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

    protected function action(): Response
    {
        $this->gateway->setUser($this->user);
        $device = $this->deviceStorage->getById($this->request->getAttribute('device_id'));
        for($repeats = 0; $repeats < 9; $repeats++) {
            $ont = $this->gateway->findOnuByIdent($device, $this->request->getAttribute('ident'));
            if($ont !== null) {
                break;
            }
            sleep(6);
        }
        return $this->respondWithData($ont);
    }
}
