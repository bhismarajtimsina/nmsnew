<?php
declare(strict_types=1);

namespace WCAA\Api\Middleware;

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

class AuthStrictByIpMiddleware implements Middleware
{
    protected $app;

    protected $auth;
    protected $cache;
    protected $trust;
    static protected $token;

    /**
     * {@inheritdoc}
     */
    public function process(Request $request, RequestHandler $handler): Response
    {
        /**
         * @var User $user
         */
        $user = $request->getAttribute('AUTH_USER');
        $remoteIp = $this->getUserIp($request);
        if(!$user->isPermitByIp($this->getUserIp($request))) {
            throw new HttpUnauthorizedException($request, "Strict access by IP is enabled, IP $remoteIp not allowed for current user.");
        }
        return $handler->handle($request);
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
            if ($proxiedAddress && is_array($proxiedAddress) && count($proxiedAddress) > 0) {
                $ipAddr = $proxiedAddress[0];
            } else if($proxiedAddress && is_string($ipAddr)) {
                $ipAddr = $proxiedAddress;
            }
        }
        return $ipAddr;
    }

}
