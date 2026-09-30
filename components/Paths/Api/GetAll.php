<?php

namespace WCC\Paths\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * @OA\Get(
 *   path="/component/paths",
 *   tags={"paths"},
 *   security={{"XAuthKey": {}}},
 *   summary="List all transport paths",
 *   @OA\Response(
 *     response=200,
 *     description="Paths with their current state",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(type="object"))
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class GetAll extends BaseApiAction
{
    protected function action(): Response
    {
        $paths = array_map(function ($path) {
            return $path->getAsArrayLite();
        }, $this->controller->getAll());
        return $this->respondWithData($paths);
    }
}
