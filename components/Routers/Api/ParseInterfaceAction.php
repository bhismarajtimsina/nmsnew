<?php


namespace WCC\Routers\Api;

use OpenApi\Annotations as OA;


use DI\Annotation\Inject;
use WCAA\Api\Actions\PrivateAction;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Models\SystemAction;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\SystemActionsStorage;
use WCC\Routers\Controllers\RouterController;
/**
 * @OA\Get(
 *   path="/component/routers/interfaces/parse/{device}/{interface}",
 *   tags={"routers"},
 *   security={{"XAuthKey": {}}},
 *   summary="Parse router interface identifier",
 *   @OA\Parameter(name="device", in="path", required=true, @OA\Schema(type="integer", example=101)),
 *   @OA\Parameter(name="interface", in="path", required=true, @OA\Schema(type="string", example="ether1")),
 *   @OA\Response(response=200, description="Parsed interface", @OA\JsonContent(type="object", @OA\Property(property="data", type="object", additionalProperties=true), @OA\Property(property="meta", type="object", additionalProperties=true)))
 * )
 */

class ParseInterfaceAction extends PrivateAction
{

    /**
     * @Inject
     * @var RouterController
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
