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
 *   path="/component/analytics/table/signal-strength",
 *   tags={"analytics"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get ONT signal strength table",
 *   @OA\Response(
 *     response=200,
 *     description="Paginated table data",
 *     @OA\JsonContent(type="object", @OA\Property(property="data", type="array", @OA\Items(type="object", additionalProperties=true)), @OA\Property(property="meta", type="object", additionalProperties=true))
 *   )
 * )
 *
 * @OA\Put(
 *   path="/component/analytics/table/signal-strength",
 *   tags={"analytics"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get ONT signal strength table with filters",
 *   @OA\RequestBody(required=false, @OA\JsonContent(type="object", additionalProperties=true)),
 *   @OA\Response(
 *     response=200,
 *     description="Paginated table data",
 *     @OA\JsonContent(type="object", @OA\Property(property="data", type="array", @OA\Items(type="object", additionalProperties=true)), @OA\Property(property="meta", type="object", additionalProperties=true))
 *   )
 * )
 */

class OntSignalTableAction extends PrivateAction
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
            'level' => null,
            'type' => 'rx',
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
            $devices[] = $dev->getId();
        }
        $pagination = $this->paginationFromParams(
            $this->controller->getOntsWithSignalLevelStrength($devices, $filter['type'], $filter['level'])
        );
        return $this->respondWithData($pagination['data'], $pagination['meta']);
    }

}
