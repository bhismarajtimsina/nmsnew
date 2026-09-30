<?php


namespace WCAA\Api\Actions\User;

use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use WCAA\Storage\UserAuthKeyStorage;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * @OA\Get(
 *   path="/user",
 *   tags={"user"},
 *   security={{"XAuthKey": {}}},
 *   summary="List users",
 *   @OA\Response(
 *     response=200,
 *     description="Users list",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/User"))
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class GetAllUsersAction extends  UserAction
{

    /**
     * @Inject
     * @var UserAuthKeyStorage
     */
    protected $userAuthStorage;
    /**
     * @return Response
     */


    protected function action(): Response
    {
        $users = $this->userStorage->fetchAll();
        $response = [];
        foreach ($users as $user) {
            if($user->getId() < 0 || $user->getRole()->getId() < 0) {
                $permissions = [];
                foreach ($this->app->conf('api.auth.rules') as $perm) {
                    $permissions[] = $perm['key'];
                }
                $user->getRole()->setPermissions($permissions);
                $user->setDeviceGroups($this->deviceGroupsStorage->fetchAll());
            }
            $u = $user->getAsArray();
            $auth = $this->userAuthStorage->getLastActivityByUser($user);
            if($auth !== null) {
                $u['last_activity'] = $auth->getLastActivity();
            }
            $response[] = $u;
        }
        return  $this->respondWithData($response);
    }
}
