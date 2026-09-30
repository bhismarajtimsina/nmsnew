<?php


namespace WCAA\Api\Actions\User;

use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use WCAA\Models\User\UserAuthKey;
use WCAA\Storage\UserAuthKeyStorage;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpForbiddenException;

/**
 * @OA\Get(
 *   path="/user/{id}",
 *   tags={"user"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get user by id",
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string", example="self")),
 *   @OA\Response(
 *     response=200,
 *     description="User data",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", ref="#/components/schemas/User")
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized"),
 *   @OA\Response(response=403, description="Forbidden"),
 *   @OA\Response(response=404, description="Not Found")
 * )
 */
class GetUserAction extends  UserAction
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
        $id = $this->request->getAttribute('id');
        if($id === 'self') {
            $id = $this->user->getId();
        }
        if(!$this->user->isRulePermitted('user_management') && $this->user->getId() != $id) {
            throw new HttpForbiddenException($this->request, "Not enough rights for view user with not same id");
        }
        $user = $this->userStorage->getById($id);
        $u = $user->getAsArray();
        $auth = $this->userAuthStorage->getLastActivityByUser($user);
        if($auth !== null) {
            $a = $auth->getAsArray();
            unset($a['key']);
            unset($a['user']);
            $u['last_activity'] = $auth->getLastActivity();
        }
        $u['active_sessions'] = [];
        $sessions = array_filter($this->userAuthStorage->getSessionsByUser($user), function ($el) {
            return $el->getStatus() === UserAuthKey::STATUS_ACTIVE;
        });
        foreach ($sessions as $ses) {
            $s = $ses->getAsArray();
            unset($s['user']);
            unset($s['key']);
            $u['active_sessions'][] = $s;
        }
        return  $this->respondWithData($u);
    }
}
