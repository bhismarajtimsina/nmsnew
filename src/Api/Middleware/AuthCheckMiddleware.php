<?php
declare(strict_types=1);

namespace WCAA\Api\Middleware;

use Monolog\Logger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface as Middleware;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpUnauthorizedException;
use WCAA\Api\Auth;
use WCAA\App;
use WCAA\Exceptions\ApiKeyNotSettedException;
use WCAA\Exceptions\SupportException;
use WCAA\Infrastructure\TrustedIps;
use WCAA\Interfaces\CacheInterface;
use WCAA\Models\User\User;
use WCAA\Storage\UserStorage;

class AuthCheckMiddleware implements Middleware
{
    protected $app;

    protected $auth;
    protected $cache;
    protected $trust;
    static protected $token;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * {@inheritdoc}
     */
    public function process(Request $request, RequestHandler $handler): Response
    {
        $remoteIP = $this->getUserIp($request);
        if($remoteIP && $this->trust->isWca($remoteIP)) {
            $user = App::getInstance()->getSysUser();
            App::getInstance()->getContainer()->set(User::class, $user);
            return  $handler->handle($request->withAttribute("REMOTE_ADDR", $remoteIP)->withAttribute('AUTH_USER', $user));
        }

        try {
            $request = $this->checkKey($request, $handler);
            return $handler->handle($request->withAttribute('REMOTE_ADDR', $remoteIP));
        } catch (ApiKeyNotSettedException $e) {
            if ($this->app->conf('api.auth.trusted_networks')) {
                $request = $this->checkTrustedIps($request, $handler);
            } else {
                throw $e;
            }
            return $handler->handle($request);
        }
    }

    public function __construct(CacheInterface $cache, App $app, UserStorage $userStorage, TrustedIps $trust, Auth $auth, Logger $logger)
    {
        $this->cache = $cache;
        $this->app = $app;
        $this->userStorage = $userStorage;
        $this->trust = $trust;
        $this->auth = $auth;
        $this->logger = $logger;
    }

    function checkKey(Request $request, RequestHandler $handler)
    {
        $token = "";
        $tokenHeader = $request->getHeader('X-Auth-Key');
        if (count($tokenHeader) != 0) {
            $token = $tokenHeader[0];
        } else if (isset($request->getQueryParams()['x-auth-key'])) {
            $token = $request->getQueryParams()['x-auth-key'];
        }
        if ($token) {
            try {
                if (!$this->auth->isKeyValid($token)) {
                    $this->logger->error("Invalid API key - " . self::maskToken($token));
                    throw new HttpUnauthorizedException($request, "Incorrect user token");
                }
            } catch (SupportException $e) {
                $this->logger->error("Error auth user with key " . self::maskToken($token) . " - {$e->getMessage()}");
                throw new HttpUnauthorizedException($request, "Incorrect user token");
            }
            if(!$this->cache->get("USER_ACTIVITY:{$token}")) {
                $this->auth->updateLastActivity($token);
                $this->cache->set("USER_ACTIVITY:{$token}", true, 60);
            }
            $user = $this->auth->getUserByKey($token);
            $this->logger->debug("Start request with user = {$user->getLogin()}", $user->getAsArray());
            App::getInstance()->getContainer()->set(User::class, $user);
            $request = $request->withAttribute('AUTH_USER', $user)
                ->withAttribute('AUTH_KEY', $token);
        } else {
            throw new ApiKeyNotSettedException("X-Auth-Key not setted");
        }
        return $request;
    }

    function checkTrustedIps(Request $request, RequestHandler $handler)
    {
        $ipAddr = $this->getUserIp($request);
        if (!$this->trust->isAllowed($ipAddr)) {
            throw new HttpUnauthorizedException($request, "$ipAddr not listed in trusted Ips");
        }
        $user = $this->getUser($request);
        if ($user) {
            App::getInstance()->getContainer()->set(User::class, $user);
        } else {
            $user = App::getInstance()->getSysUser();
            App::getInstance()->getContainer()->set(User::class, $user);
        }

        return $request->withAttribute('REMOTE_ADDR', $ipAddr)->withAttribute('AUTH_USER', $user);
    }

    /**
     * Replace all but the last 4 characters of a token so logs stay correlatable
     * without carrying a usable credential.
     *
     * @param string $token
     * @return string
     */
    private static function maskToken($token)
    {
        if (!is_string($token) || strlen($token) <= 4) {
            return '***';
        }
        return str_repeat('*', strlen($token) - 4) . substr($token, -4);
    }

    private function getUserIp(Request $request) {
        $peerAddr = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        $ipAddr = $peerAddr;

        //WCA-Real-Ip and the configured proxy header are only meaningful when the
        //request actually arrived through the bundled nginx, which overwrites them.
        //Honouring them from an arbitrary peer would let a client name its own source
        //address - and isWca() below grants system-user access based on that address.
        try {
            $peerIsTrustedProxy = $peerAddr !== '' && $this->trust->isWca($peerAddr);
        } catch (\Throwable $e) {
            //An address the subnet calculator cannot parse is not a trusted peer.
            $peerIsTrustedProxy = false;
        }
        if (!$peerIsTrustedProxy) {
            return $ipAddr;
        }

        $remoteIPs = $request->getHeader('WCA-Real-Ip');
        if($remoteIPs) {
            $ipAddr = $remoteIPs[0];
        }
        $proxyConf = App::getInstance()->conf('api.proxy');
        if ($proxyConf['enabled']) {
            $proxiedAddress = $request->getHeader($proxyConf['real_ip_header']);
            if (is_array($proxiedAddress) && count($proxiedAddress) > 0) {
                //X-Forwarded-For may be a chain; the original client is the first entry.
                $ipAddr = trim(explode(',', $proxiedAddress[0])[0]);
            }
        }
        return $ipAddr;
    }

    private function getUser(Request $request)
    {
        $token = "";
        $tokenHeader = $request->getHeader('X-Auth-Key');
        if (count($tokenHeader) != 0) {
            $token = $tokenHeader[0];
        } else if (isset($request->getQueryParams()['x-auth-key'])) {
            $token = $request->getQueryParams()['x-auth-key'];
        }
        if ($token) {
            try {
                if (!$this->auth->isKeyValid($token)) {
                    return null;
                }
            } catch (\Exception $e) {
                return null;
            }
            $this->auth->updateLastActivity($token);
            $user = $this->auth->getUserByKey($token);
            return $user;
        }
        $params = $request->getQueryParams();
        if (isset($params['USER_ID']) && $params['USER_ID']) {
            return $this->userStorage->getById($params['USER_ID']);
        }
        return null;
    }

}
