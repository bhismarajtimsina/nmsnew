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
use WCAA\Storage\Devices\DeviceGroupStorage;

/**
 * @OA\Delete(
 *   path="/device-group/{id}",
 *   tags={"device-group"},
 *   security={{"XAuthKey": {}}},
 *   summary="Delete device group",
 *   @OA\Parameter(
 *     name="id",
 *     in="path",
 *     required=true,
 *     @OA\Schema(type="integer", example=4)
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Deletion status",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="boolean", example=true)
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class DeleteGroupAction extends PrivateAction
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
        $this->storage->delete($object);
        $this->addActionSuccess("device-group:deleted", "Group with name {$object->getName()} success deleted");
        return  $this->respondWithData(true);
    }

}
