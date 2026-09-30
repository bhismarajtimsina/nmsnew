<?php

namespace WCC\Analytics\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Infrastructure\PrometheusMetrics;
use WCAA\Models\Devices\Device;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\PollerData\OntIdentStorage;
use WCC\Analytics\Controllers\Controller;
use WCC\PrometheusWrapper\Controllers\PrometheusMetricsTempStore;
/**
 * @OA\Put(
 *   path="/component/analytics/table/device-statuses",
 *   tags={"analytics"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get device statuses table with filters",
 *   description="Returns paginated device statuses. PUT uses JSON body.",
 *
 *   @OA\RequestBody(
 *     required=false,
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(
 *         property="filter",
 *         type="object",
 *         @OA\Property(
 *           property="choosed_time",
 *           type="integer",
 *           nullable=true,
 *           description="Unix timestamp or other integer time marker (backend expects int or null).",
 *           example=1700000000
 *         ),
 *         additionalProperties=true
 *       ),
 *       additionalProperties=true
 *     )
 *   ),
 *
 *   @OA\Response(
 *     response=200,
 *     description="Paginated table data",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="data", type="array", @OA\Items(type="object", additionalProperties=true)),
 *       @OA\Property(property="meta", type="object", additionalProperties=true)
 *     )
 *   ),
 *   @OA\Response(
 *     response=400,
 *     description="Bad request (e.g. malformed JSON)",
 *     @OA\JsonContent(type="object", additionalProperties=true)
 *   ),
 *   @OA\Response(
 *     response=401,
 *     description="Unauthorized",
 *     @OA\JsonContent(type="object", additionalProperties=true)
 *   ),
 *   @OA\Response(
 *     response=500,
 *     description="Internal server error",
 *     @OA\JsonContent(type="object", additionalProperties=true)
 *   )
 * )
 */
class DevicesStatusTableAction extends PrivateAction
{
    

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @Inject
     * @var Controller
     */
    protected $controller;

    protected function action(): Response
    {
        $filter = $this->getFormData()['filter'];
        $this->fillDefaultKeys([
            'choosed_time' => null,
        ], $filter);
        $statusData = [];
        foreach ($this->controller->deviceStatusesByTime($filter['choosed_time']) as $values) {
            foreach ($values as $value) {
                $labels = $value['metric'];
                switch ($labels['__name__']) {
                    case 'pinger_host_status':
                        $statusData[$labels['dev_id']]['status'] = $value['value'][1] > 0 ? "Up" : "Down";
                        break;
                    case 'device_uptime':
                        $statusData[$labels['dev_id']]['uptime_sec'] = $value['value'][1];
                        break;
                }
            }
        }
        $devices = [];
        $allowedGroupIds = $this->getDeviceGroupsIdsFromUser();
        foreach ($this->deviceStorage->fetchAll() as $dev) {
            if(!in_array($dev->getGroup()->getId(), $allowedGroupIds)) continue;
            $dv = $dev->getAsArray();
            $dv['status'] = isset($statusData[$dev->getId()]['status']) ? $statusData[$dev->getId()]['status'] : null;
            $dv['uptime_sec'] = isset($statusData[$dev->getId()]['uptime_sec']) ? $statusData[$dev->getId()]['uptime_sec'] : null;
            $devices[] = $dv;
        }


        $pagination = $this->paginationFromParams($devices);
        return $this->respondWithData($pagination['data'], $pagination['meta']);
    }

}
