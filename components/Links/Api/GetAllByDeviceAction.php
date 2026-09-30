<?php

namespace WCC\Links\Api;

use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\Pinger\Storage\PingerDeviceStatusStorage;
/**
 * @OA\Get(
 *   path="/component/links/by-device/{device_id}",
 *   tags={"links"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get links by device",
 *   @OA\Parameter(name="device_id", in="path", required=true, @OA\Schema(type="integer", example=101)),
 *   @OA\Response(
 *     response=200,
 *     description="Links list",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/LinkLite"))
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */

class GetAllByDeviceAction extends BaseApiAction
{
    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @Inject
     * @var PingerDeviceStatusStorage
     */
    protected $pingerStatuses;

    protected function action(): Response
    {
        $device = $this->controller->getDeviceStorage()->getById($this->request->getAttribute('device_id'));
        $links = array_map(function ($e) {return $e->getAsArrayLite();}, $this->controller->getByDevice($device));

        // Up/down ring colour for the device icon at either end of a link
        // (frontend) — a handful of per-device lookups (this device's own
        // few links, not a table scan) rather than a live device query.
        $deviceIds = [];
        foreach ($links as $lnk) {
            if (isset($lnk['src_device']['id'])) $deviceIds[$lnk['src_device']['id']] = true;
            if (isset($lnk['dest_device']['id'])) $deviceIds[$lnk['dest_device']['id']] = true;
        }
        // model.type also doesn't survive the link's own nested lite
        // serialization (DeviceModel's `type` is marked `@prop.display=root`
        // — deliberately stripped from every NESTED model sub-object across
        // the whole app, not just this one, so fixing it there would touch
        // far more than this drawer) — added back explicitly here for the
        // frontend's device-type icon, same as status above.
        $statusById = [];
        $typeById = [];
        foreach (array_keys($deviceIds) as $id) {
            $d = $this->deviceStorage->getById($id);
            if (!$d) continue;
            $st = $this->pingerStatuses->getDeviceStatus($d);
            $statusById[$id] = $st ? ($st->getLatency() > 0 ? 'Up' : 'Down') : null;
            $typeById[$id] = $d->getModel() ? $d->getModel()->getType() : null;
        }
        foreach ($links as &$lnk) {
            if (isset($lnk['src_device']['id'])) {
                $lnk['src_device']['status'] = $statusById[$lnk['src_device']['id']] ?? null;
                $lnk['src_device']['model_type'] = $typeById[$lnk['src_device']['id']] ?? null;
            }
            if (isset($lnk['dest_device']['id'])) {
                $lnk['dest_device']['status'] = $statusById[$lnk['dest_device']['id']] ?? null;
                $lnk['dest_device']['model_type'] = $typeById[$lnk['dest_device']['id']] ?? null;
            }
        }
        unset($lnk);

        return $this->respondWithData($links);
    }
}
