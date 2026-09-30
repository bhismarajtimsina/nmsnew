<?php


namespace WCAA\Api\Actions\User;

use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use WCAA\Storage\UserAuthKeyStorage;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * @OA\Get(
 *   path="/user-list",
 *   tags={"user"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get short user list",
 *   @OA\Response(
 *     response=200,
 *     description="Short user list",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/UserListItem"))
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class GetAllUserListAction extends  UserAction
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
            if($user->getId() < 0) continue;
            $response[] = [
                'id' => $user->getId(),
                'name' => $user->getName(),
            ];
        }
        return  $this->respondWithData($response);
    }
}
