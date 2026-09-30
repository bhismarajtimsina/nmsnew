<?php


namespace WCAA\Api\Actions\Devices\Models;


use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use WCAA\Api\Actions\Action;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Models\Devices\DeviceModel;
use WCAA\Storage\Devices\DeviceModelStorage;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;

/**
 * @OA\Post(
 *   path="/device-model",
 *   tags={"device-model"},
 *   security={{"XAuthKey": {}}},
 *   summary="Create device model",
 *   description="Creates a new device model. Required: vendor, model.",
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       type="object",
 *       required={"vendor","model"},
 *       @OA\Property(property="vendor", type="string", example="Cisco"),
 *       @OA\Property(property="model", type="string", example="C9300"),
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
 *     description="Device model successfully created",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", ref="#/components/schemas/DeviceModel")
 *     )
 *   ),
 *   @OA\Response(
 *     response=400,
 *     description="Bad Request (missing required fields)",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=400),
 *       @OA\Property(property="error", type="string", example="Bad Request"),
 *       @OA\Property(property="message", type="string", example="Vendor is required")
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class AddModelAction extends PrivateAction
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
        $Model = new DeviceModel();
        $data = $this->getFormData();

        if(isset($data['vendor'])) {
            $Model->setVendor($data['vendor']);
        } else {
            throw new HttpBadRequestException($this->request, "Vendor is required");
        }
        if(isset($data['model'])) {
            $Model->setModel($data['model']);
        } else {
            throw new HttpBadRequestException($this->request, "Model is required");
        }
        if(isset($data['name'])) {
            $Model->setName($data['name']);
        } else {
            $Model->setName("{$data['vendor']} {$data['model']}");
        }
        if(isset($data['params'])) {
            $Model->setParams($data['params']);
        }
        $Model = $this->storage->add($Model);
        return  $this->respondWithData($Model->getAsArray());
    }

}
