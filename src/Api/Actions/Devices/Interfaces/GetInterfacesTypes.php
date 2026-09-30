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
 *   path="/device-interface/types",
 *   tags={"device-interface"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get supported interface types",
 *   @OA\Response(
 *     response=200,
 *     description="Supported interface types",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(type="string"))
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class GetInterfacesTypes extends PrivateAction
{

    /**
     * @return Response
     */
    protected function action(): Response
    {
        return $this->respondWithData(App::getInstance()->conf('types.interfaces'));
    }
}
