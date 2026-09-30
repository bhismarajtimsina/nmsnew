<?php

namespace WCAA\Api\Actions\Devices;

use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use SwitcherCore\Exceptions\ModuleNotFoundException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Exceptions\SwitcherCore\SnmpException;
use WCAA\Exceptions\SwitcherCore\SwitcherCoreException;
use WCAA\Services\DeviceInfoServices;
use WCAA\Storage\Devices\DeviceModelStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\SwitcherCore\SwitcherCore;
/**
 * Возвращает данные с железа путём опроса snmp который
 * происходит в методе buildCoreWithoutModel
 */

class CompareDeviceModelAction extends PrivateAction
{
    /**
     * @OA\Get(
     *   path="/device/{id}/compare-model",
     *   tags={"device"},
     *        security={{"XAuthKey": {}}},
     *   summary="Compare device model from DB and hardware",
     *
     *   @OA\Parameter(
     *     name="id",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="integer")
     *   ),
     *
     *   @OA\Response(
     *     response=200,
     *     description="Successful operation",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(
     *         property="real_model",
     *         type="object",
     *         @OA\Property(property="key", type="string"),
     *         @OA\Property(property="name", type="string")
     *       ),
     *       @OA\Property(
     *         property="key_from_db",
     *         type="object",
     *         @OA\Property(property="meta_key", type="string", nullable=true),
     *         @OA\Property(property="name", type="string", nullable=true)
     *       ),
     *       @OA\Property(property="is_different", type="string")
     *     )
     *   ),
     *
     *   @OA\Response(response=401, description="Unauthorized")
     * )
     */
    protected $deviceInfoServices;

    /**
     * @Inject
     * @var DeviceInfoServices
     */
    public function setDeviceInfoServices(DeviceInfoServices $svc): void { $this->deviceInfoServices = $svc; }

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @Inject
     * @var DeviceModelStorage
     */
    protected $modelStorage;

    /**
     * @Inject
     * @var SwitcherCore
     */
    protected $switcherCore;

    /**
     * @throws ModuleNotFoundException
     * @throws SnmpException
     * @throws SwitcherCoreException
     */
    protected function action(): Response
    {
        $id = $this->request->getAttribute('id');
        $device = $this->deviceStorage->getById($id);

        if (!$device) {
            throw new \Exception("Device not found");
        }

        $core = $this->deviceInfoServices->buildCoreWithoutModel($device);

        $system = $core->action('system');
        $keyFromRealDevice = $system['meta']['key'];
        $nameFromRealDevice = $system['meta']['name'];

        $keyFromDb = $device->getModel() ? $device->getModel()->getKey() : null;
        $nameFromDb = $device->getModel() ? $device->getModel()->getName() : null;

        return $this->respondWithData([
            "real_model" => [
                "key" => $keyFromRealDevice,
                "name" => $nameFromRealDevice,
            ],
            "key_from_db" => [
                "meta_key" => $keyFromDb,
                "name" => $nameFromDb,
            ],
            "is_different" => $keyFromDb !== $keyFromRealDevice ? "true" : "false",
        ]);
    }
}
