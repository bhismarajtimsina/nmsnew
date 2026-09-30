<?php


namespace WCAA\Api\Actions\Devices;


use DI\Annotation\Inject;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\Action;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Storage\Devices\DeviceAccessStorage;
use WCAA\Storage\Devices\DeviceGroupStorage;
use WCAA\Storage\Devices\DeviceModelStorage;
use WCAA\Storage\Devices\DeviceStorage;
use Psr\Http\Message\ResponseInterface as Response;

class EditDeviceAction extends PrivateAction
{
    /**
     * @OA\Put(
     *   path="/device/{id}",
     *   tags={"device"},
     *   security={{"XAuthKey": {}}},
     *   summary="Edit device by ID (partial update)",
     *   description="Updates only fields that are present in request body. Requires permission to device group.",
     *
     *   @OA\Parameter(
     *     name="id",
     *     in="path",
     *     required=true,
     *     description="Device ID",
     *     @OA\Schema(type="integer", example=123)
     *   ),
     *
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="name", type="string", example="Core-Switch-01"),
     *       @OA\Property(property="ip", type="string", example="192.168.1.10"),
     *       @OA\Property(property="description", type="string", nullable=true, example="Main switch in DC"),
     *       @OA\Property(property="coordinates", type="string", nullable=true, example="50.4501,30.5234"),
     *       @OA\Property(
     *         property="access",
     *         type="object",
     *         @OA\Property(property="id", type="integer", example=5)
     *       ),
     *       @OA\Property(
     *         property="model",
     *         type="object",
     *         description="You can set model by id OR by key",
     *         @OA\Property(property="id", type="integer", example=12, nullable=true),
     *         @OA\Property(property="key", type="string", example="cisco_c9300", nullable=true)
     *       ),
     *       @OA\Property(property="mac", type="string", nullable=true, example="AA:BB:CC:DD:EE:FF"),
     *       @OA\Property(property="serial", type="string", nullable=true, example="FTX1234ABC"),
     *       @OA\Property(
     *         property="params",
     *         type="object",
     *         description="Merged into existing params (array_merge)",
     *         additionalProperties=true,
     *         example={"snmp_community":"public","timeout":5}
     *       ),
     *       @OA\Property(property="location", type="string", nullable=true, example="Rack A-12"),
     *       @OA\Property(property="enabled", type="boolean", example=true),
     *       @OA\Property(
     *         property="pollers",
     *         type="array",
     *         @OA\Items(type="string"),
     *         example={"snmp","lldp"}
     *       ),
     *       @OA\Property(
     *         property="group",
     *         type="object",
     *         @OA\Property(property="id", type="integer", example=2)
     *       )
     *     )
     *   ),
     *
     *   @OA\Response(
     *     response=200,
     *     description="Successful operation",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="statusCode", type="integer", example=200),
     *       @OA\Property(
     *         property="data",
     *         type="object",
     *         description="Updated device (returned from storage->update()->getAsArray())",
     *         @OA\Property(property="id", type="integer", example=123),
     *         @OA\Property(property="name", type="string", example="Core-Switch-01"),
     *         @OA\Property(property="ip", type="string", example="192.168.1.10"),
     *         @OA\Property(property="description", type="string", nullable=true),
     *         @OA\Property(property="coordinates", type="string", nullable=true),
     *         @OA\Property(property="mac", type="string", nullable=true),
     *         @OA\Property(property="serial", type="string", nullable=true),
     *         @OA\Property(property="params", type="object", additionalProperties=true),
     *         @OA\Property(property="location", type="string", nullable=true),
     *         @OA\Property(property="enabled", type="boolean", example=true),
     *         @OA\Property(property="pollers", type="array", @OA\Items(type="string")),
     *         @OA\Property(property="group", type="object"),
     *         @OA\Property(property="access", type="object"),
     *         @OA\Property(property="model", type="object")
     *       )
     *     )
     *   ),
     *
     *   @OA\Response(response=401, description="Unauthorized"),
     *   @OA\Response(
     *     response=400,
     *     description="Bad Request / No permissions",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="statusCode", type="integer", example=400),
     *       @OA\Property(property="error", type="string", example="Bad Request"),
     *       @OA\Property(property="message", type="string", example="You dont have permission for update choosed device! Check role permissions")
     *     )
     *   )
     * )
     */

    protected $forbiddenInDemo = true;
    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $storage;

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
    /**
     * @Inject
     * @var DeviceGroupStorage
     */
    protected $deviceGroupStorage;

    /**
     * @return Response
     * @throws \Exception
     */
    protected function action(): Response
    {

        $id = $this->request->getAttribute('id');
        $device = $this->storage->getById($id);
        $oldDevice = clone $device;
        if(!array_filter($this->user->getDeviceGroups(), function ($e) use ($device){
                return $device->getGroup()->getId() === $e->getId();
            }) && ($this->user->getId() > 0  && $this->user->getRole()->getId() > 0)) {
            throw new HttpBadRequestException($this->request, "You dont have permission for update choosed device! Check role permissions");
        }

        $data = $this->getFormData();

        if(isset($data['name'])) {
            $device->setName(trim($data['name']));
        }
        if(isset($data['ip'])) {
            $device->setIp(trim($data['ip']));
        }
        if(isset($data['description'])) {
            $device->setDescription(trim($data['description']));
        }
        if(isset($data['coordinates'])) {
            $device->setCoordinates($data['coordinates']);
        }
        if(isset($data['access']['id'])) {
            $device->setAccess($this->accessStorage->getById($data['access']['id']));
        }
        if(isset($data['model']['id'])) {
            $device->setModel($this->modelStorage->getById($data['model']['id']));
        }
        if (isset($data['model']['key'])) {
            $device->setModel($this->modelStorage->getByKey($data['model']['key']));
        }
        if(isset($data['mac'])) {
            $device->setMac($data['mac']);
        }
        if(isset($data['serial'])) {
            $device->setSerial($data['serial']);
        }
        if(isset($data['params'])) {
            $oldParams = $device->getParams() ?? [];
            $newParams = array_merge($oldParams, $data['params']);
            $device->setParams($newParams);
        }
        if(isset($data['location'])) {
            $device->setLocation($data['location']);
        }
        if(isset($data['enabled'])) {
            $device->setEnabled($data['enabled']);
        }
        if(isset($data['pollers'])) {
            $device->setPollers($data['pollers']);
        }
        if(isset($data['group']['id'])) {
            $device->setGroup($this->deviceGroupStorage->getById($data['group']['id']));
        }
        $access = $this->storage->update($device);

        $this->addActionSuccess('device:updated', "Device {$device->getIp()} ({$device->getName()}) success updated", [
            'old' => $oldDevice->getAsArray(),
            'new' => $device->getAsArray(),
        ],
        $device
        );
        return  $this->respondWithData($access->getAsArray());
    }

}
