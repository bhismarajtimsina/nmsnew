<?php

namespace WCAA\Api\Actions\Devices;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Exceptions\SupportException;
use WCAA\Models\Devices\Device;
use WCAA\Storage\Devices\DeviceAccessStorage;
use WCAA\Storage\Devices\DeviceModelStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\SwitcherCore\SwitcherCore;

/**
 * @OA\Post(
 *   path="/device-detect",
 *   tags={"device"},
 *   security={{"XAuthKey": {}}},
 *   summary="Detect device model by IP and access profile",
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       type="object",
 *       required={"ip","access_id"},
 *       @OA\Property(property="ip", type="string", example="192.168.1.10"),
 *       @OA\Property(property="access_id", type="integer", example=3)
 *     )
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Detected device data",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", ref="#/components/schemas/Device")
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized"),
 *   @OA\Response(response=403, description="Forbidden")
 * )
 */
class DetectDeviceAction extends PrivateAction
{
    protected $forbiddenInDemo = true;
    /**
     * @Inject
     * @var SwitcherCore
     */
    protected $switcherCore;

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @Inject
     * @var DeviceAccessStorage
     */
    protected $accessStorage;

    /**
     * @Inject
     * @var DeviceModelStorage
     */
    protected $modelStorage;


    protected function action(): Response
    {
        $data = $this->getFormData();
        $access = $this->accessStorage->getById($data['access_id']);

        try {
            $device = (new Device())
                ->setIp($data['ip'])
                ->setAccess($access);

            $system = $this->switcherCore->getCore($device)->action('system');
            $model = $this->modelStorage->getByKey($system['meta']['key']);
            if (!$model) {
                throw new SupportException("device '{$system['meta']['name']}' not supported by agent at this time");
            }
            $device->setModel($model)
                ->setLocation($system['location'])
                ->setMac(isset($system['mac_addr']) ? $system['mac_addr'] : '')
                ->setSerial(isset($system['serial_num']) ? $system['serial_num'] : '')
                ->setName($system['name']);
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), "SNMPException: Error in packet at") !== false) {
                throw new SupportException("Device '{$data['ip']}' not supported auto detect(fill). Please, choose model manually");
            }
            throw $e;
        }
        return $this->respondWithData($device->getAsArray());
    }

}
