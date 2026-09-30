<?php


namespace WCAA\Api\Actions\Devices\Accesses;


use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use WCAA\Api\Actions\Action;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Models\Devices\DeviceAccess;
use WCAA\Storage\Devices\DeviceAccessStorage;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * @OA\Delete(
 *   path="/device-access/{id}",
 *   tags={"device-access"},
 *   security={{"XAuthKey": {}}},
 *   summary="Delete device access profile",
 *   @OA\Parameter(
 *     name="id",
 *     in="path",
 *     required=true,
 *     @OA\Schema(type="integer", example=5)
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
class DeleteAccessAction extends PrivateAction
{
    protected $forbiddenInDemo = true;
    /**
     * @Inject
     * @var DeviceAccessStorage
     */
    protected $storage;

    /**
     * @return Response
     */
    protected function action(): Response
    {
        $id = $this->request->getAttribute('id');
        $access = $this->storage->getById($id);
        $this->storage->delete($access);
        $this->addActionSuccess("device-access:deleted", "Access with name {$access->getName()} success deleted" );
        return  $this->respondWithData(true);
    }

}
