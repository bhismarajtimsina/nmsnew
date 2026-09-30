<?php

namespace WCC\Analytics\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
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
 *
 * @OA\Put(
 *   path="/component/analytics/table/duplicated-mac-addresses",
 *   tags={"analytics"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get duplicated MAC addresses table with filters",
 *   @OA\RequestBody(required=false, @OA\JsonContent(type="object", additionalProperties=true)),
 *   @OA\Response(
 *     response=200,
 *     description="Duplicates data",
 *     @OA\JsonContent(type="object", @OA\Property(property="data", type="array", @OA\Items(type="object", additionalProperties=true)))
 *   )
 * )
 */

class DuplicatedMacAdrressesTable extends PrivateAction
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

    /**
     * @Inject
     * @var DuplicatesStat
     */
    protected $duplicatesStat;

    protected function action(): Response
    {
        $filter = $this->getFormData();
        $this->fillDefaultKeys([
            'device_groups' => [],
            'mac_address' => [],
        ], $filter);

        $deviceGroupIds = $this->getDeviceGroupsIdsFromUser();
        $allowedGroups = [];
        if($filter['device_groups']) {
            foreach ($filter['device_groups'] as $devGroup) {
                if(in_array($devGroup['id'], $deviceGroupIds)) {
                    $allowedGroups[] = new DeviceGroup($devGroup['id']);
                }
            }
        } else {
            foreach ($deviceGroupIds as $groupId) {
                $allowedGroups[] = new DeviceGroup($groupId);
            }
        }

        $deviceIds = [];
        foreach ($allowedGroups as $group) {
            foreach ($this->deviceStorage->fetchByDeviceGroup($group, true) as $device) {
                $deviceIds[] = $device->getId();
            }
        }

        $duplicated = $this->duplicatesStat->getDuplicatedMacAddresses($deviceIds, $filter['mac_address']);
        return $this->respondWithData($duplicated);
    }

}
