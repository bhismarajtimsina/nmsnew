<?php
declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Exception\HttpNotFoundException;
use Slim\Interfaces\RouteCollectorProxyInterface as Group;
use WCAA\Api\Actions\Devices\AddDeviceAction;
use WCAA\Api\Actions\Devices\DeleteDeviceAction;
use WCAA\Api\Actions\Devices\EditDeviceAction;
use WCAA\Api\Actions\Devices\ListDeviceAction;
use WCAA\Api\Actions\Devices\Models\AddModelAction;
use WCAA\Api\Actions\Devices\Models\DeleteModelAction;
use WCAA\Api\Actions\Devices\Models\EditModelAction;
use WCAA\Api\Actions\Devices\Models\ListModelAction;
use WCAA\Api\Actions\User\AddUserAction;
use WCAA\Api\Actions\User\DeleteUserAction;
use WCAA\Api\Actions\User\GetAllUsersAction;
use WCAA\Api\Actions\User\GetUserAction;
use WCAA\Api\Actions\User\GetUser2faAction;
use WCAA\Api\Actions\User\ConnectUser2faAction;
use WCAA\Api\Actions\User\UpdateUserAction;
use WCAA\Api\Actions\UserRole\AddUserRoleAction;
use WCAA\Api\Actions\UserRole\DeleteUserRoleUserAction;
use WCAA\Api\Actions\UserRole\GetAllUserRolesAction;
use WCAA\Api\Actions\UserRole\GetUserRoleUserAction;
use WCAA\Api\Actions\UserRole\PermissionListAction;
use WCAA\Api\Actions\UserRole\UpdateUserRoleUserAction;
use WCAA\Api\Middleware\PermissionCheckMiddleware;

