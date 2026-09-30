<?php


namespace WCC\Switches\Api;


use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use WCAA\Api\Actions\PrivateAction;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Models\Devices\Device;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\SystemActionsStorage;
use WCC\Switches\Controllers\SwitchesController;
/**
 * @OA\Get(
 *   path="/component/switches/tab-stats/{device}",
 *   tags={"switches"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get switch tab statistics",
 *   @OA\Parameter(name="device", in="path", required=true, @OA\Schema(type="integer", example=101)),
 *   @OA\Response(
 *     response=200,
 *     description="Stats payload",
 *     @OA\JsonContent(type="object", @OA\Property(property="data", type="object", additionalProperties=true))
 *   )
 * )
 */

class GetDeviceTabsStat extends PrivateAction
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
        $this->controller->setDevice($dev)->setUser($this->user);
        return $this->respondWithData($this->controller->getDeviceStats());
    }


}
