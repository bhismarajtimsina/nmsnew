<?php


namespace WCAA\Api\Actions\UserRole;

use OpenApi\Annotations as OA;
use WCAA\Models\User\UserRole;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;

/**
 * @OA\Post(
 *   path="/user-role",
 *   tags={"user-role"},
 *   security={{"XAuthKey": {}}},
 *   summary="Create user role",
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       type="object",
 *       required={"name","display","permissions"},
 *       @OA\Property(property="name", type="string", example="Operator"),
 *       @OA\Property(property="display", type="boolean", example=true),
 *       @OA\Property(property="permissions", type="array", @OA\Items(type="string", example="user_self_control"))
 *     )
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Created role",
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
class AddUserRoleAction extends  UserRoleAction
{

    protected $forbiddenInDemo = true;

    /**
     * @return Response
     * @throws HttpBadRequestException
     */

    protected function action(): Response
    {
        $data = $this->getFormData();
        $keys = array_keys($data);
        if(!in_array('name', $keys)) {
            throw new HttpBadRequestException($this->request, "Name is required");
        }
        if(!in_array('display', $keys)) {
            throw new HttpBadRequestException($this->request, "Display is required");
        }
        if(!in_array('permissions', $keys)) {
            throw new HttpBadRequestException($this->request, "Permissions is required");
        }
        $obj = new UserRole();
        $obj->setName($data['name'])
            ->setDisplay($data['display'])
            ->setPermissions($data['permissions']);
        $user = $this->groupStorage->add($obj);
        return  $this->respondWithData($user->getAsArray());
    }
}
