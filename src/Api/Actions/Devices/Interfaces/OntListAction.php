<?php

namespace WCAA\Api\Actions\Devices\Interfaces;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Infrastructure\Paginator\DataPagination;
use WCAA\Infrastructure\Paginator\Paginator;
use WCAA\Models\Devices\Device;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\PollerData\OntIdentStorage;
use WCC\PrometheusWrapper\Controllers\PrometheusMetricsTempStore;

/**
 * @OA\Put(
 *   path="/device-interface/ont-list",
 *   tags={"device-interface"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get ONT list with filters and pagination",
 *   description="Returns ONT dataset enriched with optical metrics, filtered by request filter and user device groups.",
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       type="object",
 *       required={"filter"},
 *       @OA\Property(property="filter", type="object", additionalProperties=true),
 *       @OA\Property(property="query", type="object", additionalProperties=true)
 *     )
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Paginated ONT list",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(type="object", additionalProperties=true)),
 *       @OA\Property(property="meta", type="object", additionalProperties=true)
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class OntListAction extends PrivateAction
{

    /**
     * @Inject
     * @var PrometheusMetricsTempStore
     */
    protected $promStorage;

    /**
     * @Inject
     * @var OntIdentStorage
     */
    protected $ontIdentStorage;

    /**
     * @Inject
     * @var DeviceInterfaceStorage
     */
    protected $deviceInterfaceStorage;


    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    protected function action(): Response
    {
        $metrics = $this->promStorage->getLastByMetrics([
            'optical_rx',
            'optical_olt_rx',
            'optical_tx',
            'optical_temperature',
            'optical_distance',
        ]);
        $opticalData = [];
        foreach ($metrics as $metricName => $values) {
            foreach ($values as $value) {
                $opticalData["{$value['labels']['dev_id']}-{$value['labels']['iface_id']}"][$metricName] = $value['value'];
            }
        }
        $data = [];
        $filter = $this->getFormData()['filter'];
        if (isset($filter['devices']) && $filter['devices']) {
            $devices = array_map(function ($d) {
                return $this->deviceStorage->fill(new Device($d['id']));
            }, $filter['devices']);
            $ontIdentList = $this->ontIdentStorage->getByDevices($devices);
        } else {
            $devList = $this->deviceStorage->fetchByModelType('OLT');
            $devices = [];
            foreach ($devList as $l) {
                $devices[$l->getId()] = $l;
            }
            $ontIdentList = $this->ontIdentStorage->fetchAll(false);
            foreach ($ontIdentList as $ontIdent) {
                $iface = $ontIdent->getInterface();
                if(!isset($devices[$iface->getDevice()->getId()])) {
                    continue;
                }
                $iface->setDevice($devices[$iface->getDevice()->getId()]);
                $ontIdent->setInterface($iface);
            }
        }

        $deviceGroupIds = $this->getDeviceGroupsIdsFromUser();
        foreach ($ontIdentList as $d) {

            if(!in_array($d->getInterface()->getDevice()->getGroup()->getId(), $deviceGroupIds)) {
                continue;
            }

            $dt = [];
            if (isset($opticalData["{$d->getInterface()->getDevice()->getId()}-{$d->getInterface()->getBindKey()}"])) {
                $optical = $opticalData["{$d->getInterface()->getDevice()->getId()}-{$d->getInterface()->getBindKey()}"];
                $dt['optical'] = [
                    'rx' => isset($optical['optical_rx']) ? round((float)$optical['optical_rx'], 2) : null,
                    'olt_rx' => isset($optical['optical_olt_rx']) ? (float)$optical['optical_olt_rx'] : null,
                    'tx' => isset($optical['optical_tx']) ? (float)$optical['optical_tx'] : null,
                    'temperature' => isset($optical['optical_temperature']) ? (float)$optical['optical_temperature'] : null,
                    'distance' => isset($optical['optical_distance']) ? (float)$optical['optical_distance'] : null,
                ];
            } else {
                $dt['optical'] = [
                    'rx' => null,
                    'olt_rx' => null,
                    'tx' => null,
                    'temperature' => null,
                    'distance' => null,
                ];
            }
            $dt['created_at'] = $d->getCreatedAt();
            $dt['type'] = $d->getType();
            $dt['vendor'] = $d->getVendorInfo();
            $dt['ident'] = $d->getIdent();
            $dt['interface'] = [
              'id' => $d->getInterface()->getId(),
              'type' => $d->getInterface()->getType(),
              'name' => $d->getInterface()->getName(),
              'status' => $d->getInterface()->getStatus(),
              'status_changed' => $d->getInterface()->getStatusChanged(),
              'bind_key' => $d->getInterface()->getBindKey(),
              'description' => $d->getInterface()->getDescription(),
              'coordinates' => $d->getInterface()->getCoordinates(),
            ];
            $dt['interface']['device'] = [
                'id' => $d->getInterface()->getDevice()->getId(),
                'name' => $d->getInterface()->getDevice()->getName(),
                'ip' => $d->getInterface()->getDevice()->getIp(),
                'model' => [
                    'id' => $d->getInterface()->getDevice()->getModel()->getId(),
                    'key' =>  $d->getInterface()->getDevice()->getModel()->getKey(),
                    'name' =>  $d->getInterface()->getDevice()->getModel()->getName(),
                ],
                'access' => [
                    'id' => $d->getInterface()->getDevice()->getAccess()->getId(),
                    'name' => $d->getInterface()->getDevice()->getAccess()->getName(),
                ],
            ];
            if (isset($filter['field']) && $filter['field']) {
                if($filter['field'] === 'any') {
                    $value = json_encode($dt, JSON_UNESCAPED_UNICODE);
                } else {
                    $value = getArrayElementByKey($dt, $filter['field']);
                }
                $filterValue = $filter['value'];
                $allowed = false;
                switch ($filter['expression']) {
                    case '~':
                        if (preg_match("/{$filterValue}/", $value)) {
                            $allowed = true;
                        }
                        break;
                    case '=':
                        if ($value == $filterValue) {
                            $allowed = true;
                        }
                        break;
                    case '!=':
                        if ($value != $filterValue) {
                            $allowed = true;
                        }
                        break;
                    case '>':
                        if ($value > $filterValue) {
                            $allowed = true;
                        }
                        break;
                    case '<':
                        if ($value < $filterValue) {
                            $allowed = true;
                        }
                        break;
                    case '>=':
                        if ($value >= $filterValue) {
                            $allowed = true;
                        }
                        break;
                    case '<=':
                        if ($value <= $filterValue) {
                            $allowed = true;
                        }
                        break;
                }
                if($allowed) {
                    $data[] = $dt;
                }
            } else {
                $data[] = $dt;
            }
        }
        $pagination = $this->paginationFromParams($data);
        return $this->respondWithData($pagination['data'], $pagination['meta']);
    }

    protected function paginationFromParams($array) {
        $data = $this->getFormData();
        unset($data['query']['status']);
        $pagination = (new DataPagination(Paginator::initFromFilterArray($data)))
            ->setData($array);
        return [
            'data' => $pagination->getPaginator()->getData(),
            'meta' => $pagination->getPaginator()->getMeta(),
        ];
    }

}
