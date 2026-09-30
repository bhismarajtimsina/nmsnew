<?php

namespace WCC\Links\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
/**
 * @OA\Get(
 *   path="/component/links",
 *   tags={"links"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get all links (lite)",
 *   @OA\Response(
 *     response=200,
 *     description="Links list",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/LinkLite"))
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */

class GetAll extends BaseApiAction
{
    protected function action(): Response
    {
        $links = array_map(function ($e) {
            return $e->getAsArrayLite();
        }, $this->controller->getAll());
        return $this->respondWithData($links);
    }
}
