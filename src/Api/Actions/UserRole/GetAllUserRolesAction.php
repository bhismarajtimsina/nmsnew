<?php


namespace WCAA\Api\Actions\UserRole;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\App;

/**
 * @OA\Get(
 *   path="/user-role",
 *   tags={"user-role"},
 *   security={{"XAuthKey": {}}},
 *   summary="List user roles",
 *   @OA\Response(
 *     response=200,
 *     description="Roles list",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/UserRole"))
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class GetAllUserRolesAction extends  UserRoleAction
{
    /**
     * @Inject
     * @var App
     */
    protected $app;
    protected function action(): Response
    {
        $groups = $this->groupStorage->fetchAll();
        $response = [];
        foreach ($groups as $group) {
            $response[] = $group->getAsArray();
        }
        return  $this->respondWithData($response);
    }
}
