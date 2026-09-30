<?php

namespace WCC\Links\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Models\Devices\DeviceInterface;
use WCC\Links\Models\Link;
/**
 * @OA\Get(
 *   path="/component/links/options/devices",
 *   tags={"links"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get devices list for links options",
 *   @OA\Response(
 *     response=200,
 *     description="Devices list",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/LinksOptionDevice"))
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */

class GetDevicesList extends BaseApiAction
{
    protected function action(): Response
    {
        $devices = array_map(function ($e) {
            $arr = $e->getAsArrayLite();
            return [
                'id' => $arr['id'],
                'name' => $arr['name'],
                'ip' => $arr['ip'],
            ];
        }, $this->getController()->getDeviceStorage()->fetchAll());
        return $this->respondWithData($devices);
    }
}
