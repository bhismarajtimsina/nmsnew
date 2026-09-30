<?php


namespace WCC\Olts\Api;

use OpenApi\Annotations as OA;

use DI\Annotation\Inject;
use WCAA\Api\Actions\PrivateAction;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Models\SystemAction;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\SystemActionsStorage;
use WCC\Olts\Controllers\Controller;
/**
 * @return Response
 *
 * @OA\Get(
 *   path="/component/olts/interfaces/parse/{device}/{interface}",
 *   description="Parse OLT interface name",
 *   tags={"olts"},
 *   security={{"XAuthKey":{}}},
 *   @OA\Parameter(
 *     name="device",
 *     in="path",
 *     required=true,
 *     description="Device ID from database",
 *     @OA\Schema(type="integer")
 *   ),
 *   @OA\Parameter(
 *     name="interface",
 *     in="path",
 *     required=true,
 *     @OA\Schema(type="string")
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="OK",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="data", type="object", additionalProperties={}),
 *       @OA\Property(property="meta", type="object", nullable=true, additionalProperties={})
 *     )
 *   )
 * )
 */

class ParseInterfaceAction extends PrivateAction
{

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

        protected function action(): Response
    {
        $dev = $this->deviceStorage->getById($this->request->getAttribute('device'));
        $this->controller = $this->controller->setDevice($dev)->setUser($this->user);
        $interface = $this->controller->parseInterface($this->request->getAttribute('interface'));
        try {
            return $this->respondWithData($interface, $this->controller->getLastMeta());
        } catch (\Exception $e) {
            $this->systemActionsStorage->add(SystemAction::init(
                $this->user,
                'olts:parse_interfaces',
                SystemAction::STATUS_FAILED,
                "Requested interface info ({$interface['name']}) on device {$dev->getName()} ({$dev->getIp()})",
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
