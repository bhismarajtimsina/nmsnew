<?php

namespace WCAA\Api\Actions\System;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpUnauthorizedException;
use WCAA\Api\Actions\Action;
use WCAA\Api\Auth;
use WCAA\Storage\UserStorage;

/**
 * @OA\Post(
 *   path="/app-auth",
 *   tags={"system"},
 *   summary="Authorize external application by auth key",
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       type="object",
 *       required={"app","auth_key"},
 *       @OA\Property(property="app", type="string", example="portal"),
 *       @OA\Property(property="auth_key", type="string", example="0b53d498-b8fe-4be2-a24f-39ec9ef72e4a")
 *     )
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Authorized user for app",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="object", additionalProperties=true)
 *     )
 *   ),
 *   @OA\Response(response=400, description="Bad Request"),
 *   @OA\Response(response=401, description="Unauthorized"),
 *   @OA\Response(response=403, description="Forbidden")
 * )
 */
class ExternalAppsAuth extends Action
{
    /**
     * @Inject
     * @var UserStorage
     */
    protected $userStorage;

    /**
     * @Inject
     * @var Auth
     */
    protected $auth;

    protected function action(): Response
    {
        $queries = $this->getFormData();
        if(!isset($queries['app'])) {
            throw new HttpBadRequestException($this->request, "app is required");
        }
        if(!isset($queries['auth_key'])) {
            throw new HttpBadRequestException($this->request, "auth_key is required");
        }
        try {
            $this->auth->isKeyValid($queries['auth_key']);
        } catch (\Throwable $e) {
            throw new HttpUnauthorizedException($this->request, "auth_key is invalid");
        }

        $user = $this->auth->getUserByKey($queries['auth_key']);

        if(!$user->isRulePermitted("external_apps_{$queries['app']}")) {
            throw new HttpForbiddenException($this->request, "You don't have permissions to current app");
        } else {
            $userArray = $user->getAsArray();
            unset(
                $userArray['settings'],
                $userArray['device_groups'],
                $userArray['role']['permissions'],
            );
            $userArray['as_admin'] = $user->isRulePermitted("external_apps_{$queries['app']}_admin");
            return $this->respondWithData($userArray);
        }
    }

}
