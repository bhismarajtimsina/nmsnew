<?php


namespace WCC\Switches\Api;


use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use WCAA\Api\Actions\PrivateAction;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Exceptions\SupportException;
use WCAA\Models\Devices\Device;
use WCAA\Models\SystemAction;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\SystemActionsStorage;
use WCC\Switches\Controllers\SwitchesController;
/**
 * @OA\Get(
 *   path="/component/switches/interfaces/{device}/sfp_diag",
 *   tags={"switches"},
 *   security={{"XAuthKey": {}}},
 *   summary="Run SFP diagnostics on interface",
 *   @OA\Parameter(name="device", in="path", required=true, @OA\Schema(type="integer", example=101)),
 *   @OA\Parameter(name="from", in="query", required=false, @OA\Schema(type="string", enum={"device","cache","store"}, default="cache")),
 *   @OA\Parameter(name="interface", in="query", required=false, @OA\Schema(type="string", example="1/0/1")),
 *   @OA\Response(
 *     response=200,
 *     description="SFP diagnostic data",
 *     @OA\JsonContent(type="object", @OA\Property(property="data", type="object", additionalProperties=true), @OA\Property(property="meta", type="object", additionalProperties=true))
 *   )
 * )
 */

class SfpDiagOnInterface extends PrivateAction
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
        $queries = $this->request->getQueryParams();
        $from = isset($queries['from']) ? $queries['from'] : 'cache';
        $interface = isset($queries['interface']) ? $queries['interface'] : null;
        $dev = new Device($this->request->getAttribute('device'));
        try {
            $dev = $this->deviceStorage->getById($this->request->getAttribute('device'));
            $this->controller->setDevice($dev)->setUser($this->user);
            $this->systemActionsStorage->add(SystemAction::init(
                $this->user,
                'switches:sfp_diag_port',
                SystemAction::STATUS_SUCCESS,
                "Requested SFP diagnostic on {$dev->getName()} ({$dev->getIp()}) on port {$interface}",
                ['device' => $dev->getAsArray()]
            ));

            return $this->respondWithData($this->controller->diagSfpInterface($interface, $from), $this->controller->getLastMeta());
        } catch (\Exception $e) {
            $this->systemActionsStorage->add(SystemAction::init(
                $this->user,
                'switches:sfp_diag_port',
                SystemAction::STATUS_FAILED,
                "Requested SFP diagnostic on device {$dev->getName()} ({$dev->getIp()}) on port $interface",
                ['device' => $dev->getAsArray(), 'error' => [
                    'message' => $e->getMessage(),
                    'code' => $e->getCode(),
                    'line' => $e->getLine(),
                    'file' => $e->getFile(),
                    'trace' => $e->getTraceAsString(),
                ]]
            ));
            throw new SupportException("SFP info about port {$interface} not found");
        }
    }

}
