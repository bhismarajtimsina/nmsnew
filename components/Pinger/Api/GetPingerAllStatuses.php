<?php

namespace WCC\Pinger\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCC\Pinger\Controllers\Controller;
/**
 * @OA\Get(
 *   path="/component/pinger/statuses",
 *   tags={"pinger"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get all pinger statuses",
 *   @OA\Response(
 *     response=200,
 *     description="Statuses list",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(type="object", additionalProperties=true)),
 *       @OA\Property(property="meta", type="object", @OA\Property(property="count", type="integer", example=42))
 *     )
 *   )
 * )
 */

class GetPingerAllStatuses extends PrivateAction
{
        /**
     * @Inject
     * @var Controller
     */
    protected $controller;

    protected function action(): Response
    {
        $data = $this->controller->getPingerHosts();
        return  $this->respondWithData($data, ['count' => count($data)]);
    }

}
