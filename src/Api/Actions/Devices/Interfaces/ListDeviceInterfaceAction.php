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
 *   path="/device-interface",
 *   tags={"device-interface"},
 *   security={{"XAuthKey": {}}},
 *   summary="List device interfaces",
 *   description="Optional query params: device_id, bind_key, types, agreement, ip.",
 *   @OA\Parameter(name="device_id", in="query", required=false, @OA\Schema(type="integer", example=101)),
 *   @OA\Parameter(name="bind_key", in="query", required=false, @OA\Schema(type="string", example="1/0/1")),
 *   @OA\Parameter(name="types", in="query", required=false, description="Comma-separated interface types", @OA\Schema(type="string", example="ethernet,gpon")),
 *   @OA\Parameter(name="agreement", in="query", required=false, @OA\Schema(type="string", example="12345")),
 *   @OA\Parameter(name="ip", in="query", required=false, @OA\Schema(type="string", example="10.0.0.2")),
 *   @OA\Response(
 *     response=200,
 *     description="Interfaces list",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/DeviceInterface"))
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 * @OA\Get(
 *   path="/device-interface/{id}",
 *   tags={"device-interface"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get device interface by id",
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer", example=5001)),
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
class ListDeviceInterfaceAction extends PrivateAction
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

        if ($id = $this->request->getAttribute('id')) {
            $iface = $this->storage->getById($id);
            return $this->respondWithData($iface->getAsArray());
        }
        $params = $this->request->getQueryParams();
        if (isset($params['device_id']) && isset($params['bind_key'])) {
            $interfaces = $this->storage->getByDeviceAndKey(new Device($params['device_id']), $params['bind_key']);
        } elseif (isset($params['device_id'])) {
            $interfaces = $this->storage->getByDevice(new Device($params['device_id']));
        } else {
            $interfaces = $this->storage->fetchAll();
        }
        $response = [];
        $types = [];
        if(isset($params['types'])) {
            $types = explode(",", $params['types']);
        }
        foreach ($interfaces as $interface) {
            if ($types && !in_array($interface['type'], $types)) continue;
            if (isset($params['agreement']) && $interface->getAgreement() !== $params['agreement']) continue;
            if (isset($params['ip']) && $interface->getAgreement() !== $params['ip']) continue;
            $iface = $interface->getAsArray();
            $iface['device'] = [
                'id' => (int)$iface['device']['id'],
            ];
            $response[] = $iface;
        }
        return $this->respondWithData($response);
    }
}
