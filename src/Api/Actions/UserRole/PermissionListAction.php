<?php


namespace WCAA\Api\Actions\UserRole;


use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use WCAA\Api\Actions\Action;
use WCAA\App;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * @OA\Get(
 *   path="/user-role-permissions",
 *   tags={"user-role"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get available permission rules",
 *   @OA\Response(
 *     response=200,
 *     description="Permission rules",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/PermissionRule"))
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class PermissionListAction extends Action
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
        $permissions = [];
        foreach ($this->app->conf('api.auth.rules') as $perm) {
            $permissions[] = [
              'key' => $perm['key'],
              'description' => isset($perm['description']) ? $perm['description'] : "",
              'logic_group' => isset($perm['logic_group']) ? $perm['logic_group'] : '',
            ];
        }
        return $this->respondWithData($permissions);
    }

}
