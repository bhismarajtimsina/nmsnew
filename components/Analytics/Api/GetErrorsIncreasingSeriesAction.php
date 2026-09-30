<?php

namespace WCC\Analytics\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\Analytics\Controllers\Controller;
/**
 * @OA\Get(
 *   path="/component/analytics/errors/increasing-chart/by-interface/{interface_id}",
 *   tags={"analytics"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get increasing errors chart by interface",
 *   @OA\Parameter(name="interface_id", in="path", required=true, @OA\Schema(type="integer", example=2435)),
 *   @OA\Response(
 *     response=200,
 *     description="Chart data",
 *     @OA\JsonContent(type="object", @OA\Property(property="data", type="object", additionalProperties=true))
 *   )
 * )
 *
 * @OA\Post(
 *   path="/component/analytics/errors/increasing-chart/by-interface/{interface_id}",
 *   tags={"analytics"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get increasing errors chart by interface with body params",
 *   @OA\Parameter(name="interface_id", in="path", required=true, @OA\Schema(type="integer", example=2435)),
 *   @OA\RequestBody(required=false, @OA\JsonContent(type="object", additionalProperties=true)),
 *   @OA\Response(
 *     response=200,
 *     description="Chart data",
 *     @OA\JsonContent(type="object", @OA\Property(property="data", type="object", additionalProperties=true))
 *   )
 * )
 */

class GetErrorsIncreasingSeriesAction extends PrivateAction
{
        /**
     * @Inject
     * @var Controller
     */
    protected $controller;

    /**
     * @Inject
     * @var DeviceInterfaceStorage
     */
    protected $deviceInterfaceStorage;


    protected function action(): Response
    {
        $form = $this->getFormData();
        $this->fillDefaultKeys([
            'start' => null,
            'end' => null,
            'step' => null,
        ], $form);
        if (!$form['step']) $form['step'] = '10m';

        $data = $this->controller->errorsIncreasingSeriesByInterface($this->deviceInterfaceStorage->getById($this->request->getAttribute('interface_id')), $form['start'], $form['end'], $form['step']);
        return $this->respondWithData($this->controller->convertToChartFormat($data, [
            'in_errors' => [
                'pointRadius' => 0,
                'label' => 'Count interfaces with IN-errors',
                'stacked' => true,
                'backgroundColor' => '#b77f20',
                'borderColor' => '#b77f20',
                'tension' => false,
            ],
            'out_errors' => [
                'pointRadius' => 0,
                'label' => 'Count interfaces with OUT-errors',
                'stacked' => true,
                'backgroundColor' => '#2255a4',
                'borderColor' => '#2255a4',
                'tension' => false,
            ],
        ]));
    }
}
