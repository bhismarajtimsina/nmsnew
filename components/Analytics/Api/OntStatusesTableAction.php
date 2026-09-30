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
 * @OA\Get(
 *   path="/component/analytics/table/ont-statuses-history",
 *   tags={"analytics"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get ONT statuses history table",
 *   @OA\Response(
 *     response=200,
 *     description="Paginated table data",
 *     @OA\JsonContent(type="object", @OA\Property(property="data", type="array", @OA\Items(type="object", additionalProperties=true)), @OA\Property(property="meta", type="object", additionalProperties=true))
 *   )
 * )
 *
 * @OA\Put(
 *   path="/component/analytics/table/ont-statuses-history",
 *   tags={"analytics"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get ONT statuses history table with filters",
 *   @OA\RequestBody(required=false, @OA\JsonContent(type="object", additionalProperties=true)),
 *   @OA\Response(
 *     response=200,
 *     description="Paginated table data",
 *     @OA\JsonContent(type="object", @OA\Property(property="data", type="array", @OA\Items(type="object", additionalProperties=true)), @OA\Property(property="meta", type="object", additionalProperties=true))
 *   )
 * )
 */

class OntStatusesTableAction extends PrivateAction
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
        $form = $this->getFormData();
        $filter = $form['filter'] ?? [];
        $this->fillDefaultKeys([
            'devices' => [],
            'choosed_time' => null,
        ], $filter);
        if (isset($filter['devices']) && $filter['devices']) {
            $devArr = array_map(function ($d) {
                return $this->deviceStorage->getById($d['id']);
            }, $filter['devices']);
        } else {
            $devArr = $this->deviceStorage->fetchAll();
        }
        $devices = [];
        $allowedGroupIds = $this->getDeviceGroupsIdsFromUser();
        foreach ($devArr as $dev) {
            if(!in_array($dev->getGroup()->getId(), $allowedGroupIds)) continue;
            $devices[$dev->getId()] = $dev;
        }


        $statusData = [];
        foreach ($this->controller->ontStatusesByTime($filter['choosed_time']) as $values) {
            foreach ($values as $value) {
                $labels = $value['metric'];
                if(!isset($devices[$labels['dev_id']])) {
                    continue;
                }
                $statusData["{$labels['dev_id']}-{$labels['iface_id']}"]['device'] = [
                    'id' => $devices[$labels['dev_id']]->getId(),
                    'ip' => $devices[$labels['dev_id']]->getIp(),
                    'name' => $devices[$labels['dev_id']]->getName(),
                ];
                $statusData["{$labels['dev_id']}-{$labels['iface_id']}"]['interface'] = [
                    'id' => $labels['iface_id'],
                    'name' => $labels['iface_name'],
                    'type' => $labels['iface_type'],
                ];
                switch ($labels['__name__']) {
                    case 'device_interface_status':
                        $status = "UNKNOWN";
                        if($value['value'][1] == 1) {
                            $status = "Online";
                        } elseif ($value['value'][1] == 0) {
                            $status = "Offline";
                        } elseif ($value['value'][1] == -1) {
                            $status = "Offline";
                        } elseif ($value['value'][1] == -2) {
                            $status = "LOS";
                        }
                        $statusData["{$labels['dev_id']}-{$labels['iface_id']}"]['status'] = $status;
                        break;
                }
            }
        }
        $pagination = $this->paginationFromParams(array_values($statusData));
        return $this->respondWithData($pagination['data'], $pagination['meta']);
    }

}
