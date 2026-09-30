<?php


namespace WCAA\Api\Actions\Auth;


use DeviceDetector\DeviceDetector;
use DeviceDetector\Parser\Device\AbstractDeviceParser;
use Exception;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpUnauthorizedException;
use WCAA\Api\Actions\Action;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\Auth;
use WCAA\App;
use WCAA\Infrastructure\SystemActionLogger;
use WCAA\Interfaces\CacheInterface;
use WCAA\Models\SystemAction;
use WCAA\Models\User\UserAuthKey;
use WCAA\Storage\SystemActionsStorage;
use WCAA\Storage\UserAuthKeyStorage;
use WCAA\Storage\UserStorage;

/**
 * @OA\Post(
 *   path="/system/cross-auth",
 *   tags={"auth"},
 *   security={{"XAuthKey": {}}},
 *   summary="Cross-authenticate as another user",
 *   description="Creates temporary auth key for target user by login. Requires system_cross_auth_user permission.",
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       type="object",
 *       required={"login"},
 *       @OA\Property(property="login", type="string", example="operator"),
 *       @OA\Property(property="expire_sec", type="integer", nullable=true, example=3600),
 *       @OA\Property(property="user_ip", type="string", nullable=true, example="10.0.0.5")
 *     )
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Cross-auth key generated",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(
 *         property="data",
 *         type="object",
 *         @OA\Property(property="user", ref="#/components/schemas/UserShort"),
 *         @OA\Property(property="expired_at", type="string", example="2026-02-25 15:20:00"),
 *         @OA\Property(property="auth_key", type="string", example="0b53d498-b8fe-4be2-a24f-39ec9ef72e4a")
 *       )
 *     )
 *   ),
 *   @OA\Response(
 *     response=400,
 *     description="Bad Request",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=400),
 *       @OA\Property(property="error", type="string", example="Bad Request"),
 *       @OA\Property(property="message", type="string", example="Login is required field")
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized"),
 *   @OA\Response(response=403, description="Forbidden")
 * )
 */
class CrossAuthAction extends PrivateAction
{
    protected $forbiddenInDemo = true;
    /**
     * @Inject
     * @var \WCAA\Api\Auth
     */
    protected $auth;

    /**
     * @Inject
     * @var SystemActionLogger
     */
    protected $sysLogger;



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

    /**
     * @Inject
     * @var UserStorage
     */
    protected $userStorage;

    /**
     * @Inject
     * @var CacheInterface
     */
    protected $cache;

    protected function action(): Response
    {
        $data = $this->getFormData();
        if(!$this->user->getRole()->isPermitted('system_cross_auth_user')) {
            throw new HttpForbiddenException($this->request, "You don't have permissions for cross-login");
        }
        if (!isset($data['login'])) {
            throw new HttpBadRequestException($this->request, "Login is required field");
        }


        $user = $this->userStorage->getUserByLogin($data['login']);
        if(!$user) {
            throw new HttpBadRequestException($this->request, "User not found");
        }

        if($cached = $this->cache->get("CROSS_AUTH_LOGIN:{$this->user->getId()}:{$user->getId()}")) {
            if($key = $this->keyStorage->findByKey($cached['auth_key'])) {
                if($key->getStatus() == UserAuthKey::STATUS_ACTIVE) {
                    return  $this->respondWithData($cached);
                }
            }
        }

        $expiredSec = null;
        if(isset($data['expire_sec'])) {
            $expiredSec = $data['expire_sec'];
        }

        $userIP = "0.0.0.0";
        if(isset($data['user_ip'])) {
            $userIP = $data['user_ip'];
        }

        $key = $this->auth->generateKey($user, $expiredSec);
        $key->setUserAgent($this->request->getHeaderLine('User-Agent'));
        $key->setRemoteAddr($userIP);

        $this->sysLogger->success(
            "user:cross_logged_in",
            "User {$this->user->getLogin()} authorized user {$user->getLogin()}",
            ['remote_addr' => $key->getRemoteAddr(), 'key_expired_at' => $key->getExpiredAt(), 'root_user' => $this->user->getAsArray(), 'child_user'=>$user->getAsArray()],
            null,
            $user,
        );
        $this->keyStorage->update($key);

        $result = [
            'user' => [
                'id' => $key->getUser()->getId(),
                'login' => $key->getUser()->getLogin(),
                'name' => $key->getUser()->getName(),
            ],
            'expired_at' => $key->getExpiredAt(),
            'auth_key' => $key->getKey(),
        ];
        $expired = \DateTime::createFromFormat("Y-m-d H:i:s", $key->getExpiredAt())->getTimestamp() - time();
        $this->cache->set("CROSS_AUTH_LOGIN:{$this->user->getId()}:{$user->getId()}", $result, $expired + 30);
        return $this->respondWithData($result);
    }
    private function getUserIp(Request $request) {
        $remoteIPs = $request->getHeader('WCA-Real-Ip');
        $ipAddr = $_SERVER['REMOTE_ADDR'];
        if($remoteIPs) {
            $ipAddr = $remoteIPs[0];
        }
        $proxyConf = App::getInstance()->conf('api.proxy');
        if ($proxyConf['enabled']) {
            $proxiedAddress = $request->getHeader($proxyConf['real_ip_header']);
            if(!$proxiedAddress) {
                throw new \Exception("Check trusted IP over proxy is enabled, but header with name {$proxyConf['real_ip_header']} not found. RealIP - {$ipAddr}");
            }
            if ($proxiedAddress && is_array($proxiedAddress) && count($proxiedAddress) > 0) {
                $ipAddr = $proxiedAddress[0];
            } else if($proxiedAddress && is_string($ipAddr)) {
                $ipAddr = $proxiedAddress;
            }
        }
        return $ipAddr;
    }
}
