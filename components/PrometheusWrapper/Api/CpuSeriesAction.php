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
 *   path="/component/prometheus_wrapper/chart-cpu-load-series",
 *   tags={"prometheus-wrapper"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get CPU load chart series",
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       type="object",
 *       required={"device_id"},
 *       @OA\Property(property="device_id", type="integer", example=123),
 *       @OA\Property(property="start", type="string", example="2026-03-09 00:00:00"),
 *       @OA\Property(property="end", type="string", example="2026-03-09 09:00:00"),
 *       @OA\Property(property="step", type="string", example="10m")
 *     )
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Chart data",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="data", type="object", additionalProperties=true)
 *     )
 *   )
 * )
 */

class CpuSeriesAction extends PrivateAction
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
           'start' => null,
           'end' => null,
           'step' => null,
       ], $form);
       if(!$form['step']) $form['step'] = '10m';
       if(!$form['device_id']) throw new HttpBadRequestException($this->request, "device_id is required");
       return  $this->respondWithData($this->controller->convertToChartFormat($this->controller->cpuLoad($form['device_id'], $form['start'], $form['end'], $form['step'])));
    }


}
