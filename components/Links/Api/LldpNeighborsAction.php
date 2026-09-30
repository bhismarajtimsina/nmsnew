<?php

namespace WCC\Links\Api;

use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Models\Devices\Device;
use WCAA\Models\SystemAction;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\SystemActionsStorage;
use WCAA\SwitcherCore\SwitcherCore;
use WCC\Pinger\Storage\PingerDeviceStatusStorage;

/**
 * Real LLDP neighbor table for a device, cross-referenced against this
 * system's own device inventory by chassis MAC — lets an admin see which
 * physical-layer neighbors are already-known devices (and which links, if
 * any, are missing from the Links table entirely), independent of the
 * existing FDB/MAC-learning-based auto-topology. Only meaningful for
 * models whose vendor module actually supports `lldp_info` (confirmed via
 * this session's audit: BDCOM does, this system's Huawei OLT model does
 * not) — `supported: false` is a normal, expected response for those, not
 * an error.
 *
 * @OA\Get(
 *   path="/component/links/lldp-neighbors/{device_id}",
 *   tags={"links"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get LLDP-reported neighbors for a device, matched against known devices by MAC",
 *   @OA\Parameter(name="device_id", in="path", required=true, @OA\Schema(type="integer", example=101)),
 *   @OA\Response(
 *     response=200,
 *     description="LLDP neighbor table",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="object", additionalProperties=true)
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized"),
 *   @OA\Response(response=404, description="Not Found")
 * )
 */
class LldpNeighborsAction extends PrivateAction
{
    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @Inject
     * @var SwitcherCore
     */
    protected $switcherCore;

    /**
     * @Inject
     * @var SystemActionsStorage
     */
    protected $systemActionsStorage;

    /**
     * @Inject
     * @var PingerDeviceStatusStorage
     */
    protected $pingerStatuses;

    /**
     * Normalizes a MAC-like string (strips separators, uppercases) so LLDP's
     * own formatting (colons/dashes/case can vary by vendor module) can be
     * compared against however this system stores `devices.mac`.
     */
    private function normalizeMac(?string $mac): ?string
    {
        if (!$mac) return null;
        $clean = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $mac));
        return strlen($clean) === 12 ? $clean : null;
    }

    protected function action(): Response
    {
        $dev = new Device();
        try {
            $dev = $this->deviceStorage->getById($this->request->getAttribute('device_id'));
            $core = $this->switcherCore->getCore($dev);

            if (!$core->isModuleExist('lldp_info')) {
                return $this->respondWithData([
                    'supported' => false,
                    'local_chassis_id' => null,
                    'neighbors' => [],
                ]);
            }

            $lldp = $core->action('lldp_info');

            // Build a normalized-MAC -> device lookup once, from every
            // device this system already knows about (small enough table
            // on this system to hold in memory for one request; matches
            // the same tradeoff DeviceStorage::getUniqDeviceMacAddresses()
            // already makes elsewhere for a similar cross-reference).
            $allDevices = $this->deviceStorage->fetchAll();
            $byMac = [];
            foreach ($allDevices as $d) {
                $norm = $this->normalizeMac($d->getMac());
                if ($norm) $byMac[$norm] = $d;
            }

            $neighbors = [];
            foreach (($lldp['remotes'] ?? []) as $remote) {
                $normRemMac = $this->normalizeMac($remote['rem_chassis_id'] ?? null);
                $matched = $normRemMac && isset($byMac[$normRemMac]) ? $byMac[$normRemMac] : null;
                $matchedStatus = null;
                if ($matched) {
                    $st = $this->pingerStatuses->getDeviceStatus($matched);
                    $matchedStatus = $st ? ($st->getLatency() > 0 ? 'Up' : 'Down') : null;
                }
                $neighbors[] = [
                    'loc_interface' => $remote['loc_interface'] ?? null,
                    'rem_chassis_id' => $remote['rem_chassis_id'] ?? null,
                    'rem_interface' => $remote['rem_interface'] ?? null,
                    'matched_device' => $matched ? [
                        'id' => $matched->getId(),
                        'name' => $matched->getName(),
                        'ip' => $matched->getIp(),
                        'model_type' => $matched->getModel() ? $matched->getModel()->getType() : null,
                        'status' => $matchedStatus,
                    ] : null,
                ];
            }

            $this->systemActionsStorage->add(SystemAction::init(
                $this->user,
                'links:lldp_neighbors',
                SystemAction::STATUS_SUCCESS,
                "Requested LLDP neighbors on device {$dev->getName()} ({$dev->getIp()})",
                ['device' => $dev->getAsArray()]
            ));

            return $this->respondWithData([
                'supported' => true,
                'local_chassis_id' => $lldp['local']['chassis_id'] ?? null,
                'neighbors' => $neighbors,
            ]);
        } catch (\Exception $e) {
            $this->systemActionsStorage->add(SystemAction::init(
                $this->user,
                'links:lldp_neighbors',
                SystemAction::STATUS_FAILED,
                "Requested LLDP neighbors on device {$dev->getName()} ({$dev->getIp()})",
                ['device' => $dev->getAsArray(), 'error' => [
                    'message' => $e->getMessage(),
                    'code' => $e->getCode(),
                    'line' => $e->getLine(),
                    'file' => $e->getFile(),
                    'trace' => $e->getTraceAsString(),
                ]]
            ));
            throw $e;
        }
    }
}
