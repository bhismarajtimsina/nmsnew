<?php

namespace WCC\Pinger\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCC\Pinger\Controllers\Controller;
/**
 * @OA\Get(
 *   path="/component/pinger/device-status-stat",
 *   tags={"pinger"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get pinger status chart data",
 *   @OA\Response(
 *     response=200,
 *     description="Chart payload",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="object", additionalProperties=true)
 *     )
 *   )
 * )
 */

class GetHostsStatusChart extends PrivateAction
{
        /**
     * @Inject
     * @var Controller
     */
    protected $controller;

    protected function action(): Response
    {
        $stat = $this->controller->getPingerStatusStat();
        $stat = array_filter($stat, function ($s) {
           return $s->getDevice()->isEnabled() && in_array($s->getDevice()->getGroup(), $this->user->getDeviceGroups());
        });
        return  $this->respondWithData([
            'labels' =>  ['up', 'down'],
            'datasets' => [
              [
                  'label' => 'Device ICMP status',
                  'data' => [
                      count(array_filter($stat, function ($e) {
                          return $e->getLatency() > 0;
                      })),
                      count(array_filter($stat, function ($e) {
                          return $e->getLatency() <= 0;
                      })),
                  ],
                  'backgroundColor' => [
                    "rgba(5, 100, 0, 0.9)",
                    "rgba(130, 0, 0, 0.9)",
                  ],
              ]
            ],
        ]);
    }

}
