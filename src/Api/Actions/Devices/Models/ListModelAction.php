<?php


namespace WCAA\Api\Actions\Devices\Models;


use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use WCAA\Api\Actions\Action;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Storage\Devices\DeviceModelStorage;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * @OA\Get(
 *   path="/device-model",
 *   tags={"device-model"},
 *   security={{"XAuthKey": {}}},
 *   summary="List device models",
 *   description="Returns list of device models.",
 *   @OA\Response(
 *     response=200,
 *     description="List of device models",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(
 *         property="data",
 *         type="array",
 *         @OA\Items(ref="#/components/schemas/DeviceModel")
 *       )
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 * @OA\Get(
 *   path="/device-model/{id}",
 *   tags={"device-model"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get device model by id",
 *   @OA\Parameter(
 *     name="id",
 *     in="path",
 *     required=true,
 *     description="Device model ID",
 *     @OA\Schema(type="integer", example=12)
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Device model",
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
class ListModelAction extends PrivateAction
{
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

        if ($id = $this->request->getAttribute('id')) {
            $model =  $this->storage->getById($id)->getAsArray(null, true);
            if(!$model['pollers']) {
                $model['pollers'] = null;
            }
            return $this->respondWithData($model);
        }
        $Models = [];
        foreach ($this->storage->fetchAll() as $Model) {
            $model = $Model->getAsArray(null, false);
            if(!$model['pollers']) {
                $model['pollers'] = null;
            }
            $Models[] = $model;
        }
        return  $this->respondWithData($Models);
    }

}
