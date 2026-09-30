<?php


namespace WCC\Routers\Api;

use OpenApi\Annotations as OA;


use DI\Annotation\Inject;
use WCAA\Api\Actions\PrivateAction;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Models\Devices\Device;
use WCAA\Models\SystemAction;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\SystemActionsStorage;
use WCC\Routers\Controllers\RouterController;
/**
 * @OA\Get(
 *   path="/component/routers/resources/{device}",
 *   tags={"routers"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get router resources",
 *   @OA\Parameter(name="device", in="path", required=true, @OA\Schema(type="integer", example=101)),
 *   @OA\Parameter(name="from", in="query", required=false, @OA\Schema(type="string", enum={"device","cache","store"}, default="cache")),
 *   @OA\Response(response=200, description="Resources payload", @OA\JsonContent(type="object", @OA\Property(property="data", type="object", additionalProperties=true)))
 * )
 */

class GetResources extends PrivateAction
{

    /**
     * @Inject
     * @var RouterController
     */
    protected $controller;

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
        $data = $this->controller->setUser($this->user)->setDevice($dev)->getResources($from);
        return $this->respondWithData($data);
    }

}
