<?php


namespace WCAA\Api\Actions\Devices\Interfaces;


use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use WCAA\Api\Actions\Action;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Models\Devices\DeviceAccess;
use WCAA\Models\Devices\DeviceInterface;
use WCAA\Storage\Devices\DeviceAccessStorage;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceStorage;

/**
 * @OA\Post(
 *   path="/device-interface",
 *   tags={"device-interface"},
 *   security={{"XAuthKey": {}}},
 *   summary="Create device interface",
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       type="object",
 *       required={"device","bind_key","type"},
 *       @OA\Property(
 *         property="device",
 *         type="object",
 *         @OA\Property(property="id", type="integer", example=101)
 *       ),
 *       @OA\Property(property="bind_key", type="string", example="1/0/1"),
 *       @OA\Property(property="type", type="string", example="ethernet"),
 *       @OA\Property(property="name", type="string", nullable=true, example="Gi1/0/1"),
 *       @OA\Property(property="params", type="object", nullable=true, additionalProperties=true),
 *       @OA\Property(property="status", type="string", nullable=true, example="up"),
 *       @OA\Property(property="billing_link", type="string", nullable=true),
 *       @OA\Property(property="ip", type="string", nullable=true),
 *       @OA\Property(property="agreement", type="string", nullable=true),
 *       @OA\Property(property="description", type="string", nullable=true),
 *       @OA\Property(property="comment", type="string", nullable=true),
 *       @OA\Property(property="coordinates", type="string", nullable=true)
 *     )
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Created interface",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", ref="#/components/schemas/DeviceInterface")
 *     )
 *   ),
 *   @OA\Response(response=400, description="Bad Request"),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class AddDeviceInterfaceAction extends PrivateAction
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
        $iface = new DeviceInterface();
        $data = $this->getFormData();
        if(!isset($data['device']) || !isset($data['device']['id'])) {
            throw new HttpBadRequestException($this->request, "Device is required");
        } else {
            $iface->setDevice($this->deviceStorage->getById($data['device']['id']));
        }
        if(!isset($data['bind_key'])) {
            throw new HttpBadRequestException($this->request, "bind_key is required");
        } else {
            $iface->setBindKey($data['bind_key']);
        }
        if(!isset($data['type'])) {
            throw new HttpBadRequestException($this->request, "Type is required");
        }

        if(isset($data['name'])) $iface->setName($data['name']);
        if(isset($data['type'])) $iface->setType($data['type']);
        if(isset($data['params'])) $iface->setParams($data['params']);
        if(isset($data['status'])) $iface->setStatus($data['status']);
        if(isset($data['billing_link'])) $iface->setBillingLink($data['billing_link']);
        if(isset($data['ip'])) $iface->setIp($data['ip']);
        if(isset($data['agreement'])) $iface->setAgreement($data['agreement']);
        if(isset($data['description'])) $iface->setDescription($data['description']);
        if(isset($data['comment'])) $iface->setComment($data['description']);
        if(isset($data['coordinates'])) $iface->setCoordinates($data['coordinates']);

        $access = $this->storage->add($iface);
        return  $this->respondWithData($access->getAsArray());
    }

}
