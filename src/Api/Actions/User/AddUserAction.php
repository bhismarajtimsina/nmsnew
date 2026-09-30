<?php


namespace WCAA\Api\Actions\User;

use OpenApi\Annotations as OA;
use WCAA\App;
use WCAA\Infrastructure\Security\Passwords;
use WCAA\Models\Devices\DeviceGroup;
use WCAA\Models\User\User;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;

/**
 * @OA\Post(
 *   path="/user",
 *   tags={"user"},
 *   security={{"XAuthKey": {}}},
 *   summary="Create user",
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       type="object",
 *       required={"name","login","password","role"},
 *       @OA\Property(property="name", type="string", example="Operator"),
 *       @OA\Property(property="login", type="string", example="operator"),
 *       @OA\Property(property="password", type="string", format="password", example="secret"),
 *       @OA\Property(property="language", type="string", nullable=true, example="en"),
 *       @OA\Property(property="settings", type="object", nullable=true, additionalProperties=true),
 *       @OA\Property(property="role", type="object", @OA\Property(property="id", type="integer", example=2)),
 *       @OA\Property(property="device_groups", type="array", @OA\Items(type="object", @OA\Property(property="id", type="integer", example=3)))
 *     )
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Created user",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", ref="#/components/schemas/User")
 *     )
 *   ),
 *   @OA\Response(response=400, description="Bad Request"),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class AddUserAction extends UserAction
{

    /**
     * @Inject
     * @var Passwords
     */
    protected $passwords;

    protected $forbiddenInDemo = true;

    /**
     * @return Response
     * @throws HttpBadRequestException
     */


    protected function action(): Response
    {
        $data = $this->getFormData();
        $keys = array_keys($data);
        if (!in_array('name', $keys)) {
            throw new HttpBadRequestException($this->request, "Name is required");
        }
        if (!in_array('role', $keys)) {
            throw new HttpBadRequestException($this->request, "Role is required");
        }
        if (!in_array('password', $keys)) {
            throw new HttpBadRequestException($this->request, "Password is required");
        }
        if (!in_array('login', $keys)) {
            throw new HttpBadRequestException($this->request, "Login is required");
        }
        $user = $this->userStorage->getUserByLogin($data['login']);
        if ($user) {
            throw new HttpBadRequestException($this->request, "User with login {$data['login']} already exist");
        }
        $user = new User();
        if (isset($data['device_groups'])) {
            $user->setDeviceGroups(array_map(function ($e) {
                return new DeviceGroup($e['id']);
            }, $data['device_groups']));

        }
        if(_env('SECURE_CHECK_PASSWORD_STRENGTH', true) && $data['password']) {
            $warnings = $this->passwords->checkPasswordStrength($data['password']);
            if($warnings) {
                throw new HttpBadRequestException($this->request, 'Password has warnings: ' . join(', ', $warnings));
            }
        }
        $user->setName($data['name'])
            ->setLogin($data['login'])
            ->setPassword($this->passwords->hash($data['password']))
            ->setLanguage((isset($data['language']) && $data['language']) ? $data['language'] : App::getInstance()->conf('language.default'))
            ->setSettings(isset($data['settings']) ? $data['settings'] : null)
            ->setRole($this->groupStorage->getById($data['role']['id']));
        $user = $this->userStorage->add($user);
        return $this->respondWithData($user->getAsArray());
    }
}
