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

/**
 * @OA\Get(
 *   path="/device-interface/by-bind-key/{device_id}/{bind_key}",
 *   tags={"device-interface"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get interface by device and bind key",
 *   @OA\Parameter(name="device_id", in="path", required=true, @OA\Schema(type="integer", example=101)),
 *   @OA\Parameter(name="bind_key", in="path", required=true, @OA\Schema(type="string", example="1/0/1")),
 *   @OA\Response(
 *     response=200,
 *     description="Device interface",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", ref="#/components/schemas/DeviceInterface")
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized"),
 *   @OA\Response(response=404, description="Not Found")
 * )
 */
class GetInterfaceByBindKey extends PrivateAction
{
    /**
     * @Inject
     * @var DeviceInterfaceStorage
     */
    protected $storage;

    /**
     * @return Response
     */
    protected function action(): Response
    {
        $deviceId = $this->request->getAttribute('device_id');
        $bindKey = $this->request->getAttribute('bind_key');
        $iface = $this->storage->getByDeviceAndKey(new Device($deviceId), $bindKey)->getAsArray();
        $iface['device'] = [
            'id' => $deviceId,
        ];
        if(!$iface['params']) {
            $iface['params'] =  new \stdClass();;
        }
        return $this->respondWithData($iface);
    }
}
