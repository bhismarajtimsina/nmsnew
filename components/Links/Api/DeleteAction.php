<?php

namespace WCC\Links\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
/**
 * @OA\Delete(
 *   path="/component/links/{id}",
 *   tags={"links"},
 *   security={{"XAuthKey": {}}},
 *   summary="Delete link by ID",
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer", example=12)),
 *   @OA\Response(
 *     response=200,
 *     description="Deletion status",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="boolean", example=true)
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized"),
 *   @OA\Response(response=404, description="Not Found")
 * )
 */

class DeleteAction extends BaseApiAction
{
    protected $forbiddenInDemo = true;

    protected function action(): Response
    {
        $id = $this->request->getAttribute('id');
        $this->controller->deleteLink($id);
        return  $this->respondWithData(true);
    }
}
