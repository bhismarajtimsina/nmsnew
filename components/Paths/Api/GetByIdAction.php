<?php

namespace WCC\Paths\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Api\DomainException\DomainRecordNotFoundException;

/**
 * @OA\Get(
 *   path="/component/paths/{id}",
 *   tags={"paths"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get one transport path with its segments and state",
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer", example=1)),
 *   @OA\Response(response=200, description="Path"),
 *   @OA\Response(response=404, description="Not found"),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class GetByIdAction extends BaseApiAction
{
    protected function action(): Response
    {
        $path = $this->controller->getById($this->resolveArg('id'));
        if (!$path) {
            throw new DomainRecordNotFoundException("Path not found");
        }
        return $this->respondWithData($path->getAsArray());
    }
}
