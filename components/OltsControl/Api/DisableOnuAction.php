<?php


namespace WCC\OltsControl\Api;

use OpenApi\Annotations as OA;


use DI\Annotation\Inject;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Models\SystemAction;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\SystemActionsStorage;
use WCC\OltsControl\Controllers\Controller;
/**
 * @OA\Put(
 *   path="/component/olts_control/ont/disable/{device}/{interface}",
 *   tags={"olts_control"},
 *   security={{"XAuthKey":{}}},
 *
 *   @OA\Parameter(
 *     name="device",
 *     in="path",
 *     required=true,
 *     description="Device ID",
 *     @OA\Schema(type="integer", example=1)
 *   ),
 *
 *   @OA\Parameter(
 *     name="interface",
 *     in="path",
 *     required=true,
 *     description="ONT interface identifier",
 *     @OA\Schema(type="string", example="1/1/1:1")
 *   ),
 *
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       type="object",
 *       required={"state"},
 *       @OA\Property(
 *         property="state",
 *         type="string",
 *         enum={"disable","enable"},
 *         example="disable",
 *         description="Set ONU state"
 *       )
 *     )
 *   ),
 *
 *   @OA\Response(
 *     response=200,
 *     description="OK",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="data", type="object", nullable=true, additionalProperties=true),
 *       @OA\Property(property="meta", type="object", nullable=true, additionalProperties=true)
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
 *         @OA\Property(property="description", type="string", example="State is required in form")
 *       )
 *     )
 *   ),
 *
 *   @OA\Response(
 *     response=404,
 *     description="Device or interface not found",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=404),
 *       @OA\Property(
 *         property="error",
 *         type="object",
 *         @OA\Property(property="description", type="string", example="Device not found")
 *       )
 *     )
 *   )
 * )
 */

class DisableOnuAction extends PrivateAction
{
    protected $forbiddenInDemo = true;

    /**
     * @Inject
     * @var Controller
     */
    protected $controller;

    /**
     * @Inject
     * @var SystemActionsStorage
     */
    protected $systemActionsStorage;

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @return Response
     */
    

    protected function action(): Response
    {
        $dev = $this->deviceStorage->getById($this->request->getAttribute('device'));
        $this->controller = $this->controller->setDevice($dev)->setUser($this->user);
        $interface = $this->controller->parseInterface($this->request->getAttribute('interface'));
        $form = $this->getFormData();
        if(!isset($form['state'])) {
            throw new HttpBadRequestException($this->request, "State is required in form");
        }
        try {
            $data = $this->controller
                ->ctrlOnuDisable($interface['id'], $form['state']);
            $this->systemActionsStorage->add(SystemAction::init(
                $this->user,
                $form['state'] == 'disable' ? 'olts:disable_onu' : 'olts:enable_onu',
                SystemAction::STATUS_SUCCESS,
                "Requested {$form['state']} ONU {$interface['name']} on device {$dev->getName()} ({$dev->getIp()})",
                ['device' => $dev, 'interface' => $interface]
            ));
            return $this->respondWithData($data, $this->controller->getLastMeta());
        } catch (\Exception $e) {
            $this->systemActionsStorage->add(SystemAction::init(
                $this->user,
                $form['state'] == 'disable' ? 'olts:disable_onu' : 'olts:enable_onu',
                SystemAction::STATUS_FAILED,
                "Requested {$form['state']}  ONU {$interface['name']} on device {$dev->getName()} ({$dev->getIp()})",
                ['device' => $dev, 'error' => [
                    'message' => $e->getMessage(),
                    'code' => $e->getCode(),
                    'line' => $e->getLine(),
                    'file' => $e->getFile(),
                    'trace' => $e->getTraceAsString(),
                ]]
            ));
            throw $e;
        }
    }
}