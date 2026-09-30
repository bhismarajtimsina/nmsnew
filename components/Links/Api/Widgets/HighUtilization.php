<?php

namespace WCC\Links\Api\Widgets;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\Events\Storage\EventsStorage;
/**
 * @OA\Get(
 *   path="/component/links/widgets/high-utilization",
 *   tags={"links"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get count of links with high utilization events",
 *   @OA\Response(
 *     response=200,
 *     description="Widget payload",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(
 *         property="data",
 *         type="object",
 *         @OA\Property(property="count", type="integer", example=3)
 *       )
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */

class HighUtilization extends PrivateAction
{

    /**
     * @Inject
     * @var EventsStorage
     */
    protected $eventStorage;


    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    protected function action(): Response
    {
        $highUtilizated = [];
        foreach (array_merge(
                     $this->eventStorage->getNotResolvedBy('high_link_utilization'),
                 ) as $event) {

            try {
                $srcDevice = $this->deviceStorage->getById($event->getLabels()['src_device_id']);
                $destDevice = $this->deviceStorage->getById($event->getLabels()['dest_device_id']);
            } catch (\Exception $e) {
                continue;
            }
            if(!in_array($destDevice->getGroup()->getId(), $this->getDeviceGroupsIdsFromUser())) {
                continue;
            }
            if(!in_array($srcDevice->getGroup()->getId(), $this->getDeviceGroupsIdsFromUser())) {
                continue;
            }
            $highUtilizated[$event->getLabels()['link_id']] = $event->getCreatedAt();
        }
        return $this->respondWithData([
           'count' => count($highUtilizated),
        ]);
    }

}
