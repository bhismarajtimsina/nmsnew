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
 *   path="/component/olts/resources/{device}",
 *   description="OLT resources",
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
 *     name="from",
 *     in="query",
 *     required=false,
 *     description="Data source: device — read from device; cache — read from cache, if stale then query device; store — read from cache, if missing then error.",
 *     @OA\Schema(type="string", enum={"device","cache","store"}, default="cache")
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
 * @OA\Get(
 *   path="/component/olts/system/resources/{device}",
 *   description="OLT system resources",
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
 *     name="from",
 *     in="query",
 *     required=false,
 *     description="Data source: device — read from device; cache — read from cache, if stale then query device; store — read from cache, if missing then error.",
 *     @OA\Schema(type="string", enum={"device","cache","store"}, default="cache")
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

class GetResources extends PrivateAction
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
        $queries = $this->request->getQueryParams();
        $from = isset($queries['from']) ? $queries['from'] : 'cache';
        $dev = $this->deviceStorage->getById($this->request->getAttribute('device'));
        $data = $this->controller->setDevice($dev)->setUser($this->user)->getResources($from);
        return $this->respondWithData($data, $this->controller->getLastMeta());
    }

}
