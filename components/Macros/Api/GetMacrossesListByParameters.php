<?php

namespace WCC\Macros\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\Macros\Models\Macros;
use WCC\Macros\Storage\MacrosStorage;
/**
 * @OA\Get(
 *   path="/component/macros/list",
 *   description="List macros available for device and interface parameters",
 *   tags={"macros"},
 *   security={{"XAuthKey":{}}},
 *   @OA\Parameter(
 *     name="device_id",
 *     in="query",
 *     required=true,
 *     description="Device ID from database",
 *     @OA\Schema(type="integer")
 *   ),
 *   @OA\Parameter(
 *     name="interface_id",
 *     in="query",
 *     required=false,
 *     @OA\Schema(type="integer")
 *   ),
 *   @OA\Parameter(
 *     name="interface_bind_key",
 *     in="query",
 *     required=false,
 *     @OA\Schema(type="string")
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="OK",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/MacrosListItem")),
 *       @OA\Property(property="meta", type="object", nullable=true, additionalProperties={})
 *     )
 *   )
 * )
 */

class GetMacrossesListByParameters extends PrivateAction
{

    /**
     * @Inject
     * @var MacrosStorage
     */
    protected $macrosStorage;

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @Inject
     * @var DeviceInterfaceStorage
     */
    protected $interfaceStorage;

        protected function action(): Response
    {

        $reqs = $this->request->getQueryParams();
        if (!isset($reqs['device_id']) || !$reqs['device_id']) {
            throw new HttpBadRequestException($this->request, "Parameter device_id is required");
        }


        $device = $this->deviceStorage->getById($reqs['device_id']);
        $displayFor = 'DEVICE';

        if (isset($reqs['interface_id'])) {
            $interface = $this->interfaceStorage->getById($reqs['interface_id']);
            if ($interface->getType() === 'ONU') {
                $displayFor = 'ONU';
            } elseif ($interface->getType() === 'PON') {
                $displayFor = 'PON';
            } else {
                $displayFor = 'PORT';
            }
        } elseif (isset($reqs['interface_bind_key'])) {
            $interface = $this->interfaceStorage->getByDeviceAndKey($device, $reqs['interface_bind_key']);
            if ($interface->getType() === 'ONU') {
                $displayFor = 'ONU';
            } elseif ($interface->getType() === 'PON') {
                $displayFor = 'PON';
            } else {
                $displayFor = 'PORT';
            }
        }
        $macrosesByParams = $this->macrosStorage->getByParameters($device->getModel(), $this->user->getRole(), $displayFor);

        // WAN-add macros are tagged 'WAN' instead of 'ONU' (a distinct
        // purpose, not just another ONU-scoped macro) but still only make
        // sense on an ONU interface — merge them in alongside plain
        // ONU-tagged macros so the frontend can tell the two apart via
        // each item's own display_for and show WAN ones as their own
        // "Add WAN" action instead of lumping them into the general list.
        if ($displayFor === 'ONU') {
            $wanMacros = $this->macrosStorage->getByParameters($device->getModel(), $this->user->getRole(), 'WAN');
            $knownIds = array_map(function ($m) { return $m->getId(); }, $macrosesByParams);
            foreach ($wanMacros as $m) {
                if (!in_array($m->getId(), $knownIds)) {
                    $macrosesByParams[] = $m;
                }
            }
        }

        return $this->respondWithData($this->macrosToArray($macrosesByParams));
    }

    /**
     * @param Macros[] $macroses
     */
    function macrosToArray($macroses)
    {
        return array_map(function ($macros) {
            return [
                'id' => $macros->getId(),
                'name' => $macros->getName(),
                'description' => $macros->getDescription(),
                'display_for' => $macros->getDisplayFor(),
            ];
        }, $macroses);
    }
}
