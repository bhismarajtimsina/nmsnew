<?php

namespace WCC\Events\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\Events\Controllers\Controller;
use WCC\Events\Models\EventFilter;
/**
 * @OA\Get(
 *   path="/component/events/params/names",
 *   tags={"events"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get event names list",
 *   @OA\Response(
 *     response=200,
 *     description="Names list",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(type="string", example="high_link_utilization"))
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */

class GetEventNames extends PrivateAction
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
       return  $this->respondWithData($this->controller->getEventNames());
    }


}
