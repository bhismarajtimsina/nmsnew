<?php


namespace WCAA\Api\Actions\User;

use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use Slim\Exception\HttpForbiddenException;
use WCAA\Api\Actions\Auth\UserAuthAction;
use WCAA\Api\Auth;
use WCAA\Models\User\User;
use WCAA\Models\User\UserAuthKey;
use WCAA\Storage\UserAuthKeyStorage;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;

/**
 * @OA\Put(
 *   path="/user/{id}/generate-auth-key",
 *   tags={"user"},
 *   security={{"XAuthKey": {}}},
 *   summary="Generate manual auth key for user",
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer", example=12)),
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       type="object",
 *       required={"expired_at","current_password"},
 *       @OA\Property(property="expired_at", type="string", format="date", example="2026-12-31"),
 *       @OA\Property(property="current_password", type="string", format="password", example="secret"),
 *       @OA\Property(property="description", type="string", nullable=true, example="Token for integration")
 *     )
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Created auth key",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", ref="#/components/schemas/UserAuthKey")
 *     )
 *   ),
 *   @OA\Response(response=400, description="Bad Request"),
 *   @OA\Response(response=401, description="Unauthorized"),
 *   @OA\Response(response=403, description="Forbidden")
 * )
 */
class GenerateUserTokenAction extends  UserAction
{

    /**
     * @Inject
     * @var UserAuthKeyStorage
     */
    protected $userKeyStorage;

    /**
     * @Inject
     * @var Auth
     */
    protected $auth;

    /**
     * @return Response
     * @throws HttpBadRequestException
     */

    protected function action(): Response
    {
        $form = $this->getFormData();
        $user = null;
        if($this->request->getAttribute('id') && $this->request->getAttribute('id') != 0) {
            if($this->request->getAttribute('id') != $this->user->getId() && !$this->user->isRulePermitted('user_management')) {
                throw new HttpForbiddenException($this->request, "You dont have permissions for generate user token for another user (need user_management permission)");
            }
            $user = $this->userStorage->getById($this->request->getAttribute('id'));
        }  else {
            $user = $this->user;
        }
        if(!isset($form['expired_at'])) {
            throw new HttpBadRequestException($this->request, "expired_at parameter is required");
        } else {
            $form['expired_at'] = explode("T", $form['expired_at'])[0] . " 00:00:00";
        }
        if(!isset($form['current_password'])) {
            throw new HttpBadRequestException($this->request, "current_password parameter is required");
        }
        /**
         * Генерирует исключение при несовпадении паролей
         */
        $this->auth->checkPair($this->user->getLogin(), $form['current_password']);

        $authKey = (new UserAuthKey())
            ->setUser($user)
            ->setExpiredAt($form['expired_at'])
            ->setDescription($form['description'])
            ->setIsManual(true)
            ->setStatus(UserAuthKey::STATUS_ACTIVE)
            ->setRemoteAddr($this->request->getAttribute('REMOTE_ADDR'));

        $key = $this->userKeyStorage->add($authKey);
        $k = $key->getAsArray();
        return  $this->respondWithData($k);
    }
}
