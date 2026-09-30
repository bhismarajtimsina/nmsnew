<?php


namespace WCAA\Api\Actions\UserRole;

use OpenApi\Annotations as OA;
use WCAA\Models\User\UserRole;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * @OA\Delete(
 *   path="/user-role/{id}",
 *   tags={"user-role"},
 *   security={{"XAuthKey": {}}},
 *   summary="Delete user role",
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer", example=2)),
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
class DeleteUserRoleUserAction extends  UserRoleAction
{

    protected $forbiddenInDemo = true;

    protected function action(): Response
    {
        $id = $this->request->getAttribute('id');
        $this->groupStorage->delete((new UserRole())->setId($id));
        return  $this->respondWithData(true);
    }
}
