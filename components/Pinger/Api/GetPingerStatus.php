<?php

namespace WCC\Pinger\Api;

use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpNotFoundException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Models\Devices\Device;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\Pinger\Controllers\Controller;
use WCC\Pinger\Storage\PingerDeviceStatusStorage;
/**
 * @OA\Get(
 *   path="/component/pinger/status/{device_id}",
 *   tags={"pinger"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get pinger status by device",
 *   @OA\Parameter(name="device_id", in="path", required=true, @OA\Schema(type="integer", example=101)),
 *   @OA\Response(
 *     response=200,
 *     description="Device pinger status",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="object", additionalProperties=true)
 *     )
 *   ),
 *   @OA\Response(response=404, description="Not Found")
 * )
 */

class GetPingerStatus extends PrivateAction
{
        /**
     * @Inject
     * @var Controller
     */
    protected $controller;

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @Inject
     * @var \WCC\PrometheusWrapper\Controllers\Controller
     */
    protected $prometheus;

    /**
     * @Inject
     * @var PingerDeviceStatusStorage
     */
    protected $pingerStatus;

    protected function action(): Response
    {
        $device = $this->deviceStorage->getById($this->request->getAttribute('device_id'));
        $data = [
            'availability' => null,
            'status' => null,
            'raw_status' => null,
            'is_tcp' => null,
            'duration' => null,
            'last_change' => null,
        ];
        $status = $this->pingerStatus->getDeviceStatus($device);
        $data['status'] = $status->getLatency() > 0 ? 'Up' : 'Down';
        $data['raw_status'] = $status->getLatency();
        $data['is_tcp'] = $status->getLatency() == 999;
        $data['duration'] = time() - \DateTime::createFromFormat("Y-m-d H:i:s", $status->getLastChange())->getTimestamp();
        $data['last_change'] = $status->getLastChange();

        try {
            $data['availability'] = [
                '24h' => $this->getUpPrcByTime($device, time() - (3600 * 24), '1m'),
                '7d' => $this->getUpPrcByTime($device, time() - (3600 * 24 * 7), '1m'),
                '30d' => $this->getUpPrcByTime($device, time() - (3600 * 24 * 30), '5m'),
            ];
        } catch (\Exception $e) {
        }
        return $this->respondWithData($data);
    }

    protected function getUpPrcByTime(Device $device, $time, $step = '1m')
    {
        $data = $this->prometheus->seriesRequest([sprintf('pinger_host_status{dev_id="%d"}', $device->getId())], $time, null, $step);
        if (!isset($data[0][0]['values'])) {
            throw new HttpNotFoundException($this->request, "Metrics data not found");
        }
        $up = 0;
        $down = 0;
        foreach ($data[0][0]['values'] as $val) {
            if ($val[1] > 0) {
                $up++;
            } else {
                $down++;
            }
        }
        //доступность = (Д — П) / Д × 100 %
        return round(($up - $down) / $up * 100, 2);
    }

}
