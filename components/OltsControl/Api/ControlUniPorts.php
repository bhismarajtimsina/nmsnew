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
 * @OA\PUT(
 *   path="/component/olts_control/ont/uni-control/{device}/{interface}",
 *   tags={"olts_control"},
 *   security={{"XAuthKey":{}}},
 *   @OA\RequestBody(
 *     required=false,
 *     @OA\JsonContent(type="object", nullable=true, additionalProperties={})
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="OK",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="data", type="object", nullable=true, additionalProperties={}),
 *       @OA\Property(property="meta", type="object", nullable=true, additionalProperties={})
 *     )
 *   )
 * )
 */

class ControlUniPorts extends PrivateAction
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
        if(!isset($form['action'])) {
            throw new HttpBadRequestException($this->request, "action is required in form");
        }
        try {
            switch ($form['action']) {
                case 'admin_state': $data = $this->controller->ctrlChangeAdminState($interface['id'], $form['num'], $form['state']); break;
                default:
                    throw new HttpBadRequestException($this->request, "incorrect action '{$form['action']}'");
            }
            $this->systemActionsStorage->add(SystemAction::init(
                $this->user,
                'olts:control_uni_ports',
                SystemAction::STATUS_SUCCESS,
                "Requested change UNI {$form['action']} on ONT {$interface['name']} on device {$dev->getName()} ({$dev->getIp()})",
                ['device' => $dev, 'interface' => $interface, 'form' => $form]
            ));
            return $this->respondWithData($data, $this->controller->getLastMeta());
        } catch (\Exception $e) {
            $this->systemActionsStorage->add(SystemAction::init(
                $this->user,
                'olts:control_uni_ports',
                SystemAction::STATUS_FAILED,
                "Requested change UNI port on ONT {$interface['name']} on device {$dev->getName()} ({$dev->getIp()})",
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