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
 * @OA\Put(
 *   path="/user/{id}/2fa/connect",
 *   tags={"user"},
 *   security={{"XAuthKey": {}}},
 *   summary="Enable or disable user 2FA",
 *   description="If twofa_pin is provided and valid, enables 2FA. If empty payload, disables 2FA.",
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string", example="self")),
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="twofa_pin", type="string", nullable=true, example="123456")
 *     )
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="2FA operation status",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="object", @OA\Property(property="success", type="boolean", example=true))
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized"),
 *   @OA\Response(response=403, description="Forbidden")
 * )
 */
class ConnectUser2faAction extends  UserAction
{
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

        $data = $this->getFormData();

        $user = $this->userStorage->getById($id);

        $googleAuthenticator = new GoogleAuthenticator();

        if(!empty($data['twofa_pin'])) {
            $success = $googleAuthenticator->check2FA($user, $data['twofa_pin']);

            if ($success) {
                $user->setIsTwofa(true);
                $this->userStorage->update($user);

                return $this->respondWithData(['success' => true]);
            }
        } else {
            $user->setIsTwofa(false);
            $this->userStorage->update($user);

            return $this->respondWithData(['success' => true]);
        }

        return $this->respondWithData(['success' => false]);
    }
}
