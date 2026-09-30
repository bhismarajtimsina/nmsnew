<?php

namespace WCC\Links\Api;

use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Infrastructure\PrometheusMetrics;
use WCAA\Models\Devices\Device;
use WCAA\Models\Devices\DeviceInterface;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\Links\Models\Link;
use WCC\Links\Storage\LinkStorage;
use WCC\Pinger\Storage\PingerDeviceStatusStorage;
use WCC\PrometheusWrapper\Controllers\Controller;
/**
 * @OA\Get(
 *   path="/component/links/upward/{device_id}",
 *   tags={"links"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get upward topology by device",
 *   @OA\Parameter(name="device_id", in="path", required=true, @OA\Schema(type="integer", example=101)),
 *   @OA\Response(
 *     response=200,
 *     description="Uplink tree",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/UpwardTopologyItem"))
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized"),
 *   @OA\Response(response=404, description="Not Found")
 * )
 */

class GetUpwardTopologyByDevice extends BaseApiAction
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
     * @var PingerDeviceStatusStorage
     */
    protected $pingerStorage;

    protected function action(): Response
    {
        $device = $this->deviceStorage->getById($this->request->getAttribute('device_id'));
        $tree = $this->linkStorage->getUplinkTree($device);

        $data = [];
        foreach ($tree as $link) {
            $lnk = $this->controller->getAllDataByLink($this->linkStorage->getById($link['id']));
            $dts = [
                'device' => $link['device']->getAsArrayLite(),
                'uplink_interface' => $link['uplink_interface'] ? $link['uplink_interface']->getAsArrayLite() : null,
                'downlink_interface' => $link['downlink_interface'] ? $link['downlink_interface']->getAsArrayLite() : null,
                'depth' => $link['depth'],
                'link' => $lnk,
            ];
            $dts['device']['is_up'] = $this->getDeviceStatus($device);

            $data[] = $dts;
        }

        return $this->respondWithData($data);
    }


    protected function getDeviceStatus(Device $device)
    {
        $status = $this->pingerStorage->getDeviceStatus($device);
        return $status->getLatency() > 0;
    }
}
