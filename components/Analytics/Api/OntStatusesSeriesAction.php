<?php

namespace WCC\Analytics\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\Analytics\Controllers\Controller;
/**
 * @OA\Post(
 *   path="/component/analytics/charts/ont-statuses",
 *   tags={"analytics"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get ONT statuses time series",
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

class OntStatusesSeriesAction extends PrivateAction
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
            'device' => null,
            'start' => null,
            'end' => null,
            'step' => null,
        ], $form);
        if (!$form['step']) $form['step'] = '10m';

        $deviceIds = [];
        if (isset($form['filter']['devices']) && $form['filter']['devices']) {
            foreach ($form['filter']['devices'] as $dv) {
                $deviceIds[] = $dv['id'];
            }
        } else {
            $groups = $this->getDeviceGroupsIdsFromUser();
            foreach ($this->deviceStorage->fetchAll() as $dev) {
                if (in_array($dev->getGroup()->getId(), $groups)) {
                    $deviceIds[] = $dev->getId();
                }
            }
        }

        $data = $this->controller->ontStatusesSeries($deviceIds, $form['start'], $form['end'], $form['step']);


        return $this->respondWithData($this->controller->convertToChartFormat($data, [
            'online' => ['pointRadius' => 0],
            'offline' => ['pointRadius' => 0],
            'los' => ['pointRadius' => 0],
        ]));
    }
}
