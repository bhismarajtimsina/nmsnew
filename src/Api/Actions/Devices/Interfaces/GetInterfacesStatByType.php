<?php


namespace WCAA\Api\Actions\Devices\Interfaces;


use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use WCAA\Api\Actions\Action;
use WCAA\Api\Actions\PrivateAction;
use WCAA\App;
use WCAA\Models\Devices\Device;
use WCAA\Storage\Devices\DeviceAccessStorage;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceStorage;

/**
 * @OA\Get(
 *   path="/device-interface/stat-by-types",
 *   tags={"device-interface"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get interface statistics grouped by type",
 *   @OA\Response(
 *     response=200,
 *     description="Statistics by type",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="object", additionalProperties=true)
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class GetInterfacesStatByType extends PrivateAction
{

    /**
     * @Inject
     * @var DeviceInterfaceStorage
     */
    protected $deviceInterfaceStorage;

    /**
     * @return Response
     */
    protected function action(): Response
    {
        return $this->respondWithData($this->deviceInterfaceStorage->getIfacesStatByType());
    }
}
