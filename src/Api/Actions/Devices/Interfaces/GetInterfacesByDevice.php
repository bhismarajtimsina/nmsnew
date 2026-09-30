<?php


namespace WCAA\Api\Actions\Devices\Interfaces;


use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use WCAA\Api\Actions\Action;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Models\Devices\Device;
use WCAA\Storage\Devices\DeviceAccessStorage;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceStorage;

/**
 * @OA\Get(
 *   path="/device-interface/by-device/{device_id}",
 *   tags={"device-interface"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get interfaces by device",
 *   @OA\Parameter(name="device_id", in="path", required=true, @OA\Schema(type="integer", example=101)),
 *   @OA\Response(
 *     response=200,
 *     description="Device interfaces",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/DeviceInterface"))
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class GetInterfacesByDevice extends PrivateAction
{
    /**
     * @Inject
     * @var DeviceInterfaceStorage
     */
    protected $storage;
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
        $deviceId = $this->request->getAttribute('device_id');
        $device = $this->deviceStorage->getById($deviceId);
        $iface = array_map(function ($i) {$r = $i->getAsArray();
            if(!$r['params']) {
                $r['params'] = new \stdClass();
            }
            return $r;}, $this->storage->getByDevice($device, null, false));

        return $this->respondWithData($iface);
    }
}
