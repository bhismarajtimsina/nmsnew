<?php

namespace WCC\Olts\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\Olts\Controllers\Controller;
/**
 * @OA\Get(
 *   path="/component/olts/tab-stats/{device}",
 *   description="Device tab statistics",
 *   tags={"olts"},
 *   security={{"XAuthKey":{}}},
 *   @OA\Parameter(
 *     name="device",
 *     in="path",
 *     required=true,
 *     description="Device ID from database",
 *     @OA\Schema(type="integer")
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

class GetTabStats extends PrivateAction
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

        protected function action(): Response
    {
        $dev = $this->deviceStorage->getById($this->request->getAttribute('device'));
        $data = $this->controller->setDevice($dev)->setUser($this->user);
        return $this->respondWithData($data->getDeviceStats());
    }

}
