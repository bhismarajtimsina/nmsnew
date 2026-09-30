<?php
declare(strict_types=1);

namespace WCAA\Api\Middleware;

use Slim\Exception\HttpSpecializedException;
use WCAA\App;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface as Middleware;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpInternalServerErrorException;
use Slim\Routing\RouteContext;
use WCAA\Exceptions\StorageExceptions\NotEnoughtRightsException;
use WCAA\Models\User\User;
use WCAA\Storage\Devices\DeviceGroupStorage;

class PermissionCheckMiddleware implements Middleware
{
    protected $app;

    /**
     * @Inject
     * @var DeviceGroupStorage
     */
    protected $deviceGroupsStorage;
    /**
     * {@inheritdoc}
     */
    public function process(Request $request, RequestHandler $handler): Response
    {
        /**
         * @var User $user;
         */
        $user = $request->getAttribute('AUTH_USER');
        if($user->getId() <= 0 || $user->getRole()->getId() <= 0) {
            $request = $request->withAttribute("ALLOWED_BY_RULES", []);
            $request = $request->withAttribute("ALLOWED_BY_ROOT_USERS", true);
            return  $handler->handle($request);
        }
        $perms = $user->role->getPermissions();
        $requestRuleNames = $this->getPermissionNamesFromRequest($request);

        if(!$requestRuleNames) {
            return  $handler->handle($request);
        }
        $request = $request->withAttribute("ALLOWED_BY_RULES", $requestRuleNames);
        if(count(array_intersect($requestRuleNames, $perms)) !== 0) {
            return  $handler->handle($request);
        }
        throw new HttpForbiddenException($request,"Insufficient rights to access this resource");
    }
    function getPermissionNamesFromRequest(Request $request) {

        $pattern = $request->getMethod() . ':' . $request->getRequestTarget();
        $names = [];
        foreach ($this->app->conf('api.auth.rules') as $permission) {
            if(!isset($permission['routes'])) continue;
            foreach ($permission['routes'] as $route) {
                if(preg_match("#{$route}#", $pattern)) {
                    $names[] = $permission['key'];
                }
            }
        }
        if($names) {
            return  $names;
        }
        $strictEnabled = $this->app->conf('api.auth.strict_rules');
        if($strictEnabled) {
            throw new HttpInternalServerErrorException($request,"Strict rules enabled. Rule not found for route $pattern");
        }
        return  null;
    }

    public function __construct(App $app )
    {
        $this->app = $app;
    }
}
