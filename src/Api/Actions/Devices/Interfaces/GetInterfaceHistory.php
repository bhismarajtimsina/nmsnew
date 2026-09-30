<?php


namespace WCAA\Api\Actions\Devices\Interfaces;


use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use WCAA\Api\Actions\PrivateAction;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Storage\Devices\DeviceInterfaceHistoryStorage;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceStorage;

/**
 * @OA\Get(
 *   path="/device-interface/history/{interface_id}",
 *   tags={"device-interface"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get interface history",
 *   @OA\Parameter(name="interface_id", in="path", required=true, @OA\Schema(type="integer", example=5001)),
 *   @OA\Response(
 *     response=200,
 *     description="Interface history records",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/DeviceInterfaceHistory"))
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class GetInterfaceHistory extends PrivateAction
{
    /**
     * @Inject
     * @var DeviceInterfaceStorage
     */
    protected $storage;


    /**
     * @Inject
     * @var DeviceInterfaceHistoryStorage
     */
    protected $deviceHistoryStorage;

    /**
     * @return Response
     */
    protected function action(): Response
    {
        $ifaceId = $this->request->getAttribute('interface_id');
        $iface = $this->storage->getById($ifaceId);
        $historyTable = array_map(function ($i) {
            return $i->getAsArray();
            }, $this->deviceHistoryStorage->getByInterface($iface, false, 100));

        return $this->respondWithData($historyTable);
    }
}
