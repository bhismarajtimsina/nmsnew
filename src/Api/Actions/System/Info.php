<?php

namespace WCAA\Api\Actions\System;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Infrastructure\SystemInfo;

/**
 * @OA\Get(
 *   path="/system/info",
 *   tags={"system"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get agent validation/server info",
 *   @OA\Response(
 *     response=200,
 *     description="System info",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="object", additionalProperties=true)
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class Info extends PrivateAction
{
    /**
     * @Inject
     * @var SystemInfo
     */
    protected $system;
    protected function action(): Response
    {
        return $this->respondWithData($this->system->getAsArray());
    }

}
