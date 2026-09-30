<?php

namespace WCC\Links\Api;

use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Infrastructure\PrometheusMetrics;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\Links\Controllers\Controller;
use WCC\Links\Storage\LinkStorage;
use WCC\Pinger\Storage\PingerDeviceStatusStorage;
use WCC\PrometheusWrapper\Controllers\Controller as PrometheusController;
/**
 * @OA\Get(
 *   path="/component/links/topology-tree",
 *   tags={"links"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get topology graph",
 *   @OA\Parameter(name="period", in="query", required=false, @OA\Schema(type="string", example="15m")),
 *   @OA\Parameter(name="utilization", in="query", required=false, @OA\Schema(type="integer", example=85)),
 *   @OA\Parameter(name="hide_levels_above", in="query", required=false, @OA\Schema(type="integer", example=3)),
 *   @OA\Parameter(name="hide_without_links", in="query", required=false, @OA\Schema(type="string", example="true")),
 *   @OA\Parameter(name="show_external", in="query", required=false, description="Include unmanaged LLDP neighbors (e.g. another company's switch on a shared uplink) as read-only pseudo-nodes", @OA\Schema(type="string", example="true")),
 *   @OA\Response(
 *     response=200,
 *     description="Topology data",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", ref="#/components/schemas/LinksTopology")
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */

class Topology extends PrivateAction
{
    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;
    /**
     * @Inject
     * @var LinkStorage
     */
    protected $linkStorage;


    /**
     * @Inject
     * @var Controller
     */
    protected $controller;

    /**
     * @Inject
     * @var PingerDeviceStatusStorage
     */
    protected $pingerStatuses;

    /**
     * @Inject
     * @var PrometheusMetrics
     */
    protected $promStorage;


    /**
     * @Inject
     * @var PrometheusController
     */
    protected $promMetrics;


    protected $levelToSize = [
       0 => 32,
       1 => 22,
       2 => 16,
       3 => 12,
       4 => 10,
    ];

    protected function action(): Response
    {
        $devices = [];
        $period = "15m";
        $params = $this->request->getQueryParams();
        if(isset($params['period'])) {
            $period = $params['period'];
        }
        foreach ($this->deviceStorage->fetchAll() as $device) {
            $dev = $device->getAsArray();
            unset(
                $dev['access'], $dev['params'], $dev['mac'],
                $dev['serial'], $dev['pollers'], $dev['coordinates'],
                $dev['location'], $dev['enabled'], $dev['updated_at'],
                $dev['created_at']
            );
            $dev['model'] = [
                'id' => $device->getModel()->getId(),
                'name' => $device->getModel()->getName(),
                'type' => $device->getModel()->getType(),
            ];
            $dev['group'] = [
                'id' => $device->getGroup()->getId(),
                'name' => $device->getGroup()->getName(),
            ];
            $devices[$device->getId()] = $dev;

            $devices[$device->getId()]['pinger'] = [
                'status' => null,
                'latency' => null,
                'last_change' => null,
            ];
            $devices[$device->getId()]['design'] = [
                'size' => 8,
                'color' => 'darkgreen',
            ];
        }
        foreach ($this->pingerStatuses->getAllStatuses(false) as $status) {
            $devices[$status->getDeviceId()]['pinger'] = [
                'status' => $status->getLatency() > 0 ? 'Up' : 'Down',
                'latency' => $status->getLatency(),
                'last_change' => $status->getLastChange(),
            ];
            if($status->getLatency() <= 0) {
                $devices[$status->getDeviceId()]['design']['color'] = 'darkred';
            }
        }

        //Уберем устройства, к которым не разрешен доступ
        $devices = array_filter($devices, function ($item) {
            return isset($item['group']['id']) && in_array($item['group']['id'], $this->getDeviceGroupsIdsFromUser());
        });

        $existedDevices = [];
        $deviceLevels = $this->linkStorage->getLevelsWithDeviceIds();
        $nodeSizes = $this->linkStorage->getChildCountByDeviceId();
        foreach ($devices as $devId=>$device) {
            $level = -1;
            if(isset($deviceLevels[$devId])) {
                $level = $deviceLevels[$devId];
            }
            $devices[$devId]['level'] = $level;

            $size = 8;
            if(isset($nodeSizes[$devId])) {
                $size = 10 + ($nodeSizes[$devId] / 2 * 1.5);
                if ($size > 600) {
                    $size = 42;
                }  elseif ($size > 400) {
                    $size = 40;
                }  elseif ($size > 100) {
                    $size = 38;
                }  elseif ($size > 36) {
                    $size = 36;
                }
            }
            $devices[$devId]['design']['size'] = $size;
        }


        if(isset($params['hide_levels_above']) && $params['hide_levels_above'] > 0) {
            $devices = array_filter($devices, function ($item) use ($params) {
                return $item['level'] <= $params['hide_levels_above'];
            });
        }
        $fullLinks = [];
        foreach ($this->controller->getAllLinksData($period) as $lnk) {
            if(!isset($lnk['src_device']['id'])) continue;
            if(!isset($lnk['dest_device']['id'])) continue;
            if(!isset($devices[$lnk['src_device']['id']])) continue;
            if(!isset($devices[$lnk['dest_device']['id']])) continue;

            unset(
                $lnk['src_device']['model'], $lnk['src_device']['group'], $lnk['src_device']['enabled'],
                $lnk['dest_device']['model'], $lnk['dest_device']['group'], $lnk['dest_device']['enabled'],
            );

            if(isset($params['utilization']) && $params['utilization'] > 0) {
                if(!$lnk['utilization'] || $lnk['utilization'] <= $params['utilization']) {
                    continue;
                }
            }

            $existedDevices[$lnk['src_device']['id']] = true;
            $existedDevices[$lnk['dest_device']['id']] = true;
            $fullLinks[] = $lnk;
        }

        if(isset($params['utilization']) && $params['utilization'] > 0) {
            $params['hide_without_links'] = true;
        }
        if(isset($params['hide_without_links']) && $params['hide_without_links'] !== 'false') {
            foreach ($devices as $k=>$device) {
                if(!isset($existedDevices[$k])) {
                    unset($devices[$k]);
                }
            }
        }

        $extDevices = [];
        $extLinks = [];
        if (!isset($params['show_external']) || $params['show_external'] !== 'false') {
            $external = $this->controller->getExternalNeighbors($devices, $period);
            $extDevices = $external['devices'];
            $extLinks = $external['links'];
        }

        return $this->respondWithData([
            'devices' => array_merge(array_values($devices), $extDevices),
            'links' => array_merge($fullLinks, $extLinks),
        ]);
    }

}
