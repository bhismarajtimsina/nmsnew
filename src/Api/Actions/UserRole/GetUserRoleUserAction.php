<?php


namespace WCAA\Api\Actions\UserRole;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\App;

/**
 * @OA\Get(
 *   path="/user-role/{id}",
 *   tags={"user-role"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get user role by id",
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer", example=2)),
 *   @OA\Response(
 *     response=200,
 *     description="Role data",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", ref="#/components/schemas/UserRole")
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized"),
 *   @OA\Response(response=404, description="Not Found")
 * )
 */
class GetUserRoleUserAction extends  UserRoleAction
{

    /**
     * @Inject
     * @var App
     */
    protected $app;

    /**
     * @return Response
     */

    protected function action(): Response
    {
        $id = $this->request->getAttribute('id');
        $group = $this->groupStorage->getById($id);
        return  $this->respondWithData($group->getAsArray());
    }
}
