<?php

namespace WCC\Analytics\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Infrastructure\Paginator\DataPagination;
use WCAA\Infrastructure\Paginator\Paginator;
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
 *   path="/component/analytics/table/increasing-errors",
 *   tags={"analytics"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get increasing errors table",
 *   @OA\Response(
 *     response=200,
 *     description="Paginated table data",
 *     @OA\JsonContent(type="object", @OA\Property(property="data", type="array", @OA\Items(type="object", additionalProperties=true)), @OA\Property(property="meta", type="object", additionalProperties=true))
 *   )
 * )
 *
 * @OA\Put(
 *   path="/component/analytics/table/increasing-errors",
 *   tags={"analytics"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get increasing errors table with filters",
 *   @OA\RequestBody(required=false, @OA\JsonContent(type="object", additionalProperties=true)),
 *   @OA\Response(
 *     response=200,
 *     description="Paginated table data",
 *     @OA\JsonContent(type="object", @OA\Property(property="data", type="array", @OA\Items(type="object", additionalProperties=true)), @OA\Property(property="meta", type="object", additionalProperties=true))
 *   )
 * )
 */

class GetErrorsIncreasingAnalyticsTable extends PrivateAction
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
     * @var Controller
     */
    protected $controller;


    protected function action(): Response
    {
        $filter = $this->getFormData();
        $this->fillDefaultKeys([
            'device_groups' => null,
            'step' => '30m',
            'choosed_time' => null,
            'views' => [],
        ], $filter);
        if ($filter['device_groups']) {
            $filteredDeviceGroups = array_map(function ($deviceGroup) {
                return $deviceGroup['id'];
            }, $filter['device_groups']);
        } else {
            $filteredDeviceGroups = [];
        }
        $groups = $this->getDeviceGroupsIdsFromUser();
        $devices = [];
        foreach ($this->deviceStorage->fetchAll() as $dev) {
            if ($filteredDeviceGroups && !in_array($dev->getGroup()->getId(), $filteredDeviceGroups)) {
                continue;
            }
            if (in_array($dev->getGroup()->getId(), $groups)) {
                $devices[] = $dev;
            }
        }

        $errors = $this->controller->getErrorsByDevices($devices, $filter['step'], $filter['views'], $filter['choosed_time']);
        $data = $this->paginationFromParams($errors);
        return $this->respondWithData($data['data'], $data['meta']);
    }

}
