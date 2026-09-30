<?php

namespace WCC\Analytics\Api;

use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Infrastructure\Paginator\DataPagination;
use WCAA\Infrastructure\Paginator\Paginator;
use WCAA\Models\Devices\Device;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\PollerData\OntIdentStorage;
use WCC\Events\Models\Event;
use WCC\Events\Storage\EventsStorage;
use WCC\PrometheusWrapper\Controllers\PrometheusMetricsTempStore;
use OpenApi\Annotations as OA;
/**
 * @OA\Get(
 *   path="/component/analytics/table/ont-list",
 *   tags={"analytics"},
 *   security={{"XAuthKey":{}}},
 *   @OA\Response(
 *     response=200,
 *     description="ONT list",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/OntAnalyticsListItem")),
 *       @OA\Property(property="meta", type="object", nullable=true, additionalProperties={})
 *     )
 *   )
 * )
 *
 * @OA\Put(
 *   path="/component/analytics/table/ont-list",
 *   tags={"analytics"},
 *   security={{"XAuthKey":{}}},
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="query", type="object", nullable=true, additionalProperties={}),
 *       @OA\Property(property="limit", type="integer", example=50),
 *       @OA\Property(property="page", type="integer", example=1),
 *       @OA\Property(property="ascending", type="integer", enum={0,1}, example=1),
 *       @OA\Property(property="byColumn", type="integer", enum={0,1}, example=1),
 *       @OA\Property(
 *         property="filter",
 *         type="object",
 *         @OA\Property(property="devices", type="array", @OA\Items(type="object", @OA\Property(property="id", example="10", type="integer"))),
 *         @OA\Property(property="field", type="string", example="any"),
 *         @OA\Property(property="expression", type="string", enum={"~","=","!=",">","<",">=","<="}, example="~"),
 *         @OA\Property(property="value", type="string", nullable=true, example=null),
 *         @OA\Property(property="ont_status", type="string", example="All"),
 *         @OA\Property(property="bad_signals", type="boolean", example=false)
 *       )
 *     )
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="OK",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/OntAnalyticsListItem")),
 *       @OA\Property(property="meta", type="object", nullable=true, additionalProperties={})
 *     )
 *   )
 * )
 */


