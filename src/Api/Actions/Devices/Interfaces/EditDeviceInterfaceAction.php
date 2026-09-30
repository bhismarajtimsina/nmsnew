<?php


namespace WCAA\Api\Actions\Devices\Interfaces;


use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\Action;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Models\Devices\Device;
use WCAA\Models\Devices\DeviceInterface;
use WCAA\Storage\Devices\DeviceAccessStorage;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Storage\Devices\DeviceInterfaceStorage;

/**
 * @OA\Put(
 *   path="/device-interface/{id}",
 *   tags={"device-interface"},
 *   security={{"XAuthKey": {}}},
 *   summary="Update device interface",
 *   @OA\Parameter(
 *     name="id",
 *     in="path",
 *     required=true,
 *     @OA\Schema(type="integer", example=5001)
 *   ),
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="bind_key", type="string", nullable=true, example="1/0/1"),
 *       @OA\Property(property="type", type="string", nullable=true, example="ethernet"),
 *       @OA\Property(property="name", type="string", nullable=true, example="Gi1/0/1"),
 *       @OA\Property(property="status", type="string", nullable=true, example="up"),
 *       @OA\Property(property="device", type="object", @OA\Property(property="id", type="integer", example=101)),
 *       @OA\Property(property="params", type="object", nullable=true, additionalProperties=true),
 *       @OA\Property(property="poll_enabled", type="boolean", nullable=true),
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
 *     description="Updated interface",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", ref="#/components/schemas/DeviceInterface")
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class EditDeviceInterfaceAction extends PrivateAction
{
    protected $forbiddenInDemo = true;
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
        $id = $this->request->getAttribute('id');
        $iface = $this->storage->getById($id);
        $data = $this->getFormData();
        if(isset($data['type'])) $iface->setType($data['type']);
        if(isset($data['status'])) $iface->setStatus($data['status']);
        if(isset($data['name'])) $iface->setName($data['name']);
        if(isset($data['type'])) $iface->setType($data['type']);
        if(isset($data['params'])) $iface->setParams($data['params']);
        if(isset($data['bind_key'])) $iface->setBindKey($data['bind_key']);
        if(isset($data['device']['id'])) $iface->setDevice(new Device($data['device']['id']));
        if(isset($data['ip'])) $iface->setIp($data['ip']);
        if(isset($data['status'])) $iface->setStatus($data['status']);
        if(isset($data['billing_link'])) $iface->setBillingLink($data['billing_link']);
        if(isset($data['agreement'])) $iface->setAgreement($data['agreement']);
        if(isset($data['description'])) $iface->setDescription($data['description']);
        if(isset($data['poll_enabled'])) $iface->setPollEnabled($data['poll_enabled']);
        if(isset($data['coordinates'])) $iface->setCoordinates($data['coordinates']);
        if(isset($data['comment'])) $iface->setComment($data['comment']);
        $iface = $this->storage->update($iface);
        $resp = $iface->getAsArray();
        if(!$resp['params']) {
            $resp['params'] =  new \stdClass();
        }
        return  $this->respondWithData($resp);
    }

}
