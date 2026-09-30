<?php


namespace WCAA\Api\Actions\Auth;


use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpUnauthorizedException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\Auth;
use WCAA\Models\SystemAction;
use WCAA\Storage\SystemActionsStorage;
use WCAA\Storage\UserAuthKeyStorage;

/**
 * @OA\Delete(
 *   path="/logout",
 *   tags={"auth"},
 *   security={{"XAuthKey": {}}},
 *   summary="Logout current user",
 *   description="Closes current auth session identified by XAuthKey token.",
 *   @OA\Response(
 *     response=200,
 *     description="Session successfully closed",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="object", nullable=true, example=null)
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class UserLogout extends PrivateAction
{
    /**
     * @Inject
     * @var Auth
     */
    protected $auth;

    /**
     * @Inject
     * @var SystemActionsStorage
     */
    protected $systemActionsStorage;

    /**
     * @Inject
     * @var UserAuthKeyStorage
     */
    protected $keyStorage;

    /**
     * @return Response
     * @throws HttpBadRequestException
     * @throws HttpUnauthorizedException
     */

    protected function action(): Response
    {
        $this->auth->logout($this->request->getAttribute('AUTH_KEY'));
        $this->systemActionsStorage->add(
            (new SystemAction())
                ->setUser($this->user)
                ->setStatus(SystemAction::STATUS_SUCCESS)
                ->setMessage("User logged out from system")
                ->setAction('user:logged_out')
        );
        return $this->respondWithData();
    }

}
