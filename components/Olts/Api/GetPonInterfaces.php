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
 *   path="/component/olts/interfaces/pon-ports/{device}",
 *   description="PON ports list",
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
 *       @OA\Property(property="data", type="array", @OA\Items(type="object", additionalProperties={})),
 *       @OA\Property(property="meta", type="object", nullable=true, additionalProperties={})
 *     )
 *   )
 * )
 */

class GetPonInterfaces extends PrivateAction
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

        protected function action(): Response
    {
        $queries = $this->request->getQueryParams();
        $from = isset($queries['from']) ? $queries['from'] : 'cache';
        $dev = $this->deviceStorage->getById($this->request->getAttribute('device'));
        $data = $this->controller->setDevice($dev)->setUser($this->user)->getPonInterfacesList($from);
        return $this->respondWithData(array_values(array_filter($data, function ($e) {
            return $e['type'] == 'PON';
        })), $this->controller->getLastMeta());
    }

}
