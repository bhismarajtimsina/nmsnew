<?php


namespace WCC\Switches\Api;


use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use WCAA\Api\Actions\PrivateAction;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Models\SystemAction;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\SystemActionsStorage;
use WCC\Switches\Controllers\SwitchesController;
/**
 * @OA\Get(
 *   path="/component/switches/interfaces/parse/{device}/{interface}",
 *   tags={"switches"},
 *   security={{"XAuthKey": {}}},
 *   summary="Parse switch interface identifier",
 *   @OA\Parameter(name="device", in="path", required=true, @OA\Schema(type="integer", example=101)),
 *   @OA\Parameter(name="interface", in="path", required=true, @OA\Schema(type="string", example="1/0/1")),
 *   @OA\Response(
 *     response=200,
 *     description="Parsed interface info",
 *     @OA\JsonContent(type="object", @OA\Property(property="data", type="object", additionalProperties=true), @OA\Property(property="meta", type="object", additionalProperties=true))
 *   )
 * )
 */

class ParseInterfaceAction extends PrivateAction
{
    
    /**
     * @Inject
     * @var SwitchesController
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
        $this->controller = $this->controller->setUser($this->user)->setDevice($dev);
        $interface = $this->controller->parseInterface($this->request->getAttribute('interface'));
        try {
            return $this->respondWithData($interface, $this->controller->getLastMeta());
        } catch (\Exception $e) {
            $this->systemActionsStorage->add(SystemAction::init(
                $this->user,
                'switches:parse_interface',
                SystemAction::STATUS_FAILED,
                "Requested interface info ({$interface['name']}) on device {$dev->getName()} ({$dev->getIp()})",
                ['device' => $dev->getAsArray(), 'error' => [
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
