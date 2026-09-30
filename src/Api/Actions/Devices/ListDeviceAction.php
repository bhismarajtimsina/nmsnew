<?php


namespace WCAA\Api\Actions\Devices;


use Slim\Exception\HttpForbiddenException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Models\Pollers\PollerProcessing;
use WCAA\Storage\PollerData\PollerProcessingStorage;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceStorage;
use Psr\Http\Message\ResponseInterface as Response;
/**
 * @OA\Get(
 *   path="/device",
 *   tags={"device"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get device list or single device by ip",
 *   description="If query param 'ip' is provided - returns single device. Otherwise returns paginated list of devices.",
 *
 *   @OA\Parameter(
 *     name="ip",
 *     in="query",
 *     required=false,
 *     description="Device IP address to fetch a single device",
 *     @OA\Schema(type="string", example="185.253.216.1")
 *   ),
 *   @OA\Parameter(
 *     name="page",
 *     in="query",
 *     required=false,
 *     description="Page number for pagination",
 *     @OA\Schema(type="integer", example=1, minimum=1)
 *   ),
 *   @OA\Parameter(
 *     name="per_page",
 *     in="query",
 *     required=false,
 *     description="Items per page for pagination",
 *     @OA\Schema(type="integer", example=25, minimum=1, maximum=200)
 *   ),
 *
 *   @OA\Response(
 *     response=200,
 *     description="Successful operation. Returns either a single device object or a paginated list.",
 *     @OA\JsonContent(
 *       oneOf={
 *         @OA\Schema(
 *           type="object",
 *           description="Single device response",
 *           @OA\Property(property="statusCode", type="integer", example=200),
 *           @OA\Property(
 *             property="data",
 *             type="object",
 *             @OA\Property(property="id", type="integer", example=123),
 *             @OA\Property(property="ip", type="string", example="192.168.1.10"),
 *             @OA\Property(property="name", type="string", example="Core-Switch-01"),
 *
 *             @OA\Property(
 *               property="poller",
 *               type="object",
 *               description="Latest pollers info calculated in calculateLatestPoll()",
 *               @OA\Property(
 *                 property="data",
 *                 type="array",
 *                 @OA\Items(
 *                   type="object",
 *                   @OA\Property(property="poller", type="string", example="snmp"),
 *                   @OA\Property(property="start_at", type="string", example="2026-02-06 10:00:00", nullable=true),
 *                   @OA\Property(property="finished_at", type="string", example="2026-02-06 10:00:10", nullable=true),
 *                   @OA\Property(property="status", type="string", example="SUCCESS"),
 *                   @OA\Property(property="id", type="integer", example=555)
 *                 )
 *               ),
 *               @OA\Property(property="time", type="integer", example=1707213610),
 *               @OA\Property(property="failed_pсt", type="number", format="float", example=0)
 *             ),
 *
 *             @OA\Property(
 *               property="ifaces_stat",
 *               description="Interfaces statistic. Can be null if error occurs while collecting stats.",
 *               nullable=true,
 *               oneOf={
 *                 @OA\Schema(type="object"),
 *                 @OA\Schema(type="null")
 *               }
 *             )
 *           )
 *         ),
 *
 *         @OA\Schema(
 *           type="object",
 *           description="Paginated list response",
 *           @OA\Property(property="statusCode", type="integer", example=200),
 *           @OA\Property(
 *             property="data",
 *             type="array",
 *             @OA\Items(
 *               type="object",
 *               @OA\Property(property="id", type="integer", example=123),
 *               @OA\Property(property="ip", type="string", example="192.168.1.10"),
 *               @OA\Property(property="name", type="string", example="Core-Switch-01"),
 *               @OA\Property(property="_device_group_name", type="string", example="DC-Network")
 *             )
 *           ),
 *           @OA\Property(
 *             property="meta",
 *             type="object",
 *             description="Pagination metadata returned by paginationFromParams()",
 *             @OA\Property(property="page", type="integer", example=1),
 *             @OA\Property(property="per_page", type="integer", example=25),
 *             @OA\Property(property="total", type="integer", example=250)
 *           )
 *         )
 *       }
 *     )
 *   ),
 *
 *   @OA\Response(response=401, description="Unauthorized"),
 *   @OA\Response(response=403, description="Forbidden")
 * )
 */


class ListDeviceAction extends PrivateAction
{/**
     * @Inject
     * @var DeviceStorage
     */
    protected $storage;


    /**
     * @Inject
     * @var DeviceInterfaceStorage
     */
    protected $interfacesStorage;

    /**
     * @Inject
     * @var PollerProcessingStorage
     */
    protected $pollerProcessingStorage;

    /**
     * @return Response
     */
    protected function action(): Response
    {
        $device = null;
        if ($id = $this->request->getAttribute('id')) {
            $device = $this->storage->getById($id);
        }
        $query = $this->request->getQueryParams();
        if(isset($query['ip'])) {
            $device = $this->storage->getByIp($query['ip']);
        }
        $userDeviceGroups = $this->user->getDeviceGroups();

        if($device) {
            if(!array_filter($userDeviceGroups, function ($e) use ($device){
                return $device->getGroup()->getId() === $e->getId();
            }) && $this->user->getId() > 0) {
                throw new HttpForbiddenException($this->request, "Not allowed for update. You dont have permissions to choosed device group");
            }
            $dev = $device->getAsArray();
            $dev['poller'] = $this->calculateLatestPoll(
                $this->pollerProcessingStorage->getLatests($device)
            );
            $dev['ifaces_stat'] = null;
            try {
                $dev['ifaces_stat'] = $this->interfacesStorage->getDeviceStat($device);
            } catch (\Exception $e) {
                $this->logger->error("Error fill ifaces statistic: ". $e->getMessage());
            }
            return $this->respondWithData($dev);
        }

        $devices = [];
        foreach ($this->storage->fetchAll() as $device) {
            if(!array_filter($userDeviceGroups, function ($e) use ($device){
                    return $device->getGroup()->getId() === $e->getId();
                }) && ($this->user->getId() > 0  && $this->user->getRole()->getId() > 0)) {
                continue;
            }
            $data =  $device->getAsArray();
            $data['_device_group_name'] = $device->getGroup()->getName();
            $devices[] = $data;
        }
        $data = $this->paginationFromParams($devices);
        return $this->respondWithData($data['data'], $data['meta']);
    }


    /**
     * @param PollerProcessing[] $latests
     */
    function calculateLatestPoll(array $latests)
    {
        $countFailed = 0;
        $finished = 0;
        $pollers = [];
        $failedPrc = 0;
        foreach ($latests as $latest) {
            if ($latest->getStatus() === 'FAILED') {
                $countFailed++;
            }
            $pollers[] = [
                'poller' => $latest->getPoller(),
                'start_at' => $latest->getStartAt(),
                'finished_at' => $latest->getStopAt(),
                'status' => $latest->getStatus(),
                'id' => $latest->getId()
            ];
            if ($latest->getStopAt() && \DateTime::createFromFormat("Y-m-d H:i:s", $latest->getStopAt())->getTimestamp() > $finished) {
                $finished = \DateTime::createFromFormat("Y-m-d H:i:s", $latest->getStopAt())->getTimestamp();
            }
        }
        if ($countFailed > 0) {
            $failedPrc = round(($countFailed / count($latests)) * 100, 2);
        }
        return [
            'data' => $pollers,
            'time' => $finished,
            'failed_pсt' => $failedPrc,
        ];
    }
}
