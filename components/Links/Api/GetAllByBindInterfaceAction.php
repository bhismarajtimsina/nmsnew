<?php

namespace WCC\Links\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Models\Devices\Device;
/**
 * @OA\Get(
 *   path="/component/links/by-device/{device_id}/{interface_id}",
 *   tags={"links"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get links by bind key interface",
 *   @OA\Parameter(name="device_id", in="path", required=true, @OA\Schema(type="integer", example=101)),
 *   @OA\Parameter(name="interface_id", in="path", required=true, @OA\Schema(type="string", example="ge-0/0/1")),
 *   @OA\Response(
 *     response=200,
 *     description="Links list",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/LinkLite"))
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */

class GetAllByBindInterfaceAction extends BaseApiAction
{
    protected function action(): Response
    {
        $interface = $this->controller
            ->getDeviceInterfaceStorage()
            ->getByDeviceAndKey(
                (new Device($this->request->getAttribute('device_id'))),
                $this->request->getAttribute('interface_id')
            );
        $links = array_map(function ($e) {return $e->getAsArrayLite();}, $this->controller->getByInterface($interface));
        return $this->respondWithData($links);
    }
}
