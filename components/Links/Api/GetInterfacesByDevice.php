<?php

namespace WCC\Links\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Models\Devices\Device;
use WCAA\Models\Devices\DeviceInterface;
use WCC\Links\Models\Link;
/**
 * @OA\Get(
 *   path="/component/links/options/interfaces/{iface_id}",
 *   tags={"links"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get interfaces by device",
 *   @OA\Parameter(name="iface_id", in="path", required=true, @OA\Schema(type="integer", example=101)),
 *   @OA\Response(
 *     response=200,
 *     description="Interfaces list",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/LinksOptionInterface"))
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */

class GetInterfacesByDevice extends BaseApiAction
{
    protected function action(): Response
    {
        $interfaces = array_map(function ($e) {
            $arr = $e->getAsArrayLite();
            return [
                'id' => $arr['id'],
                'name' => $arr['name'],
            ];
        }, $this->getController()
            ->getDeviceInterfaceStorage()
            ->getByDevice(new Device($this->request->getAttribute('iface_id')))
        );
        return $this->respondWithData($interfaces);
    }
}
