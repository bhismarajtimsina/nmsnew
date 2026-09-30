<?php


namespace WCAA\Api\Actions\Devices\Groups;


use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use WCAA\Api\Actions\Action;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Models\Devices\DeviceAccess;
use WCAA\Models\Devices\DeviceGroup;
use WCAA\Storage\Devices\DeviceAccessStorage;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Storage\Devices\DeviceGroupStorage;

/**
 * @OA\Post(
 *   path="/device-group",
 *   tags={"device-group"},
 *   security={{"XAuthKey": {}}},
 *   summary="Create device group",
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       type="object",
 *       required={"name"},
 *       @OA\Property(property="name", type="string", example="Core"),
 *       @OA\Property(property="description", type="string", nullable=true, example="Core devices")
 *     )
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Created device group",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", ref="#/components/schemas/DeviceGroup")
 *     )
 *   ),
 *   @OA\Response(response=400, description="Bad Request"),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class AddGroupAction extends PrivateAction
{
    protected $forbiddenInDemo = true;
    /**
     * @Inject
     * @var DeviceGroupStorage
     */
    protected $storage;

    /**
     * @return Response
     */
    protected function action(): Response
    {
        $object = new DeviceGroup();
        $data = $this->getFormData();
        if(isset($data['name'])) {
            $object->setName($data['name']);
        } else {
            throw new HttpBadRequestException($this->request, "Name is required");
        }
        if(isset($data['description'])) {
            $object->setDescription($data['description']);
        }
        $object = $this->storage->add($object);
        $this->addActionSuccess("device-group:added", "Group with name {$object->getName()} success added");
        return  $this->respondWithData($object->getAsArray());
    }

}
