<?php

namespace WCC\Analytics\Api\Widgets;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCC\Events\Storage\EventsStorage;
/**
 * @OA\Get(
 *   path="/component/analytics/widgets/bad-signals",
 *   tags={"analytics"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get count of ONTs with bad optical signals",
 *   @OA\Response(
 *     response=200,
 *     description="Widget payload",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="object", @OA\Property(property="count", type="integer", example=7))
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */

class WithBadSignals extends PrivateAction
{
    
    /**
     * @Inject
     * @var EventsStorage
     */
    protected $eventStorage;

    /**
     * @Inject
     * @var DeviceInterfaceStorage
     */
    protected $deviceInterfaceStorage;

    protected function action(): Response
    {
        $onts = [];
        $ifaces = $this->deviceInterfaceStorage->getBindKeysByType('ONU');
        // "Bad signal" = the ONU's own rx is bad — bad_optical_level_olt_rx
        // (the OLT's rx of this ONU's upstream) is a separate alert type,
        // still fires and shows in the events log, just no longer counted
        // toward this widget.
        foreach ($this->eventStorage->getNotResolvedBy('bad_optical_level_rx') as $event) {
            $key = "{$event->getLabels()['dev_id']}-{$event->getLabels()['iface_id']}";
            if(!$event->getDevice()) {
                continue;
            }
            if(!in_array($key, $ifaces)) {
                continue;
            }
            if(!in_array($event->getDevice()->getGroup()->getId(), $this->getDeviceGroupsIdsFromUser())) {
                continue;
            }
            $onts[$key] = $event;
        }
        return $this->respondWithData([
           'count' => count($onts),
        ]);
    }


}
