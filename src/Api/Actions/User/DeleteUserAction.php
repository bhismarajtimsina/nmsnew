<?php


namespace WCAA\Api\Actions\User;

use OpenApi\Annotations as OA;
use WCAA\Models\User\User;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * @OA\Delete(
 *   path="/user/{id}",
 *   tags={"user"},
 *   security={{"XAuthKey": {}}},
 *   summary="Delete user",
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
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class DeleteUserAction extends  UserAction
{

    protected $forbiddenInDemo = true;


    protected function action(): Response
    {
        $id = $this->request->getAttribute('id');
        $this->userStorage->delete((new User())->setId($id));
        return  $this->respondWithData(true);
    }
}
