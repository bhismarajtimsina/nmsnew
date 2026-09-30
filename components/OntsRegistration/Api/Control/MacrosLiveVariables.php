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
 *   path="/component/onts_registration/control/variables",
 *   tags={"onts-registration-control"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get registration variables (control)",
 *
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       type="object",
 *       required={"device", "ont"},
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
 *       )
 *     )
 *   ),
 *
 *   @OA\Response(
 *     response=200,
 *     description="Variables",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="data", type="object", additionalProperties=true)
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
 *         @OA\Property(property="description", type="string", example="Choose device is required")
 *       )
 *     )
 *   )
 * )
 */
class MacrosLiveVariables extends PrivateAction
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
        $variables = $this->gateway->generateVariables($this->deviceStorage->getById($data['device']['id']), $data['ont'], $parameters, $from);
        return  $this->respondWithData($variables);
    }

}
