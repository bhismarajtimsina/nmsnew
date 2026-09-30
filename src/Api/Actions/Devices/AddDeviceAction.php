<?php


namespace WCAA\Api\Actions\Devices;


use DI\Annotation\Inject;
use WCAA\Api\Actions\Action;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Exceptions\StorageExceptions\DeviceAlreadyExisted;
use WCAA\Models\Devices\Device;
use WCAA\Storage\Devices\DeviceAccessStorage;
use WCAA\Storage\Devices\DeviceGroupStorage;
use WCAA\Storage\Devices\DeviceModelStorage;
use WCAA\Storage\Devices\DeviceStorage;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Storage\Exceptions\RecordNotFoundException;
/**
 * @OA\Post(
 *   path="/device",
 *   tags={"device"},
 *   security={{"XAuthKey": {}}},
 *   summary="Add new device",
 *   description="Creates a new device. Required: name, ip, access.id, model.id, group.id. Use header x-auth-key.",
 *
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       type="object",
 *       required={"name","ip","access","model","group"},
 *       example={
 *         "name":"Core-Switch-01",
 *         "ip":"192.168.1.10",
 *         "access":{"id":2},
 *         "model":{"id":12},
 *         "group":{"id":-1}
 *       },
 *
 *       @OA\Property(property="name", type="string", example="Core-Switch-01"),
 *       @OA\Property(property="ip", type="string", example="192.168.1.10"),
 *
 *       @OA\Property(
 *         property="access",
 *         type="object",
 *         required={"id"},
 *         @OA\Property(property="id", type="integer", example=2)
 *       ),
 *
 *       @OA\Property(
 *         property="model",
 *         type="object",
 *         required={"id"},
 *         @OA\Property(property="id", type="integer", example=12)
 *       ),
 *
 *       @OA\Property(
 *         property="group",
 *         type="object",
 *         required={"id"},
 *         @OA\Property(property="id", type="integer", example=-1)
 *       )
 *     )
 *   ),
 *
 *   @OA\Response(
 *     response=200,
 *     description="Device successfully created",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(
 *         property="data",
 *         type="object",
 *         @OA\Property(property="id", type="integer", example=123),
 *         @OA\Property(property="name", type="string"),
 *         @OA\Property(property="ip", type="string"),
 *         @OA\Property(property="access", type="object"),
 *         @OA\Property(property="model", type="object"),
 *         @OA\Property(property="group", type="object")
 *       )
 *     )
 *   ),
 *
 *   @OA\Response(
 *     response=400,
 *     description="Bad Request (missing required fields)",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=400),
 *       @OA\Property(property="error", type="string", example="Bad Request"),
 *       @OA\Property(property="message", type="string", example="name is required")
 *     )
 *   ),
 *
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */


class AddDeviceAction extends PrivateAction
{
    protected $forbiddenInDemo = true;
    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $storage;

    /**
     * @Inject
     * @var DeviceModelStorage
     */
    protected $modelStorage;
    /**
     * @Inject
     * @var DeviceAccessStorage
     */
    protected $accessStorage;
    /**
     * @Inject
     * @var DeviceGroupStorage
     */
    protected $deviceGroupStorage;


    /**
     * @return Response
     */
    protected function action(): Response
    {
        $device = new Device();
        $data = $this->getFormData();

        if(isset($data['enabled'])) {
            $device->setEnabled($data['enabled']);
        }

        if(isset($data['name'])) {
            $device->setName(trim($data['name']));
        } else {
            throw new HttpBadRequestException($this->request, "name is required");
        }
        if(isset($data['ip'])) {
            $device->setIp(trim($data['ip']));
        } else {
            throw new HttpBadRequestException($this->request, "ip is required");
        }
        if(isset($data['description'])) {
            $device->setDescription($data['description']);
        }
        if(isset($data['access']['id'])) {
            $device->setAccess($this->accessStorage->getById($data['access']['id']));
        } else {
            throw new HttpBadRequestException($this->request, "access is required");
        }
        if(isset($data['model']['id'])) {
            $device->setModel($this->modelStorage->getById($data['model']['id']));
        } else {
            throw new HttpBadRequestException($this->request, "model is required");
        }
        if(isset($data['mac'])) {
            $device->setMac(trim($data['mac']));
        }
        if(isset($data['serial'])) {
            $device->setSerial(trim($data['serial']));
        }
        if(isset($data['location'])) {
            $device->setLocation(trim($data['location']));
        }

        if(isset($data['pollers'])) {
            $device->setPollers($data['pollers']);
        }

        if(isset($data['params'])) {
            $device->setParams($data['params']);
        }

        if(isset($data['group']['id'])) {
            $device->setGroup($this->deviceGroupStorage->getById($data['group']['id']));
        }else {
            throw new HttpBadRequestException($this->request, "group is required");
        }


        try {
            $address = $this->storage->getByIp($device->getIp());
            if($address) {
                throw new DeviceAlreadyExisted("Device with IP {$device->getIp()} already exist");
            }
        } catch (RecordNotFoundException $e) {}

        $dev = $this->storage->add($device);
        $this->addActionSuccess('device:added', "Device {$device->getIp()} ({$device->getName()}) success added", [
            'ip' => $device->getIp(),
            'model' => $device->getModel()->getName(),
            'access' => $device->getAccess()->getName(),
        ],$dev);
        return  $this->respondWithData($dev->getAsArray());
    }

}
