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
 *   path="/component/olts_control/olt/interface/description/{device}/{interface}",
 *   tags={"olts_control"},
 *   security={{"XAuthKey":{}}},
 *   summary="Change OLT port description",
 *
 *   @OA\Parameter(
 *     name="device",
 *     in="path",
 *     required=true,
 *     description="Device ID from database",
 *     @OA\Schema(type="integer", example=10)
 *   ),
 *   @OA\Parameter(
 *     name="interface",
 *     in="path",
 *     required=true,
 *     description="Interface identifier for parseInterface() (e.g. bind_key, interface id, or name depending on backend parser)",
 *     @OA\Schema(type="string", example="10001")
 *   ),
 *
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       type="object",
 *       required={"description"},
 *       @OA\Property(property="description", type="string", example="Client ABC / VLAN 123")
 *     )
 *   ),
 *
 *   @OA\Response(
 *     response=200,
 *     description="OK",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="object", nullable=true, additionalProperties=true),
 *       @OA\Property(property="meta", type="object", nullable=true, additionalProperties=true)
 *     )
 *   ),
 *   @OA\Response(
 *     response=400,
 *     description="Bad Request (e.g. description is missing / malformed json)"
 *   ),
 *   @OA\Response(
 *     response=404,
 *     description="Device not found"
 *   )
 * )
 */
class ChangePortDescription extends PrivateAction
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

        $data = $this->getFormData();
        if(!isset($data['description'])) {
            throw new HttpBadRequestException($this->request, "Description is required");
        }

        try {
            $response = $this->controller
                ->changePortDescription($interface['id'], $data['description']);
            $this->systemActionsStorage->add(SystemAction::init(
                $this->user,
                'olts:change_port_description',
                SystemAction::STATUS_SUCCESS,
                "Setted new description on {$interface['name']} on device {$dev->getName()} ({$dev->getIp()}) - {$data['description']}",
                ['device' => $dev, 'interface' => $interface]
            ));
            return $this->respondWithData($data, $this->controller->getLastMeta());
        } catch (\Exception $e) {
            $this->systemActionsStorage->add(SystemAction::init(
                $this->user,
                'olts:change_port_description',
                SystemAction::STATUS_FAILED,
                "Setted new description on {$interface['name']} on device {$dev->getName()} ({$dev->getIp()}) - {$data['description']}",
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