<?php

namespace WCC\Paths\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * @OA\Get(
 *   path="/component/paths/map",
 *   tags={"paths"},
 *   security={{"XAuthKey": {}}},
 *   summary="Transport paths grouped by redundancy group, with hop coordinates for map rendering",
 *   @OA\Response(
 *     response=200,
 *     description="Groups with their paths, per-path state and ordered geo points",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(type="object"))
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class GetMapView extends BaseApiAction
{
    protected function action(): Response
    {
        return $this->respondWithData($this->controller->getMapView());
    }
}
