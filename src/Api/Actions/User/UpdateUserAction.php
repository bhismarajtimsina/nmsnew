<?php


namespace WCAA\Api\Actions\User;

use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use Slim\Exception\HttpSpecializedException;
use WCAA\App;
use WCAA\Exceptions\StorageExceptions\NotEnoughtRightsException;
use WCAA\Infrastructure\Security\Passwords;
use WCAA\Models\Devices\DeviceGroup;
use WCAA\Models\User\User;
use WCAA\Models\User\UserAuthKey;
use WCAA\Storage\UserAuthKeyStorage;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpForbiddenException;

/**
 * @OA\Put(
 *   path="/user/{id}",
 *   tags={"user"},
 *   security={{"XAuthKey": {}}},
 *   summary="Update user by id",
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string", example="12")),
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="name", type="string", nullable=true),
 *       @OA\Property(property="login", type="string", nullable=true),
 *       @OA\Property(property="password", type="string", nullable=true, format="password"),
 *       @OA\Property(property="status", type="string", nullable=true, example="ENABLED"),
 *       @OA\Property(property="language", type="string", nullable=true),
 *       @OA\Property(property="is_twofa", type="boolean", nullable=true),
 *       @OA\Property(property="settings", type="object", nullable=true, additionalProperties=true),
 *       @OA\Property(property="role", type="object", nullable=true, @OA\Property(property="id", type="integer", example=2)),
 *       @OA\Property(property="device_groups", type="array", nullable=true, @OA\Items(type="object", @OA\Property(property="id", type="integer", example=3)))
 *     )
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Updated user",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", ref="#/components/schemas/User")
 *     )
 *   ),
 *   @OA\Response(response=400, description="Bad Request"),
 *   @OA\Response(response=401, description="Unauthorized"),
 *   @OA\Response(response=403, description="Forbidden")
 * )
 * @OA\Put(
 *   path="/user",
 *   tags={"user"},
 *   security={{"XAuthKey": {}}},
 *   summary="Update current user",
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="name", type="string", nullable=true),
 *       @OA\Property(property="login", type="string", nullable=true),
 *       @OA\Property(property="password", type="string", nullable=true, format="password"),
 *       @OA\Property(property="status", type="string", nullable=true, example="ENABLED"),
 *       @OA\Property(property="language", type="string", nullable=true),
 *       @OA\Property(property="is_twofa", type="boolean", nullable=true),
 *       @OA\Property(property="settings", type="object", nullable=true, additionalProperties=true),
 *       @OA\Property(property="role", type="object", nullable=true, @OA\Property(property="id", type="integer", example=2)),
 *       @OA\Property(property="device_groups", type="array", nullable=true, @OA\Items(type="object", @OA\Property(property="id", type="integer", example=3)))
 *     )
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Updated user",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", ref="#/components/schemas/User")
 *     )
 *   ),
 *   @OA\Response(response=400, description="Bad Request"),
 *   @OA\Response(response=401, description="Unauthorized"),
 *   @OA\Response(response=403, description="Forbidden")
 * )
 */
class UpdateUserAction extends  UserAction
{

    protected $forbiddenInDemo = true;

    /**
     * @Inject
     * @var UserAuthKeyStorage
     */
    protected $userAuthStorage;


    /**
     * @Inject
     * @var Passwords
     */
    protected $passwords;

    /**
     * @return Response
     * @throws HttpBadRequestException
     */


    protected function action(): Response
    {
        $sessionsMustBeClosed = false;
        $data = $this->getFormData();
        $id = $this->request->getAttribute('id');
        if(!$id || $id == 'self') {
           $id = $this->user->getId();
        }

        if(!$this->user->isRulePermitted('user_management') && (int)$this->user->getId() != (int)$id) {
            throw new HttpForbiddenException($this->request, "Not enough rights for edit user");
        }
        $user = $this->userStorage->fill((new User())->setId($id));
        if(isset($data['name'])) {
            $user->setName($data['name']);
        }
        if(isset($data['is_twofa'])) {
            $user->setIsTwofa($data['is_twofa']);
        } elseif (empty($data['is_twofa'])) {
            $user->setTwofaToken(null);
        }

        if(isset($data['settings'])) {
            if(!$this->user->isRulePermitted('user_management_config_strict_ip')) {
                $settings = $user->getSettings();
                if(isset($settings['strict_access_by_ip'])) {
                    $data['settings']['strict_access_by_ip'] = $settings['strict_access_by_ip'];
                }
            }
            if(isset($data['settings']['strict_access_by_ip']) && $data['settings']['strict_access_by_ip']['enabled']) {
                foreach ($data['settings']['strict_access_by_ip']['networks'] as $key => $network) {
                    if(!$network) {
                        unset($data['settings']['strict_access_by_ip']['networks'][$key]);
                        continue;
                    }
                    if(preg_match('/^([0-9]{1,3}\.){3}[0-9]{1,3}$/', $network)) {
                        $data['settings']['strict_access_by_ip']['networks'][$key] = "{$network}/32";
                    }
                    if(!preg_match('/^([0-9]{1,3}\.){3}[0-9]{1,3}\/([0-9]{1,2})$/', $data['settings']['strict_access_by_ip']['networks'][$key])) {
                        throw new \Exception("Incorrect strict CIDR for network - {$network}");
                    }
                }
                if(count($data['settings']['strict_access_by_ip']['networks']) === 0) {
                    $data['settings']['strict_access_by_ip']['enabled'] = false;
                }
                $data['settings']['strict_access_by_ip']['networks'] = array_values($data['settings']['strict_access_by_ip']['networks']);
            }
            $user->setSettings($data['settings']);
        }
        if(isset($data['login']) && $data['login'] != $user->getLogin() && $this->user->isRulePermitted('user_management')) {
            $user->setLogin($data['login']);
            $sessionsMustBeClosed = true;
        }
        if(isset($data['status']) && $data['status'] != $user->getStatus() && $this->user->isRulePermitted('user_management')) {
            $user->setStatus($data['status']);
            if($data['status'] === 'DISABLED') {
                $sessionsMustBeClosed = true;
            }
        }
        if(isset($data['password']) && $data['password']) {
            if(_env('SECURE_CHECK_PASSWORD_STRENGTH', true) && $data['password']) {
                $warnings = $this->passwords->checkPasswordStrength($data['password']);
                if($warnings) {
                    throw new HttpBadRequestException($this->request, 'Password has warnings: ' . join(', ', $warnings));
                }
            }
            $user->setPassword($this->passwords->hash($data['password']));
            $sessionsMustBeClosed = true;
        }
        if(isset($data['language']) && $data['language']) {
            $user->setLanguage($data['language']);
        }

        if(isset($data['role']['id']) && $data['role']['id'] != $user->getRole()->getId()  && $this->user->isRulePermitted('user_management')) {
            $group = $this->groupStorage->getById($data['role']['id']);
            $user->setRole($group);
            $sessionsMustBeClosed = true;
        }

        if(isset($data['device_groups']) && $this->user->isRulePermitted('user_management')) {
            $user->setDeviceGroups(array_map(function($e) {return new DeviceGroup($e['id']);}, $data['device_groups']));
        }

        $user = $this->userStorage->update($user);
        $u = $user->getAsArray();
        if($sessionsMustBeClosed) {
            $sessions = $this->userAuthStorage->getSessionsByUser($user);
            foreach ($sessions as $session) {
                if($session->getStatus() === UserAuthKey::STATUS_ACTIVE) {
                    $session->setStatus(UserAuthKey::STATUS_CLOSED);
                    $this->userAuthStorage->update($session);
                }
            }
        }

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
