<?php


namespace WCAA\Api\Actions\System;


use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use WCAA\Api\Actions\PrivateAction;
use WCAA\App;
use WCAA\Models\User\User;
use WCAA\Storage\SystemComponentsStorage;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * @OA\Get(
 *   path="/system/settings",
 *   tags={"system"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get system properties for frontend",
 *   @OA\Response(
 *     response=200,
 *     description="System properties",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(
 *         property="data",
 *         type="object",
 *         @OA\Property(property="search2", type="object", additionalProperties=true),
 *         @OA\Property(property="map", type="object", additionalProperties=true),
 *         @OA\Property(property="permissions", type="array", @OA\Items(ref="#/components/schemas/PermissionRule")),
 *         @OA\Property(property="zoom", type="object", additionalProperties=true),
 *         @OA\Property(property="version", type="string", example="1.2.3"),
 *         @OA\Property(property="build_date", type="string", example="20260225"),
 *         @OA\Property(property="commit_id", type="string", example="abcdef0"),
 *         @OA\Property(property="languages", type="object", additionalProperties=true),
 *         @OA\Property(
 *           property="components",
 *           type="object",
 *           @OA\Property(property="enabled", type="array", @OA\Items(type="string")),
 *           @OA\Property(property="all", type="array", @OA\Items(ref="#/components/schemas/SystemComponent"))
 *         ),
 *         @OA\Property(property="user", ref="#/components/schemas/User"),
 *         @OA\Property(property="default_role_permissions", type="array", @OA\Items(type="string"))
 *       )
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class SystemPropertiesAction extends PrivateAction
{

    /**
     * @Inject
     * @var SystemComponentsStorage
     */
    protected $moduleStorage;


    /**
     * @Inject
     * @var App
     */
    protected $app;

    /**
     * @Inject
     * @var User
     */
    protected $user;

    /**
     * @return Response
     */
    protected function action(): Response
    {
        $permissions = [];
        foreach ($this->app->conf('api.auth.rules') as $perm) {
            $permissions[] = [
                'key' => $perm['key'],
                'description' => isset($perm['description']) ? $perm['description'] : "",
                'logic_group' => isset($perm['logic_group']) ? $perm['logic_group'] : '',
            ];
        }
        return $this->respondWithData([
            'search2' => $this->app->conf('search2'),
            'map'=> [
              'coordinates' => $this->app->conf('panel.map.coordinates'),
              'default_tile' => $this->app->conf('panel.map.default_tile'),
            ],
            'permissions' => $permissions,
            'zoom' => [
              'main' => _env('WEB_ZOOM_MAIN', 1.0),
              'iframe' => _env('WEB_ZOOM_IFRAME', 1.0),
            ],
            'version' => _env('VERSION', '0.0.0'),
            'build_date' => _env('BUILD_DATE', date("Ymd")),
            'commit_id' => _env('COMMIT_ID', '0000000'),
            'languages' => $this->app->conf('language'),
            'components' => [
                'enabled' => $this->getEnabledModules(),
                'all' => array_map(function ($module) {
                    return $module->getAsArray();
                }, $this->moduleStorage->fetchAll())
            ],
            'user' => $this->user->getAsArray(),
            'default_role_permissions' => $this->app->conf('api.auth.default_role_permissions'),
        ]);
    }

    protected function getEnabledModules()
    {
        $componentInjector = $this->app->getComponentInjector();
        $modules = $this->moduleStorage->fetchAll();
        $keys = [];
        foreach ($modules as $module) {
            if(!$componentInjector->isComponentExist($module->getKey())) continue;
            if (!$module->isEnabled()) continue;
            $keys[] = $module->getKey();
        }
        return $keys;
    }

}