class GetOntList extends PrivateAction
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


    /**
     * @Inject
     * @var EventsStorage
     */
    protected $eventStorage;

        protected function action(): Response
    {

        $badRxSignal = [];
        $badOltRxSignal = [];
        foreach (array_merge(
                     $this->eventStorage->getNotResolvedBy('bad_optical_level_rx'),
                     $this->eventStorage->getNotResolvedBy('bad_optical_level_olt_rx'),
                 ) as $event) {
            $key = "{$event->getLabels()['dev_id']}-{$event->getLabels()['iface_id']}";
            if(!isset($badRxSignal[$key]) && $event->getName() == 'bad_optical_level_rx') {
                $badRxSignal[$key] = $event;
            }
            if(!isset($badOltRxSignal[$key]) && $event->getName() == 'bad_optical_level_olt_rx') {
                $badOltRxSignal[$key] = $event;
            }
        }


        $data = [];
        $form = $this->getFormData();
        $filter = $form['filter'] ?? [];
        $this->fillDefaultKeys([
            'devices' => [],
            'field' => null,
            'expression' => '~',
            'value' => null,
            'ont_status' => 'All',
            'bad_signals' => false,
        ], $filter);
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

            $ontIdentList = [];
            $statuses = null;
            if(isset($filter['ont_status']) && $filter['ont_status'] !== 'All') {
                $statuses = explode("|", $filter['ont_status']);
            }

            if(isset($filter['bad_signals']) && $filter['bad_signals']) {
                /**
                 * @var $event Event
                 */
                // "Bad signal" means the ONU's OWN rx is bad — not the OLT's
                // rx of that ONU's upstream signal (bad_olt_rx is still
                // tracked/shown per-ONT, just no longer part of what counts
                // as a "bad signal" ONT here).
                foreach ($badRxSignal as $event) {
                    $key = "{$event->getLabels()['dev_id']}-{$event->getLabels()['iface_id']}";
                    try {
                        $interface = $this->deviceInterfaceStorage->getByDeviceAndKey(new Device($event->getLabels()['dev_id']), $event->getLabels()['iface_id']);
                        $ident = $this->ontIdentStorage->getByInterface($interface);
                        if($ident) {
                            $ontIdentList[$key] = $ident;
                        }
                    } catch (\Exception $e) {
                        $this->logger->error("Can't find interface with deviceId={$event->getLabels()['dev_id']}, and bindKey={$event->getLabels()['iface_id']} reason={$e->getMessage()}");
                    }
                }
            } else {
                $ontIdentList = $this->ontIdentStorage->fetchAll(false, null, $statuses);
            }

            foreach ($ontIdentList as $ontIdent) {
                $iface = $ontIdent->getInterface();
                if(!isset($devices[$iface->getDevice()->getId()])) {
                    continue;
                }
                $iface->setDevice($devices[$iface->getDevice()->getId()]);
                $ontIdent->setInterface($iface);
            }
        }


        $opticalData = $this->getOpticalData();
        $deviceGroupIds = $this->getDeviceGroupsIdsFromUser();
        foreach ($ontIdentList as $d) {
            if (!in_array($d->getInterface()->getDevice()->getGroup()->getId(), $deviceGroupIds)) {
                continue;
            }
            $key = "{$d->getInterface()->getDevice()->getId()}-{$d->getInterface()->getBindKey()}";
            $dt = [];
            $dt['optical'] = [
                'rx' => null,
                'tx' => null,
                'olt_rx' => null,
                'temperature' => null,
                'distance' => null,
                'bad_rx' => isset($badRxSignal[$key]) ? $badRxSignal[$key]->getCreatedAt() : null,
                'bad_olt_rx' => isset($badOltRxSignal[$key]) ? $badOltRxSignal[$key]->getCreatedAt() : null,
            ];
            if (isset($opticalData[$key])) {
                $optical = $opticalData[$key];
                $rxDelta = null;
                if(isset($optical['optical_rx']) && isset($optical['optical_olt_rx']) && $optical['optical_rx'] && $optical['optical_olt_rx']) {
                    $rxDelta = round(abs(abs($optical['optical_rx']) - abs($optical['optical_olt_rx'])), 2);
                }
                $dt['optical'] = [
                    'rx' => isset($optical['optical_rx']) ? round((float)$optical['optical_rx'], 2) : null,
                    'tx' => isset($optical['optical_tx']) ? round((float)$optical['optical_tx'], 2) : null,
                    'olt_rx' => isset($optical['optical_olt_rx']) ? (float)$optical['optical_olt_rx'] : null,
                    'temperature' => isset($optical['optical_temperature']) ? (float)$optical['optical_temperature'] : null,
                    'distance' => isset($optical['optical_distance']) ? (float)$optical['optical_distance'] : null,
                    'bad_rx' => isset($badRxSignal[$key]) ? $badRxSignal[$key]->getCreatedAt() : null,
                    'bad_olt_rx' => isset($badOltRxSignal[$key]) ? $badOltRxSignal[$key]->getCreatedAt() : null,
                    'rx_delta' => $rxDelta,
                ];
            }
            $dt['vendor'] = $d->getVendorInfo();
            $dt['ident'] = $d->getIdent();
            $dt['type'] = $d->getType();
            $dt['interface'] = [
                'created' => $d->getCreatedAt(),
                'id' => $d->getInterface()->getId(),
                'name' => $d->getInterface()->getName(),
                'status' => $d->getInterface()->getStatus(),
                'status_changed' => $d->getInterface()->getStatusChanged(),
                'bind_key' => $d->getInterface()->getBindKey(),
                'description' => $d->getInterface()->getDescription(),
                'params' => $d->getInterface()->getParams(),
                'agreement' => $d->getInterface()->getAgreement(),
            ];
            $dt['interface']['device'] = [
                'id' => $d->getInterface()->getDevice()->getId(),
                'name' => $d->getInterface()->getDevice()->getName(),
                'ip' => $d->getInterface()->getDevice()->getIp(),
            ];

            if (isset($filter['field']) && $filter['field'] && isset($filter['value']) && $filter['value']) {
                if (!$this->isAllowedByFilter($dt, $filter['field'], $filter['expression'], $filter['value'])) {
                    continue;
                }
            }
            if(isset($filter['bad_signals']) && $filter['bad_signals']) {
                // "Bad signal" = the ONU's own rx is bad — bad_olt_rx (the
                // OLT's rx of this ONU's upstream) is still tracked/shown
                // per-ONT, just no longer counted toward this filter.
                // (This also folds the old duplicated identical check below
                // into one — the second copy was a no-op, not a second rule.)
                if(!$dt['optical']['bad_rx']) {
                    continue;
                }
            }
            if(isset($filter['ont_status']) && $filter['ont_status'] !== 'All') {
                $statuses = explode("|", $filter['ont_status']);
                if(!in_array($dt['interface']['status'], $statuses)) {
                    continue;
                }
            }
            $data[] = $dt;
        }

        $pagination = $this->paginationFromParams($data);
        return $this->respondWithData($pagination['data'], $pagination['meta']);
    }

    protected function paginationFromParams($array) {
        $data = $this->getFormData();
        $this->replaceQueryParams($data, false);

        unset($data['query']['status']);
        $pagination = (new DataPagination(Paginator::initFromFilterArray($data)))
            ->setData($array);
        return [
            'data' => $pagination->getPaginator()->getData(),
            'meta' => $pagination->getPaginator()->getMeta(),
        ];
    }
    function getOpticalData()
    {
        $metrics = $this->promStorage->getLastByMetrics([
            'optical_rx',
            'optical_tx',
            'optical_olt_rx',
            'optical_temperature',
            'optical_distance',
        ]);
        $opticalData = [];
        foreach ($metrics as $metricName => $values) {
            foreach ($values as $value) {
                $opticalData["{$value['labels']['dev_id']}-{$value['labels']['iface_id']}"][$metricName] = $value['value'];
            }
        }
        return $opticalData;
    }

    function isAllowedByFilter($data, $filterName, $filterExpression, $filterValue)
    {
        if ($filterName === 'any') {
            $value = json_encode($data, JSON_UNESCAPED_UNICODE);
        } else {
            $value = getArrayElementByKey($data, $filterName);
        }
        $allowed = false;
        switch ($filterExpression) {
            case '~':
                $filterValue = preg_quote($filterValue, '/');
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
        return $allowed;
    }
}
