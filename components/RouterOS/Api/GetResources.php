<?php


namespace WCC\RouterOS\Api;


use OpenApi\Annotations as OA;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\RouterOS\Controllers\Controller;
use Psr\Http\Message\ResponseInterface as Response;
/**
 * @OA\Get(
 *   path="/component/router_os/resources/{id}",
 *   tags={"router-os"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get RouterOS resources",
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string", example="101")),
 *   @OA\Parameter(name="from", in="query", required=false, @OA\Schema(type="string", enum={"cache","device","store"}, default="cache")),
 *   @OA\Response(response=200, description="Resources", @OA\JsonContent(type="object", @OA\Property(property="data", type="object", additionalProperties=true), @OA\Property(property="meta", type="object", additionalProperties=true)))
 * )
 *
 * @OA\Get(
 *   path="/component/router_os/device/{id}/resources",
 *   tags={"router-os"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get RouterOS resources by device endpoint",
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string", example="101")),
 *   @OA\Parameter(name="from", in="query", required=false, @OA\Schema(type="string", enum={"cache","device","store"}, default="cache")),
 *   @OA\Response(response=200, description="Resources", @OA\JsonContent(type="object", @OA\Property(property="data", type="object", additionalProperties=true), @OA\Property(property="meta", type="object", additionalProperties=true)))
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
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @return Response
     */

    protected function action(): Response
    {
        $queries = $this->request->getQueryParams();
        $from = isset($queries['from']) ? $queries['from'] : 'cache';
        $dev = $this->deviceStorage->getById($this->request->getAttribute('id'));
        $data = $this->controller->setUser($this->user)->setDevice($dev)->getResources($from);
        return $this->respondWithData($data, $this->controller->getMeta());
    }
}
