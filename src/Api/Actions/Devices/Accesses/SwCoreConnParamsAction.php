<?php


namespace WCAA\Api\Actions\Devices\Accesses;


use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use WCAA\Api\Actions\Action;
use WCAA\Api\Actions\PrivateAction;
use WCAA\App;
use WCAA\Storage\Devices\DeviceAccessStorage;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * @OA\Get(
 *   path="/device-access-defaults",
 *   tags={"device-access"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get default connection params",
 *   description="Returns default switcher-core connection settings for access profiles.",
 *   @OA\Response(
 *     response=200,
 *     description="Default connection params",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="object", additionalProperties=true)
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class SwCoreConnParamsAction extends PrivateAction
{
    /**
     * @Inject
     * @var App
     */
    protected $app;

    /**
     * @return Response
     */
    protected function action(): Response
    {
        return  $this->respondWithData([
            'console_port' => $this->app->conf('switcher_core.console_port'),
            'console_timeout_sec' => $this->app->conf('switcher_core.console_timeout_sec'),
            'console_connection_type' => $this->app->conf('switcher_core.console_connection_type'),
            'snmp_timeout_sec' => $this->app->conf('switcher_core.snmp_timeout_sec'),
            'snmp_repeats' => $this->app->conf('switcher_core.snmp_repeats'),
            'mikrotik_api_port' => $this->app->conf('switcher_core.mikrotik_api_port'),
            'snmp_port' => $this->app->conf('switcher_core.snmp_port'),
            'snmp_version' => $this->app->conf('switcher_core.snmp_version'),
        ]);
    }
}
