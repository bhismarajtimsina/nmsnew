<?php


namespace WCAA\Api\Actions\Devices\Interfaces;


use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Infrastructure\Poller\PollerProcessor;
use WCAA\Storage\Devices\DeviceInterfaceHistoryStorage;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceStorage;

/**
 * @OA\Get(
 *   path="/device-interface/history/log",
 *   tags={"device-interface"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get interface history as log by device",
 *   @OA\Parameter(
 *     name="device_id",
 *     in="query",
 *     required=true,
 *     description="Device ID",
 *     @OA\Schema(type="integer", example=101)
 *   ),
 *   @OA\Parameter(
 *     name="from",
 *     in="query",
 *     required=false,
 *     description="If equals device, poller updates data before response",
 *     @OA\Schema(type="string", example="device")
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="History log",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(type="object", additionalProperties=true))
 *     )
 *   ),
 *   @OA\Response(response=400, description="Bad Request"),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class GetInterfaceHistoryAsLog extends PrivateAction
{
    /**
     * @Inject
     * @var DeviceInterfaceStorage
     */
    protected $storage;

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @Inject
     * @var DeviceInterfaceHistoryStorage
     */
    protected $deviceHistoryStorage;

    /**
     * @Inject
     * @var PollerProcessor
     */
    protected $poller;

    /**
     * @return Response
     */
    protected function action(): Response
    {
        $params = $this->request->getQueryParams();
        if(!isset($params['device_id'])) {
            throw new HttpBadRequestException($this->request, "Device ID is required");
        }
        $device = $this->deviceStorage->getById($params['device_id']);

        if(isset($params['from']) && $params['from'] === 'device') {
            $this->poller
                ->setDevice($device)
                ->setUser($this->user)
                ->poll([
                    'interfaces_list',
                    'interfaces_status',
                ], true);
        }

        return $this->respondWithData($this->deviceHistoryStorage->getHistoryByDeviceAsLog($device));
    }
}
