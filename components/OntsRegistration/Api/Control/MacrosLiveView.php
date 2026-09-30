<?php

namespace WCC\OntsRegistration\Api\Control;

use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\OntsRegistration\Controllers\MacrosGateway;
use WCC\OntsRegistration\Storage\UnregisteredOntMacroStorage;
/**
 * @OA\Post(
 *   path="/component/onts_registration/control/preview",
 *   tags={"onts-registration-control"},
 *   security={{"XAuthKey": {}}},
 *   summary="Preview registration template",
 *
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       type="object",
 *       required={"device", "ont", "template"},
 *
 *       @OA\Property(
 *         property="device",
 *         type="object",
 *         required={"id"},
 *         @OA\Property(
 *           property="id",
 *           type="integer",
 *           example=123,
 *           description="Device ID"
 *         )
 *       ),
 *
 *       @OA\Property(
 *         property="ont",
 *         type="object",
 *         additionalProperties=true,
 *         description="ONT data used for variable generation"
 *       ),
 *
 *       @OA\Property(
 *         property="params",
 *         type="object",
 *         additionalProperties=true,
 *         description="Additional parameters for macro generation"
 *       ),
 *
 *       @OA\Property(
 *         property="from",
 *         type="string",
 *         example="cache",
 *         description="Source of data"
 *       ),
 *
 *       @OA\Property(
 *         property="template",
 *         type="string",
 *         example="ONT {{serial}} model {{model}}",
 *         description="Template string to preview"
 *       )
 *     )
 *   ),
 *
 *   @OA\Response(
 *     response=200,
 *     description="Rendered template",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="data", type="string", example="ONT ZTEGC1234567 model F670")
 *     )
 *   ),
 *
 *   @OA\Response(
 *     response=400,
 *     description="Bad request",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=400),
 *       @OA\Property(
 *         property="error",
 *         type="object",
 *         @OA\Property(property="description", type="string", example="Field template is required")
 *       )
 *     )
 *   )
 * )
 */

class MacrosLiveView extends PrivateAction
{
    
    /**
     * @Inject
     * @var DeviceInterfaceStorage
     */
    protected $deviceInterfaceStorage;

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @Inject
     * @var UnregisteredOntMacroStorage
     */
    protected $macrosStorage;

    /**
     * @Inject
     * @var MacrosGateway
     */
    protected $gateway;

    /**
     * @return Response
     */
    protected function action(): Response
    {
        $this->gateway->setUser($this->user);
        $data = $this->getFormData();
        $parameters = [];
        $from = 'cache';
        if(!isset($data['device']['id'])) {
            throw new \Exception("Choose device is required");
        }
        if(!isset($data['ont'])) {
            throw new \Exception("Field ont is required");
        }
        if(isset($data['params'])) {
            $parameters = $data['params'];
        }
        if(isset($data['from'])) {
            $from = $data['from'];
        }
        if(isset($data['template'])) {
            $template = $data['template'];
        }

        $variables = $this->gateway->generateVariables($this->deviceStorage->getById($data['device']['id']), $data['ont'], $parameters, $from);
        $template = $this->gateway->buildTemplate($template, $variables);
        return  $this->respondWithData($template);
    }

}
