<?php


namespace WCAA\Api\Actions\Devices\Models;


use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use WCAA\Api\Actions\Action;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Models\Devices\DeviceModel;
use WCAA\Storage\Devices\DeviceModelStorage;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * @OA\Delete(
 *   path="/device-model/{id}",
 *   tags={"device-model"},
 *   security={{"XAuthKey": {}}},
 *   summary="Delete device model",
 *   description="Deletes device model by id.",
 *   @OA\Parameter(
 *     name="id",
 *     in="path",
 *     required=true,
 *     description="Device model ID",
 *     @OA\Schema(type="integer", example=12)
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Device model successfully deleted",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="boolean", example=true)
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized"),
 *   @OA\Response(response=404, description="Not Found")
 * )
 */
class DeleteModelAction extends PrivateAction
{
    protected $forbiddenInDemo = true;
    /**
     * @Inject
     * @var DeviceModelStorage
     */
    protected $storage;

    /**
     * @return Response
     */
    protected function action(): Response
    {
        $id = $this->request->getAttribute('id');
        $this->storage->delete(new DeviceModel($id));
        return  $this->respondWithData(true);
    }

}
