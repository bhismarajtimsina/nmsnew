<?php

namespace WCC\Analytics\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpForbiddenException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Infrastructure\PrometheusMetrics;
use WCAA\Models\Devices\Device;
use WCAA\Models\Devices\DeviceGroup;
use WCAA\Storage\Devices\DeviceGroupStorage;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\PollerData\OntIdentStorage;
use WCC\Analytics\Controllers\Controller;
use WCC\Analytics\Controllers\DuplicatesStat;
use WCC\PrometheusWrapper\Controllers\PrometheusMetricsTempStore;
/**
 * @OA\Get(
 *   path="/component/analytics/errors/increasing-data/by-device/{device_id}",
 *   tags={"analytics"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get increasing errors data by device",
 *   @OA\Parameter(name="device_id", in="path", required=true, @OA\Schema(type="integer", example=101)),
 *   @OA\Parameter(name="period", in="query", required=false, @OA\Schema(type="string", example="1d")),
 *   @OA\Parameter(name="views", in="query", required=false, @OA\Schema(type="string", example="in_errors,out_errors")),
 *   @OA\Response(
 *     response=200,
 *     description="Errors data",
 *     @OA\JsonContent(type="object", @OA\Property(property="data", type="array", @OA\Items(type="object", additionalProperties=true)))
 *   ),
 *   @OA\Response(response=401, description="Unauthorized"),
 *   @OA\Response(response=403, description="Forbidden")
 * )
 */

class GetErrorsIncreasingByDevice extends PrivateAction
{
    

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @Inject
     * @var DeviceGroupStorage
     */
    protected $deviceGroupStorage;

    /**
     * @Inject
     * @var DeviceInterfaceStorage
     */
    protected $deviceInterfaceStorage;

    /**
     * @Inject
     * @var Controller
     */
    protected $controller;


    protected function action(): Response
    {

        $data = $this->request->getQueryParams();
        $period = '1d';
        if(isset($data['period'])) {
            $period = $data['period'];
        }
        $views=null;
        if(isset($data['views'])) {
            $views = explode(",", $data['views']);
        }

        $device = $this->deviceStorage->getById($this->request->getAttribute('device_id'));
        if(!in_array($device->getGroup()->getId(), $this->getDeviceGroupsIdsFromUser())) {
            throw new HttpForbiddenException($this->request, "You don't have permissions to current device group");
        }
        $errors = $this->controller->getErrorsByDevices([$device],$period, $views);
        return $this->respondWithData($errors);
    }

}
