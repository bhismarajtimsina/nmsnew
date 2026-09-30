<?php


namespace WCAA\Api\Actions\Auth;


use DeviceDetector\DeviceDetector;
use DeviceDetector\Parser\Device\AbstractDeviceParser;
use DI\Annotation\Inject;
use Exception;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpUnauthorizedException;
use WCAA\Api\Actions\Action;
use WCAA\Api\Auth;
use WCAA\App;
use WCAA\Infrastructure\SystemActionLogger;
use WCAA\Interfaces\CacheInterface;
use WCAA\Models\SystemAction;
use WCAA\Services\GoogleAuthenticator;
use WCAA\Storage\SystemActionsStorage;
use WCAA\Storage\UserAuthKeyStorage;
use WCAA\Storage\UserStorage;

/**
 * @OA\Post(
 *   path="/auth",
 *   tags={"auth"},
 *   summary="User authentication",
 *   description="Authenticates user by login and password. If two-factor authentication is enabled and PIN is not provided, returns need_2fa=true.",
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       type="object",
 *       required={"login","password"},
 *       @OA\Property(property="login", type="string", example="admin"),
 *       @OA\Property(property="password", type="string", format="password", example="secret"),
 *       @OA\Property(property="twofa_pin", type="string", nullable=true, example="123456")
 *     )
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Successful authentication or 2FA challenge",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(
 *         property="data",
 *         oneOf={
 *           @OA\Schema(
 *             type="object",
 *             @OA\Property(property="need_2fa", type="boolean", example=true)
 *           ),
 *           @OA\Schema(ref="#/components/schemas/UserAuthKey")
 *         }
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
 *       @OA\Property(property="message", type="string", example="Login and password are required fields")
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized"),
 *   @OA\Response(response=403, description="Forbidden")
 * )
 */
class UserAuthAction extends Action
{
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
     * @Inject
     * @var UserStorage
     */
    protected $userStorage;


    /**
     * @Inject
     * @var GoogleAuthenticator
     */
    protected $googleAuthenticator;

    /**
     * @Inject
     * @var CacheInterface
     */
    protected $cache;

    /**
     * @return Response
     * @throws HttpBadRequestException
     * @throws HttpUnauthorizedException
     */
    protected function action(): Response
    {
        $remoteIp = $this->getUserIp($this->request);

        $authAttemptsCacheKey = 'auth_attempts-' . $remoteIp;
        $authAttempts = $this->cache->get($authAttemptsCacheKey);
        $authAttempts = !is_numeric($authAttempts) ? 0 : $authAttempts;

        if (App::getInstance()->conf('rate_limiter.enabled') && $authAttempts >= App::getInstance()->conf('rate_limiter.attempts')) {
            throw new HttpForbiddenException($this->request, "You was blocked by security reasons.");
        }

        $data = $this->getFormData();
        if (!isset($data['login']) || !isset($data['password'])) {
            throw new HttpBadRequestException($this->request, "Login and password are required fields");
        }
        $user = null;
        try {
            $user = $this->auth->checkPair($data['login'], $data['password']);
        } catch (Exception $e) {
            if (App::getInstance()->conf('rate_limiter.enabled')) {
                $authAttempts++;
                if ($authAttempts >= App::getInstance()->conf('rate_limiter.attempts')) {
                    $this->sysLogger->success("security:auth_attempts", "IP {$remoteIp} blocked by number of authorization attempts", [
                        'remoteIp' => $remoteIp,
                        'attempts' => $authAttempts,
                        'unblock_time' => date("Y-m-d H:i:s", time() + App::getInstance()->conf('rate_limiter.time'))
                    ], null, App::getInstance()->getSysUser());
                    $this->cache->set($authAttemptsCacheKey, $authAttempts, App::getInstance()->conf('rate_limiter.time'));
                } else {
                    $this->cache->set($authAttemptsCacheKey, $authAttempts, 600);
                }
            }
            throw new HttpUnauthorizedException($this->request, $e->getMessage());
        }

        $this->cache->set($authAttemptsCacheKey, 0, 1);

        /**
         * 2FA block
         */
        if ($user->getIsTwofa() && empty($data['twofa_pin'])) {
            return $this->respondWithData(['need_2fa' => true]);
        } else if ($user->getIsTwofa() && !empty($data['twofa_pin'])) {
            $success = $this->googleAuthenticator->check2FA($user, $data['twofa_pin']);

            if (!$success) {
                throw new HttpForbiddenException($this->request, 'Wrong 2FA pin-code.');
            }
        }

        if (!$user->isPermitByIp($remoteIp)) {
            throw new HttpForbiddenException($this->request, "Strict access by IP is enabled, IP $remoteIp not allowed for current user.");
        }

        $key = $this->auth->generateKey($user);
        $key->setUserAgent($this->request->getHeaderLine('User-Agent'));
        $key->setRemoteAddr($remoteIp);


        AbstractDeviceParser::setVersionTruncation(AbstractDeviceParser::VERSION_TRUNCATION_NONE);
        $dd = new DeviceDetector($this->request->getHeaderLine('User-Agent'));
        $dd->parse();

        if ($dd->isBot()) {
            $key->setDeviceInfo([
                'bot' => $dd->getBot(),
                'client' => null,
                'os_info' => null,
                'device' => null,
                'brand' => null,
                'model' => null,
            ]);
        } else {
            $key->setDeviceInfo([
                'bot' => null,
                'client' => $dd->getClient(),
                'os_info' => $dd->getOs(),
                'device' => $dd->getDeviceName(),
                'brand' => $dd->getBrandName(),
                'model' => $dd->getModel(),
            ]);
        }
        $this->sysLogger->success(
            "user:logged_in",
            "User success logined from IP {$key->getRemoteAddr()}",
            ['dev_info' => $key->getDeviceInfo(), 'remote_addr' => $key->getRemoteAddr(), 'key_expired_at' => $key->getExpiredAt()],
            null,
            $user
        );
        $this->keyStorage->update($key);

        return $this->respondWithData($key->getAsArray());
    }

    private function getUserIp(Request $request)
    {
        $remoteIPs = $request->getHeader('WCA-Real-Ip');
        $ipAddr = $_SERVER['REMOTE_ADDR'];
        if ($remoteIPs) {
            $ipAddr = $remoteIPs[0];
        }
        $proxyConf = App::getInstance()->conf('api.proxy');
        if ($proxyConf['enabled']) {
            $proxiedAddress = $request->getHeader($proxyConf['real_ip_header']);
            if (!$proxiedAddress) {
                throw new \Exception("Check trusted IP over proxy is enabled, but header with name {$proxyConf['real_ip_header']} not found. RealIP - {$ipAddr}");
            }
            if ($proxiedAddress && is_array($proxiedAddress) && count($proxiedAddress) > 0) {
                $ipAddr = $proxiedAddress[0];
            } else if ($proxiedAddress && is_string($ipAddr)) {
                $ipAddr = $proxiedAddress;
            }
        }
        return $ipAddr;
    }
}
