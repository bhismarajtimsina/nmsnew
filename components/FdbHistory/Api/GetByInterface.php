<?php


namespace WCC\FdbHistory\Api;


use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use WCAA\Api\Actions\PrivateAction;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Models\Devices\Device;
use WCAA\Models\Devices\DeviceInterface;
use WCAA\Models\SystemAction;
use WCAA\Storage\PollerData\FdbHistoryStorage;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\SystemActionsStorage;
/**
 * @OA\Get(
 *   path="/component/fdb_history/{device}",
 *   tags={"fdb-history"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get FDB history by device",
 *   @OA\Parameter(name="device", in="path", required=true, @OA\Schema(type="integer", example=101)),
 *   @OA\Parameter(name="active", in="query", required=false, @OA\Schema(type="string", enum={"yes","no"}, example="yes")),
 *   @OA\Response(
 *     response=200,
 *     description="FDB history records",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(type="object", additionalProperties=true))
 *     )
 *   )
 * )
 *
 * @OA\Get(
 *   path="/component/fdb_history/{device}/{interface}",
 *   tags={"fdb-history"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get FDB history by device interface",
 *   @OA\Parameter(name="device", in="path", required=true, @OA\Schema(type="integer", example=101)),
 *   @OA\Parameter(name="interface", in="path", required=true, @OA\Schema(type="string", example="1/0/1")),
 *   @OA\Parameter(name="active", in="query", required=false, @OA\Schema(type="string", enum={"yes","no"}, example="yes")),
 *   @OA\Response(
 *     response=200,
 *     description="FDB history records",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(type="object", additionalProperties=true))
 *     )
 *   )
 * )
 */

class GetByInterface extends PrivateAction
{
    
    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @Inject
     * @var DeviceInterfaceStorage
     */
    protected $interfaceStorage;

    /**
     * @Inject
     * @var SystemActionsStorage
     */
    protected $systemActionsStorage;

    /**
     * @Inject
     * @var FdbHistoryStorage
     */
    protected $historyStorage;

    /**
     * @return Response
     */

    protected function action(): Response
    {
        $queries = $this->request->getQueryParams();
        $onlyActive = isset($queries['active']) && $queries['active'] == 'yes';
        $dev = new Device();
        $interface = new DeviceInterface();
        try {
            $dev = $this->deviceStorage->getById($this->request->getAttribute('device'));
            if($ifaceAttr = $this->request->getAttribute('interface')) {
                $interface = $this->interfaceStorage->getByDeviceAndKey($dev, $ifaceAttr);
                $data = $this->historyStorage->getByInterface($interface, $onlyActive);
            } else {
                $data = $this->historyStorage->getByDevice($dev, $onlyActive);
            }
            $response = [];
            foreach ($data as $d) {
                $response[] = [
                    'interface' => [
                        'device' => [
                            'id' => $d->getInterface()->getDevice()->getId(),
                            'ip' => $d->getInterface()->getDevice()->getIp(),
                        ],
                        'bind_key' => $d->getInterface()->getBindKey(),
                        'id' => $d->getInterface()->getId(),
                        'name' => $d->getInterface()->getName(),
                        'type' => $d->getInterface()->getType(),
                    ],
                    'vlan_id' => $d->getVlanId(),
                    'mac_address' => $d->getMacAddress(),
                    'start_at' => $d->getStartAt(),
                    'stop_at' => $d->getStopAt(),
                    'id' => $d->getId(),
                    'active' => $d->getStopAt() === null,
                ];
            }
            return $this->respondWithData($response);
        } catch (\Throwable $e) {
            $this->systemActionsStorage->add(SystemAction::init(
                $this->user,
                'fdb_history:by_interface',
                SystemAction::STATUS_FAILED,
                "Error get fdb history by {$dev->getName()} ({$dev->getIp()}) and interface {$interface->getName()}",
                ['device' => $dev, 'interface' => $interface, 'error' => [
                    'message' => $e->getMessage(),
                    'code' => $e->getCode(),
                    'line' => $e->getLine(),
                    'file' => $e->getFile(),
                    'trace' => $e->getTraceAsString(),
                ]]
            ));
            throw $e;
        }
    }

}
