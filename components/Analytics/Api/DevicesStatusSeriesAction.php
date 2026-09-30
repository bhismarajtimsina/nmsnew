<?php

namespace WCC\Analytics\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Models\Devices\Device;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\Analytics\Controllers\Controller;
/**
 * @OA\Post(
 *   path="/component/analytics/charts/device-statuses",
 *   tags={"analytics"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get device statuses time series",
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

class DevicesStatusSeriesAction extends PrivateAction
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
       ], $form);
       if(!$form['step']) $form['step'] = '10m';

       $deviceGroupIds = $this->getDeviceGroupsIdsFromUser();
       $deviceIds =[];
       foreach ($this->deviceStorage->fetchAll() as $dev) {
           if(in_array($dev->getGroup()->getId(), $deviceGroupIds)) {
               $deviceIds[] = $dev->getId();
           }
       }

       return  $this->respondWithData($this->controller->convertToChartFormat($this->controller->devicesStatusSeries($deviceIds, $form['start'], $form['end'], $form['step']), [
           'all' => ['hidden' => true],
       ]));
    }


}