return function (App $app) {

    $app->get('/metrics[/{prefix}]', \WCAA\Api\Actions\System\PrometheusMetricsAction::class)
        ->add(\WCAA\Api\Middleware\AuthStrictByIpMiddleware::class)->add(\WCAA\App::getInstance()->conf('api.auth.check_auth_middleware'));

    //API Block
    $app->group('/api', function (Group $group) {
        $group->get("/universal", \WCAA\Api\Actions\UniversalApi\UniversalApi::class);
        $group->map(['POST', 'GET'], '/switcher-core/{storage}/{module}/{device_id}', \WCAA\Api\Actions\SwitcherCore\SwitcherCoreAction::class)
            ->add(\WCAA\Api\Middleware\AuthStrictByIpMiddleware::class)->add(\WCAA\App::getInstance()->conf('api.auth.check_auth_middleware'));

        //Version 1
        $group->group('/v1', function (Group $group) {
            $group->group('/public', function (Group $group) {
                $group->get('/defaults', \WCAA\Api\Actions\Publics\GetDefaultsAction::class);
                $group->get('/translations[/{lang}]', \WCAA\Api\Actions\System\GetTranslations::class);
                $group->put('/translations', \WCAA\Api\Actions\System\SetTranslationKey::class);
                $group->get('/new-version', \WCAA\Api\Actions\System\CheckNewVersionAvailable::class);
            });
            $group->post('/auth', \WCAA\App::getInstance()->conf('api.auth.check_pair_method'));
            $group->post('/app-auth', \WCAA\Api\Actions\System\ExternalAppsAuth::class);
            $group->delete('/logout', \WCAA\Api\Actions\Auth\UserLogout::class)
                ->add(PermissionCheckMiddleware::class)
                ->add(\WCAA\App::getInstance()->conf('api.auth.check_auth_middleware'));

            //Private block
            $group->group('', function (Group $group) {
                //Work with components
                $group->group('/component', function (Group $group) {
                    $rootApp = \WCAA\App::getInstance();
                    $rootApp->getComponentInjector()->addRoutes($group);
                });

                //Block of portal
                $group->group('/portal', function (Group $group) {
                    $group->map(['GET', 'POST'], '/search', \WCAA\Api\Actions\GlobalSearchAction::class);
                    $group->map(['GET', 'POST'], '/nearest-elements', \WCAA\Api\Actions\NearestElementsAction::class);
                });
                $group->group('/dashboard', function (Group $group) {
                    $group->get('/template', \WCAA\Api\Actions\Dashboard\Template\GetDashboardAction::class);
                    $group->put('/template', \WCAA\Api\Actions\Dashboard\Template\UpdateDashboardAction::class);
                    $group->get('/latest-system-actions', \WCAA\Api\Actions\Dashboard\LatestSystemActions::class);
                    $group->get('/widget/error-calling-by-device', \WCAA\Api\Actions\Dashboard\Widgets\ErrorCallingByDevices::class);
                    $group->get('/widget/system-stat', \WCAA\Api\Actions\Dashboard\Widgets\SystemStat::class);
                    $group->get('/widget/ont-statuses', \WCAA\Api\Actions\Dashboard\Widgets\OntStatusesPie::class);
                    // The tiles each console opens on. Scoped to the caller's
                    // device groups in the storage, like every other listing.
                    $group->get('/widget/ont-offline-split', \WCAA\Api\Actions\Dashboard\Widgets\OntOfflineSplit::class);
                    $group->get('/widget/ports-down', \WCAA\Api\Actions\Dashboard\Widgets\PortsDown::class);
                    $group->get('/widget/poller-health', \WCAA\Api\Actions\Dashboard\Widgets\PollerHealth::class);
                    $group->get('/widget/pon-ports', \WCAA\Api\Actions\Dashboard\Widgets\PonPorts::class);
                });
                $group->group('/dev-dashboard', function (Group $group) {
                    $group->map(['GET', 'POST'], '/devices', \WCAA\Api\Actions\DeviceDashboard\DeviceListAction::class);
                    $group->get('/groups', \WCAA\Api\Actions\DeviceDashboard\ListGroupAction::class);
                    $group->get('/models', ListModelAction::class);
                });
                $group->group('/maps', function (Group $group) {
                    $group->get('/groups', \WCAA\Api\Actions\Maps\GetDeviceGroups::class);
                    $group->put('/devices', \WCAA\Api\Actions\Maps\GetDevices::class);
                    $group->put('/device-links', \WCAA\Api\Actions\Maps\GetDeviceLinks::class);
                    $group->put('/onts', \WCAA\Api\Actions\Maps\GetOntsList::class);
                });
                $group->group('/user', function (Group $group) {
                    $group->get('', GetAllUsersAction::class);
                    $group->get('/{id}', GetUserAction::class);
                    $group->post('', AddUserAction::class);
                    $group->put('/{id}', UpdateUserAction::class);
                    $group->put('', UpdateUserAction::class);
                    $group->delete('/{id}', DeleteUserAction::class);
                    $group->get('/{id}/2fa', GetUser2faAction::class);
                    $group->put('/{id}/2fa/connect', ConnectUser2faAction::class);
                    $group->put('/{id}/generate-auth-key', \WCAA\Api\Actions\User\GenerateUserTokenAction::class);
                });

                $group->get('/user-list', \WCAA\Api\Actions\User\GetAllUserListAction::class);
                $group->delete('/user-session-close/{id}', \WCAA\Api\Actions\User\CloseUserSessionAction::class);
                $group->get('/user-role-permissions', PermissionListAction::class);
                $group->group('/user-role', function (Group $group) {
                    $group->get('', GetAllUserRolesAction::class);
                    $group->get('/{id}', GetUserRoleUserAction::class);
                    $group->post('', AddUserRoleAction::class);
                    $group->put('/{id}', UpdateUserRoleUserAction::class);
                    $group->delete('/{id}', DeleteUserRoleUserAction::class);
                });
                $group->post('/device-detect', \WCAA\Api\Actions\Devices\DetectDeviceAction::class);
                $group->group('/poller', function (Group $group) {
                   $group->get('/latests/{device-id}', \WCAA\Api\Actions\Poller\GetLatestPollersByDeviceAction::class);
                   $group->put('/poll/{device-id}', \WCAA\Api\Actions\Poller\PollDeviceAction::class);
                   $group->put('/poll-background/{device-id}', \WCAA\Api\Actions\Poller\PollDeviceBackgroundAction::class);
                   $group->delete('/clear-history/{device-id}', \WCAA\Api\Actions\Poller\ClearHistoryAction::class);
                });
                $group->group('/device-access', function (Group $group) {
                    $group->get('', \WCAA\Api\Actions\Devices\Accesses\ListAccessAction::class);
                    $group->get('/{id}', \WCAA\Api\Actions\Devices\Accesses\ListAccessAction::class);
                    $group->post('', \WCAA\Api\Actions\Devices\Accesses\AddAccessAction::class);
                    $group->put('/{id}', \WCAA\Api\Actions\Devices\Accesses\EditAccessAction::class);
                    $group->delete('/{id}', \WCAA\Api\Actions\Devices\Accesses\DeleteAccessAction::class);
                });
                $group->get('/device-access-defaults', \WCAA\Api\Actions\Devices\Accesses\SwCoreConnParamsAction::class);
                $group->group('/device-group', function (Group $group) {
                    $group->get('', \WCAA\Api\Actions\Devices\Groups\ListGroupAction::class);
                    $group->get('/{id}', \WCAA\Api\Actions\Devices\Groups\ListGroupAction::class);
                    $group->post('', \WCAA\Api\Actions\Devices\Groups\AddGroupAction::class);
                    $group->put('/{id}', \WCAA\Api\Actions\Devices\Groups\EditGroupAction::class);
                    $group->delete('/{id}', \WCAA\Api\Actions\Devices\Groups\DeleteGroupAction::class);
                });
                $group->group('/device-model', function (Group $group) {
                    $group->get('', ListModelAction::class);
                    $group->get('/{id}', ListModelAction::class);
                    $group->post('', AddModelAction::class);
                    $group->put('/{id}', EditModelAction::class);
                    $group->delete('/{id}', DeleteModelAction::class);
                });
                $group->get('/device-icon/{id}', \WCAA\Api\Actions\Devices\Models\GetModelIconAction::class);
                $group->group('/device', function (Group $group) {
                    $group->get('/options', \WCAA\Api\Actions\Devices\DeviceListForOptionsAction::class);
                    $group->get('', ListDeviceAction::class);
                    $group->get('/{id}', ListDeviceAction::class);
                    $group->get('/{id}/compare-model', \WCAA\Api\Actions\Devices\CompareDeviceModelAction::class);
                    $group->post('', AddDeviceAction::class);
                    $group->put('/{id}', EditDeviceAction::class);
                    $group->delete('/{id}', DeleteDeviceAction::class);
                });
                $group->group('/device-interface', function (Group $group) {
                    $group->get('/history/log', \WCAA\Api\Actions\Devices\Interfaces\GetInterfaceHistoryAsLog::class);
                    $group->get('/history/{interface_id}', \WCAA\Api\Actions\Devices\Interfaces\GetInterfaceHistory::class);
                    // The same history, answered rather than listed.
                    $group->get('/drop-summary/{interface_id}', \WCAA\Api\Actions\Devices\Interfaces\GetDropSummary::class);
                    $group->get('/stat-by-types', \WCAA\Api\Actions\Devices\Interfaces\GetInterfacesStatByType::class);
                    $group->get('/types', \WCAA\Api\Actions\Devices\Interfaces\GetInterfacesTypes::class);
                    $group->put('/ont-list', \WCAA\Api\Actions\Devices\Interfaces\OntListAction::class);
                    $group->get('/by-bind-key/{device_id}/{bind_key}', \WCAA\Api\Actions\Devices\Interfaces\GetInterfaceByBindKey::class);
                    $group->get('/by-device/{device_id}', \WCAA\Api\Actions\Devices\Interfaces\GetInterfacesByDevice::class);
                    $group->get('/search', \WCAA\Api\Actions\Devices\Interfaces\SearchInterfacesByParameters::class);
                    $group->get('', \WCAA\Api\Actions\Devices\Interfaces\ListDeviceInterfaceAction::class);
                    $group->get('/{id}', \WCAA\Api\Actions\Devices\Interfaces\ListDeviceInterfaceAction::class);
                    $group->post('', \WCAA\Api\Actions\Devices\Interfaces\AddDeviceInterfaceAction::class);
                    $group->put('/{id}', \WCAA\Api\Actions\Devices\Interfaces\EditDeviceInterfaceAction::class);
                    $group->delete('/{id}', \WCAA\Api\Actions\Devices\Interfaces\DeleteDeviceInterfaceAction::class);
                });
                $group->group('/interface-marks', function (Group $group) {
                    $group->put('/favorite/list', \WCAA\Api\Actions\Devices\Interfaces\Marks\GetFavoriteInterfacesWithPaggingAction::class);
                    $group->put('/tagged/list', \WCAA\Api\Actions\Devices\Interfaces\Marks\GetTaggedInterfacesWithPaggingAction::class);
                    $group->map(['GET'],'/favorite[/{device_id}]', \WCAA\Api\Actions\Devices\Interfaces\Marks\GetFavoriteInterfaceAction::class);
                    $group->map(['GET'],'/tags[/{device_id}]', \WCAA\Api\Actions\Devices\Interfaces\Marks\GetTagsByInterfacesAction::class);
                    $group->get('/marks/{interface_id}', \WCAA\Api\Actions\Devices\Interfaces\Marks\GetInterfaceMarks::class);
                    $group->put('/tags/{interface_id}', \WCAA\Api\Actions\Devices\Interfaces\Marks\SetTagsForInterfaceAction::class);
                    $group->put('/favorite/{interface_id}', \WCAA\Api\Actions\Devices\Interfaces\Marks\SetStarredStatusAction::class);
                    $group->get('/existed-tags', \WCAA\Api\Actions\Devices\Interfaces\Marks\GetTagsList::class);
                });

                //System configuration block
                $group->group('/system', function (Group $group) {
                    $group->post('/cross-auth', \WCAA\Api\Actions\Auth\CrossAuthAction::class);
                    $group->get('/usage-stat', \WCAA\Api\Actions\System\GetSystemStatAction::class);
                    $group->get('/info', \WCAA\Api\Actions\System\Info::class);
                    $group->get('/configuration', \WCAA\Api\Actions\System\GetParametersAction::class);
                    $group->put('/configuration', \WCAA\Api\Actions\System\UpdateParametersAction::class);
                    $group->get('/settings', \WCAA\Api\Actions\System\SystemPropertiesAction::class);
                    $group->map(['GET', 'POST', 'DELETE'], '/monitoring-targets', \WCAA\Api\Actions\System\MonitoringTargetsAction::class);
                    $group->get('/schedule', \WCAA\Api\Actions\System\Schedule\GetScheduleListAction::class);
                    $group->put('/schedule/{id}', \WCAA\Api\Actions\System\Schedule\UpdateScheduleAction::class);
                    $group->post('/webhook/alertmanager', \WCAA\Api\Actions\System\AlertManagerWebHook::class);
                    $group->group('/component', function (Group $group) {
                       $group->get('', \WCAA\Api\Actions\System\Components\ComponentList::class);
                       $group->put('/{key}', \WCAA\Api\Actions\System\Components\ComponentControl::class);
                    });
                });

                //Logs block
                $group->group('/logs', function (Group $group) {
                    $group->post('/actions', \WCAA\Api\Actions\System\Actions\GetListAction::class);
                    $group->post('/action', \WCAA\Api\Actions\System\Actions\AddNewAction::class);
                    $group->get('/actions-list', \WCAA\Api\Actions\System\Actions\GetSupportedActions::class);
                    $group->group('/switcher-core', function (Group $group) {
                        $group->get('/supported-modules', \WCAA\Api\Actions\System\SwitcherCoreActions\GetSupportedModules::class);
                        $group->post('/actions', \WCAA\Api\Actions\System\SwitcherCoreActions\GetSwitcherCoreAction::class);
                    });
                    $group->group('/poller', function (Group $group) {
                        $group->get('/existed-pollers', \WCAA\Api\Actions\System\Pollers\GetSupportedPollers::class);
                        $group->post('/pollers', \WCAA\Api\Actions\System\Pollers\GetListAction::class);
                    });
                    $group->post('/schedule/reports', \WCAA\Api\Actions\System\Schedule\ScheduleReports\GetScheduleReportsAction::class);
                    $group->get('/schedule/keys', \WCAA\Api\Actions\System\Schedule\ScheduleReports\GetScheduleListAction::class);
                });
                $group->map(['GET', 'POST'], '/switcher-core/{storage}/{module}/{device_id}', \WCAA\Api\Actions\SwitcherCore\SwitcherCoreAction::class);
                $group->map(['GET'], '/switcher-core/{device_id}/modules', \WCAA\Api\Actions\SwitcherCore\GetModulesList::class);
            })->add(PermissionCheckMiddleware::class)->add(\WCAA\Api\Middleware\AuthStrictByIpMiddleware::class)->add(\WCAA\App::getInstance()->conf('api.auth.check_auth_middleware'));
        });
    });

    //System routes
    $app->options('/{routes:.+}', function (Request $request, Response $response) {
        return $response;
    });

    $app->map(['GET', 'POST', 'PUT', 'DELETE', 'PATCH'], '/{routes:.+}', function (Request $request, Response $response) {
        throw new HttpNotFoundException($request);
    });
};
