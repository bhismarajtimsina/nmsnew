<?php


namespace WCAA\Api\Actions\UserRole;

use OpenApi\Annotations as OA;
use WCAA\Models\User\UserRole;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;

/**
 * @OA\Put(
 *   path="/user-role/{id}",
 *   tags={"user-role"},
 *   security={{"XAuthKey": {}}},
 *   summary="Update user role",
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer", example=2)),
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="name", type="string", nullable=true),
 *       @OA\Property(property="display", type="boolean", nullable=true),
 *       @OA\Property(property="description", type="string", nullable=true),
 *       @OA\Property(property="permissions", type="array", nullable=true, @OA\Items(type="string"))
 *     )
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Updated role",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", ref="#/components/schemas/UserRole")
 *     )
 *   ),
 *   @OA\Response(response=400, description="Bad Request"),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class UpdateUserRoleUserAction extends  UserRoleAction
{

    protected $forbiddenInDemo = true;

    /**
     * @return Response
     * @throws HttpBadRequestException
     */

    protected function action(): Response
    {
        $id = $this->request->getAttribute('id');
        $data = $this->getFormData();
        $group = $this->groupStorage->fill((new UserRole())->setId($id));
        if(isset($data['name'])) {
            $group->setName($data['name']);
        }
        if(isset($data['display'])) {
            $group->setDisplay($data['display']);
        }
        if(isset($data['description'])) {
            $group->setDescription($data['description']);
        }
        if(isset($data['permissions'])) {
            $group->setPermissions($data['permissions']);
        }
        $permissions = $this->groupStorage->update($group);
        $this->cache->deleteByRegex('/^STORAGE:.*/');
        return  $this->respondWithData($permissions->getAsArray());
    }
}
