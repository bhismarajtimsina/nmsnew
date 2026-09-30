<?php

namespace WCC\Paths\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * @OA\Get(
 *   path="/component/paths/groups",
 *   tags={"paths"},
 *   security={{"XAuthKey": {}}},
 *   summary="Redundancy group states without geometry - for dashboards and widgets",
 *   @OA\Response(
 *     response=200,
 *     description="Group state summary (protected / unprotected / outage)",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(type="object"))
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class GetGroupStates extends BaseApiAction
{
    protected function action(): Response
    {
        return $this->respondWithData($this->controller->getGroupStates());
    }
}
