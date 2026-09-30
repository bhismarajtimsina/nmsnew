<?php


namespace WCAA\Api\Actions\Devices\Models;


use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use WCAA\Api\Actions\Action;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Infrastructure\ImgHelper;
use WCAA\Storage\Devices\DeviceModelStorage;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * @OA\Get(
 *   path="/device-icon/{id}",
 *   tags={"device-model"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get device model icon",
 *   description="Returns binary icon for the device model.",
 *   @OA\Parameter(
 *     name="id",
 *     in="path",
 *     required=true,
 *     description="Device model ID",
 *     @OA\Schema(type="integer", example=12)
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Icon binary",
 *     @OA\MediaType(
 *       mediaType="application/octet-stream",
 *       @OA\Schema(type="string", format="binary")
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized"),
 *   @OA\Response(response=404, description="Not Found")
 * )
 */
class GetModelIconAction extends PrivateAction
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
        $id = $this->request->getAttribute('id');
        $model = $this->storage->getById($id);
        $binary = ImgHelper::getBase64Decode($model->getIcon());
        $type = ImgHelper::getMimeType($binary);
        $this->response->getBody()->write($binary);
        return $this->response->withHeader('Content-Type', $type);
    }

}
