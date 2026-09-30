<?php


namespace WCAA\Api\Actions\User;

use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use Slim\Exception\HttpForbiddenException;
use WCAA\Models\User\User;
use WCAA\Models\User\UserAuthKey;
use WCAA\Storage\UserAuthKeyStorage;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;

/**
 * @OA\Delete(
 *   path="/user-session-close/{id}",
 *   tags={"user"},
 *   security={{"XAuthKey": {}}},
 *   summary="Close user session",
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer", example=15)),
 *   @OA\Response(
 *     response=200,
 *     description="Closed session",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", ref="#/components/schemas/UserSession")
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized"),
 *   @OA\Response(response=403, description="Forbidden")
 * )
 */
class CloseUserSessionAction extends  UserAction
{

    /**
     * @Inject
     * @var UserAuthKeyStorage
     */
    protected $userKeyStorage;

    /**
     * @return Response
     * @throws HttpBadRequestException
     */

    protected function action(): Response
    {
        $id = $this->request->getAttribute('id');
        $key = $this->userKeyStorage->fill((new UserAuthKey())->setId($id));
        if(!$this->user->isRulePermitted('user_management') && $this->user->isRulePermitted('user_self_control') && $key->getUserId() != $this->user->getId()) {
            throw new HttpForbiddenException($this->request, "You dont have permissions for close sessions");
        }
        $key = $this->userKeyStorage->update($key->setStatus(UserAuthKey::STATUS_CLOSED));
        $k = $key->getAsArray();
        unset($k['user']);
        unset($k['key']);
        return  $this->respondWithData($k);
    }
}
