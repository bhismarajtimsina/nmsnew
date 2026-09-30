<?php


namespace WCAA\Api\Actions\User;

use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use WCAA\Models\User\UserAuthKey;
use WCAA\Services\GoogleAuthenticator;
use WCAA\Storage\UserAuthKeyStorage;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpForbiddenException;
use WCAA\Storage\UserStorage;

/**
 * @OA\Get(
 *   path="/user/{id}/2fa",
 *   tags={"user"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get 2FA QR data for user",
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string", example="self")),
 *   @OA\Response(
 *     response=200,
 *     description="2FA data",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(
 *         property="data",
 *         type="object",
 *         @OA\Property(property="twofaData", type="object", additionalProperties=true)
 *       )
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized"),
 *   @OA\Response(response=403, description="Forbidden")
 * )
 */
class GetUser2faAction extends  UserAction
{

    /**
     * @Inject
     * @var GoogleAuthenticator
     */
    protected $googleAuthenticator;

    /**
     * @Inject
     * @var UserStorage
     */
    protected $userStorage;

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

        if (!$user->getIsTwofa()) {

            if (!$user->getTwofaToken() || strlen($user->getTwofaToken()) > 25 ||
                strpos($user->getTwofaToken(), '=') !== false) {
                $token = $this->googleAuthenticator->generateSecret();
                $user->setTwofaToken($token);
                $user = $this->userStorage->update($user);
            }

        }

        $data = $this->googleAuthenticator->getQR($user);

        return $this->respondWithData(['twofaData' => $data]);
    }
}
