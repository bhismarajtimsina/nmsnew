<?php

namespace WCC\Analytics\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\Analytics\Controllers\Controller;
/**
 * @OA\Post(
 *   path="/component/analytics/charts/increasing-errors",
 *   tags={"analytics"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get increasing errors chart",
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(type="object", additionalProperties=true)
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
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */

class GetErrorsAnalyticsSeriesAction extends PrivateAction
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


    protected function action(): Response
    {
        $form = $this->getFormData();
        $this->fillDefaultKeys([
            'start' => null,
            'end' => null,
            'step' => null,
            'device_groups' => [],
        ], $form);
        if (!$form['step']) $form['step'] = '10m';

        if ($form['device_groups']) {
            $filteredDeviceGroups = array_map(function ($deviceGroup) {
                return $deviceGroup['id'];
            }, $form['device_groups']);
        } else {
            $filteredDeviceGroups = [];
        }

        $deviceIds = [];
        $groups = $this->getDeviceGroupsIdsFromUser();
        foreach ($this->deviceStorage->fetchAll() as $dev) {
            if ($filteredDeviceGroups && !in_array($dev->getGroup()->getId(), $filteredDeviceGroups)) {
                continue;
            }
            if (in_array($dev->getGroup()->getId(), $groups)) {
                $deviceIds[] = $dev->getId();
            }
        }

        $data = $this->controller->errorsCountIncreasingSeries($deviceIds, $form['start'], $form['end'], $form['step']);
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
