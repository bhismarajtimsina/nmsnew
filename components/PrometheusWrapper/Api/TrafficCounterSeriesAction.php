<?php

namespace WCC\PrometheusWrapper\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCC\PrometheusWrapper\Controllers\Controller;
/**
 * @OA\Post(
 *   path="/component/prometheus_wrapper/chart-traffic-counter-series",
 *   tags={"prometheus-wrapper"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get interface traffic counters chart series",
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       type="object",
 *       required={"device_id","interface_id"},
 *       @OA\Property(
 *         property="device_id",
 *         type="integer",
 *         example=10,
 *         description="Device identifier"
 *       ),
 *       @OA\Property(
 *         property="interface_id",
 *         type="integer",
 *         example=812,
 *         description="Interface identifier"
 *       ),
 *       @OA\Property(
 *         property="start",
 *         type="string",
 *         example="",
 *         description="Start datetime in Y-m-d H:i:s format"
 *       ),
 *       @OA\Property(
 *         property="end",
 *         type="string",
 *         example="",
 *         description="End datetime in Y-m-d H:i:s format"
 *       ),
 *       @OA\Property(
 *         property="step",
 *         type="string",
 *         example="10m",
 *         description="Prometheus query step, default is 10m"
 *       )
 *     )
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Chart data",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="object", additionalProperties=true)
 *     )
 *   ),
 *   @OA\Response(
 *     response=400,
 *     description="Bad request",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=400),
 *       @OA\Property(property="meta", type="object", nullable=true),
 *       @OA\Property(
 *         property="error",
 *         type="object",
 *         @OA\Property(property="type", type="string", example="BAD_REQUEST"),
 *         @OA\Property(property="description", type="string", example="device_id is required")
 *       )
 *     )
 *   )
 * )
 */

class TrafficCounterSeriesAction extends PrivateAction
{
        /**
     * @Inject
     * @var Controller
     */
    protected $controller;

    protected function action(): Response
    {
       $form = $this->getFormData();
       $this->fillDefaultKeys([
           'device_id' => null,
           'interface_id' => null,
           'start' => null,
           'end' => null,
           'step' => null,
       ], $form);
       if(!$form['step']) $form['step'] = '10m';
       if(!$form['device_id']) throw new HttpBadRequestException($this->request, "device_id is required");
       if(!$form['interface_id']) throw new HttpBadRequestException($this->request, "interface_id is required");
       return  $this->respondWithData($this->controller->convertToChartFormat(
           $this->controller->trafficCounterSeries($form['device_id'], $form['interface_id'], $form['start'], $form['end'], $form['step']),
           [
               'iface_stat_out_multicast_pkts' => ['yAxisID' => 'y1', 'hidden' => true,],
               'iface_stat_in_multicast_pkts' => ['yAxisID' => 'y1', 'hidden' => true,],
               'iface_stat_out_broadcast_pkts' => ['yAxisID' => 'y1', 'hidden' => true,],
               'iface_stat_in_broadcast_pkts' => ['yAxisID' => 'y1', 'hidden' => true,],
           ]
       ));
    }


}
