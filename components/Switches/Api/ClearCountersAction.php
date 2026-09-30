<?php


namespace WCC\Switches\Api;


use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use WCAA\Api\Actions\PrivateAction;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Models\Devices\Device;
use WCAA\Models\SystemAction;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\SystemActionsStorage;
use WCC\Switches\Controllers\SwitchesController;
/**
 * @OA\Put(
 *   path="/component/switches/system/{device}/clear_counters",
 *   tags={"switches"},
 *   security={{"XAuthKey": {}}},
 *   summary="Clear switch counters",
 *   @OA\Parameter(name="device", in="path", required=true, @OA\Schema(type="integer", example=101)),
 *   @OA\Response(
 *     response=200,
 *     description="Clear counters result",
 *     @OA\JsonContent(type="object", @OA\Property(property="data", type="object", additionalProperties=true), @OA\Property(property="meta", type="object", additionalProperties=true))
 *   )
 * )
 */

class ClearCountersAction extends PrivateAction
{
    
    /**
     * @Inject
     * @var SwitchesController
     */
    protected $controller;

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;


    /**
     * @Inject
     * @var SystemActionsStorage
     */
    protected $systemActionsStorage;

    /**
     * @return Response
     */

    protected function action(): Response
    {
        $dev = new Device($this->request->getAttribute('device'));
        try {
            $dev = $this->deviceStorage->getById($this->request->getAttribute('device'));
            $this->controller->setDevice($dev)->setUser($this->user);
            $this->systemActionsStorage->add(SystemAction::init(
                $this->user,
                'switches:clear_counters',
                SystemAction::STATUS_SUCCESS,
                "Request clear counters on device {$dev->getName()} ({$dev->getIp()})",
                ['device' => $dev->getAsArray()]
            ));

            return $this->respondWithData($this->controller->clearCounters(), $this->controller->getLastMeta());
        } catch (\Exception $e) {
            $this->systemActionsStorage->add(SystemAction::init(
                $this->user,
                'switches:clear_counters',
                SystemAction::STATUS_FAILED,
                "Request clear counters on device {$dev->getName()} ({$dev->getIp()})",
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
