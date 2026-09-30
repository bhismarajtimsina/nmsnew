<?php


namespace WCAA\Api\Actions\Devices\Models;


use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\Action;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Storage\Devices\DeviceModelStorage;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * @OA\Put(
 *   path="/device-model/{id}",
 *   tags={"device-model"},
 *   security={{"XAuthKey": {}}},
 *   summary="Update device model",
 *   description="Updates device model by id.",
 *   @OA\Parameter(
 *     name="id",
 *     in="path",
 *     required=true,
 *     description="Device model ID",
 *     @OA\Schema(type="integer", example=12)
 *   ),
 *   @OA\RequestBody(
 *     required=false,
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="name", type="string", example="Cisco C9300"),
 *       @OA\Property(
 *         property="params",
 *         type="object",
 *         nullable=true,
 *         additionalProperties=true
 *       )
 *     )
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Device model successfully updated",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", ref="#/components/schemas/DeviceModel")
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized"),
 *   @OA\Response(response=404, description="Not Found")
 * )
 */
class EditModelAction extends PrivateAction
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
        $Model = $this->storage->getById($id);
        $data = $this->getFormData();
        if(isset($data['name'])) {
            $Model->setName($data['name']);
        }

        if(isset($data['params'])) {

            $Model->setParams($data['params']);
        }
        $Model->setIcon(null);
        $Model = $this->storage->update($Model);
        return  $this->respondWithData($Model->getAsArray());
    }

}
