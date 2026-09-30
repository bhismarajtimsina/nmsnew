<?php


namespace WCC\RouterOS\Api;


use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use WCAA\Api\Actions\PrivateAction;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Models\Devices\Device;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\SystemActionsStorage;
use WCC\RouterOS\Controllers\Controller;
/**
 * @OA\Get(
 *   path="/component/router_os/tab-stats/{id}",
 *   tags={"router-os"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get RouterOS tab statistics",
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer", example=101)),
 *   @OA\Response(response=200, description="Stats", @OA\JsonContent(type="object", @OA\Property(property="data", type="object", additionalProperties=true)))
 * )
 */

class GetDeviceTabsStat extends PrivateAction
{
        /**
     * @Inject
     * @var Controller
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
        $dev = $this->deviceStorage->getById($this->request->getAttribute('id'));
        $this->controller->setDevice($dev)->setUser($this->user);
        return $this->respondWithData($this->controller->getDeviceStats());
    }
}
