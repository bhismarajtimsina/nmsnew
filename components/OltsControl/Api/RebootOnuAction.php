<?php


namespace WCC\OltsControl\Api;

use OpenApi\Annotations as OA;


use DI\Annotation\Inject;
use WCAA\Api\Actions\PrivateAction;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Models\SystemAction;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\SystemActionsStorage;
use WCC\OltsControl\Controllers\Controller;
/**
 * @OA\Put(
 *   path="/component/olts_control/ont/reboot/{device}/{interface}",
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
 *   @OA\Response(
 *     response=200,
 *     description="ONU reboot requested",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="data", type="object", nullable=true, additionalProperties=true),
 *       @OA\Property(property="meta", type="object", nullable=true, additionalProperties=true)
 *     )
 *   )
 * )
 */

class RebootOnuAction extends PrivateAction
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
        try {
            $data = $this->controller
                ->rebootOnu($interface['id']);
            $this->systemActionsStorage->add(SystemAction::init(
                $this->user,
                'olts:reboot_ont',
                SystemAction::STATUS_SUCCESS,
                "Requested reboot ONU {$interface['name']} on device {$dev->getName()} ({$dev->getIp()})",
                ['device' => $dev, 'interface' => $interface]
            ));
            return $this->respondWithData($data, $this->controller->getLastMeta());
        } catch (\Exception $e) {
            $this->systemActionsStorage->add(SystemAction::init(
                $this->user,
                'olts:reboot_ont',
                SystemAction::STATUS_FAILED,
                "Requested reboot ONU {$interface['name']} on device {$dev->getName()} ({$dev->getIp()})",
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