<?php


namespace WCAA\Api\Actions\Devices\Groups;


use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use WCAA\Api\Actions\Action;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Storage\Devices\DeviceAccessStorage;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Storage\Devices\DeviceGroupStorage;

/**
 * @OA\Put(
 *   path="/device-group/{id}",
 *   tags={"device-group"},
 *   security={{"XAuthKey": {}}},
 *   summary="Update device group",
 *   @OA\Parameter(
 *     name="id",
 *     in="path",
 *     required=true,
 *     @OA\Schema(type="integer", example=4)
 *   ),
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="name", type="string", example="Core"),
 *       @OA\Property(property="description", type="string", nullable=true, example="Core devices")
 *     )
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Updated device group",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", ref="#/components/schemas/DeviceGroup")
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class EditGroupAction extends PrivateAction
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
        $id = $this->request->getAttribute('id');
        $object = $this->storage->getById($id);
        $data = $this->getFormData();
        if(isset($data['name'])) {
            $object->setName($data['name']);
        }
        if(isset($data['description'])) {
            $object->setDescription($data['description']);
        }
        $object = $this->storage->update($object);
        $this->addActionSuccess("device-group:edited", "Group with name {$object->getName()} success edited");
        return  $this->respondWithData($object->getAsArray());
    }

}
