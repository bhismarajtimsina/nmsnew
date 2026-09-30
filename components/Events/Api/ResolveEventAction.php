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
 * @OA\Put(
 *   path="/component/events/{id}/resolve",
 *   tags={"events"},
 *   security={{"XAuthKey": {}}},
 *   summary="Resolve event by ID",
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer", example=1234)),
 *   @OA\Response(
 *     response=200,
 *     description="Resolved event",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="object", additionalProperties=true)
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized"),
 *   @OA\Response(response=404, description="Not Found")
 * )
 */

class ResolveEventAction extends PrivateAction
{
        protected $forbiddenInDemo = true;

    /**
     * @Inject
     * @var Controller
     */
    protected $controller;

    protected function action(): Response
    {
      return $this->respondWithData($this->controller->resolveEvent($this->user, $this->request->getAttribute('id'))->getAsArray());
    }

}
