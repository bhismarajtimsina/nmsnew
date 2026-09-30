<?php

namespace WCC\Paths\Controllers;

use WCAA\Infrastructure\Components\AbstractComponentController;
use WCAA\Infrastructure\Events\EventObserverStorage;
use WCC\Paths\Models\Path;
use WCC\Paths\Models\PathState;
use WCC\Paths\Storage\PathSegmentStorage;
use WCC\Paths\Storage\PathStateStorage;
use WCC\Paths\Storage\PathStorage;

class Controller extends AbstractComponentController
{
    /**
     * @Inject
     * @var PathStorage
     */
    protected $pathStorage;

    /**
     * @Inject
     * @var PathSegmentStorage
     */
    protected $segmentStorage;

    /**
     * @Inject
     * @var PathStateStorage
     */
    protected $stateStorage;

    /**
     * @Inject
     * @var StateCalculator
     */
    protected $calculator;

    /**
     * @Inject
     * @var EventObserverStorage
     */
    protected $observer;

    /**
     * @return Path[]
     */
    public function getAll($onlyEnabled = false)
    {
        return $this->pathStorage->fetchAll($onlyEnabled);
    }

    /**
     * @return Path|null
     */
    public function getById($id)
    {
        return $this->pathStorage->getById($id);
    }

    public function add(Path $path)
    {
        return $this->pathStorage->add($path);
    }

    public function update(Path $path)
    {
        return $this->pathStorage->update($path);
    }

    public function delete(Path $path)
    {
        return $this->pathStorage->delete($path);
    }

    public function setSegments($pathId, array $linkIds)
    {
        return $this->segmentStorage->replaceForPath($pathId, $linkIds);
    }

    /**
     * Recompute every enabled path, persist the result, and emit an event for
     * each transition so the SPA can repaint without polling.
     *
     * @return array{paths: array, groups: array}
     */
    public function recalculateAll()
    {
        $this->calculator->primeCache();

        $grouped = $this->pathStorage->fetchGroupedByKey();
        $statesByPathId = [];
        $pathResults = [];

        foreach ($grouped as $paths) {
            foreach ($paths as $path) {
                $result = $this->calculator->calculate($path);
                $changed = $this->stateStorage->store($path->getId(), $result['state'], $result['detail']);
                $statesByPathId[$path->getId()] = $result['state'];

                $previous = $path->getState();
                $pathResults[] = [
                    'path' => $path,
                    'state' => $result['state'],
                    'previous' => $previous ? $previous->getState() : null,
                    'changed' => $changed,
                    'detail' => $result['detail'],
                ];

                if ($changed) {
                    $this->emitPathChange($path, $result['state'], $previous ? $previous->getState() : null);
                }
            }
        }

        $groupResults = [];
        foreach ($grouped as $groupKey => $paths) {
            $group = $this->calculator->calculateGroup($paths, $statesByPathId);
            $group['group_key'] = $this->isSyntheticKey($groupKey) ? null : $groupKey;
            $groupResults[$groupKey] = $group;
        }

        return [
            'paths' => $pathResults,
            'groups' => $groupResults,
        ];
    }

    /**
     * Current view for the map: paths with resolved hop coordinates and state.
     *
     * @return array
     */
    public function getMapView()
    {
        $grouped = $this->pathStorage->fetchGroupedByKey();
        $statesByPathId = [];
        foreach ($grouped as $paths) {
            foreach ($paths as $path) {
                $state = $path->getState();
                $statesByPathId[$path->getId()] = $state ? $state->getState() : PathState::STATE_UNKNOWN;
            }
        }

        $response = [];
        foreach ($grouped as $groupKey => $paths) {
            $group = $this->calculator->calculateGroup($paths, $statesByPathId);
            $renderedPaths = [];
            foreach ($paths as $path) {
                $renderedPaths[] = $this->renderPath($path);
            }
            $response[] = [
                'group_key' => $this->isSyntheticKey($groupKey) ? null : $groupKey,
                'state' => $group['state'],
                'protected' => $group['protected'],
                'redundant' => $group['redundant'],
                'usable' => $group['usable'],
                'total' => $group['total'],
                'paths' => $renderedPaths,
            ];
        }
        return $response;
    }

    /**
     * Group-level summary without the geometry, for dashboards and widgets.
     *
     * @return array
     */
    public function getGroupStates()
    {
        $grouped = $this->pathStorage->fetchGroupedByKey();
        $statesByPathId = [];
        foreach ($grouped as $paths) {
            foreach ($paths as $path) {
                $state = $path->getState();
                $statesByPathId[$path->getId()] = $state ? $state->getState() : PathState::STATE_UNKNOWN;
            }
        }

        $response = [];
        foreach ($grouped as $groupKey => $paths) {
            $group = $this->calculator->calculateGroup($paths, $statesByPathId);
            $group['group_key'] = $this->isSyntheticKey($groupKey) ? null : $groupKey;
            $response[] = $group;
        }
        return $response;
    }

    /**
     * Turn a path into an ordered list of geo points the map can draw as a
     * polyline. Hops without coordinates are still returned so the UI can flag
     * them rather than silently drawing a wrong line.
     *
     * @param Path $path
     * @return array
     */
    private function renderPath(Path $path)
    {
        $state = $path->getState();
        $detail = $state ? $state->getDetail() : null;
        $hopStates = [];
        if (isset($detail['hops'])) {
            foreach ($detail['hops'] as $hop) {
                $hopStates[(int)$hop['link_id']] = $hop['state'];
            }
        }

        $points = [];
        $seenDevices = [];
        foreach ($path->getSegments() as $segment) {
            $link = $segment->getLink();
            if ($link === null) {
                continue;
            }
            foreach ([$link->getSrcDevice(), $link->getDestDevice()] as $device) {
                if ($device === null) {
                    continue;
                }
                //A shared device between consecutive segments is one map point.
                if (isset($seenDevices[$device->getId()])) {
                    continue;
                }
                $seenDevices[$device->getId()] = true;
                $coordinates = $device->getCoordinates();
                $points[] = [
                    'device_id' => $device->getId(),
                    'name' => $device->getName(),
                    'ip' => $device->getIp(),
                    'lat' => isset($coordinates['lat']) ? (float)$coordinates['lat'] : null,
                    'lon' => isset($coordinates['lon']) ? (float)$coordinates['lon'] : null,
                    'has_coordinates' => isset($coordinates['lat'], $coordinates['lon']),
                ];
            }
        }

        return [
            'id' => $path->getId(),
            'name' => $path->getName(),
            'priority' => $path->getPriority(),
            'enabled' => $path->isEnabled(),
            'state' => $state ? $state->getState() : PathState::STATE_UNKNOWN,
            'last_change' => $state ? $state->getLastChange() : null,
            'endpoint_a' => $path->getEndpointA() ? $path->getEndpointA()->getName() : null,
            'endpoint_b' => $path->getEndpointB() ? $path->getEndpointB()->getName() : null,
            'points' => $points,
            'segments' => array_map(function ($segment) use ($hopStates) {
                $link = $segment->getLink();
                return [
                    'position' => $segment->getPosition(),
                    'link_id' => $segment->getLinkId(),
                    'state' => isset($hopStates[$segment->getLinkId()])
                        ? $hopStates[$segment->getLinkId()]
                        : PathState::STATE_UNKNOWN,
                    'src_device_id' => $link ? $link->getSrcDeviceId() : null,
                    'dest_device_id' => $link ? $link->getDestDeviceId() : null,
                ];
            }, $path->getSegments()),
        ];
    }

    /**
     * Paths without a group get a synthetic key so they never appear to protect
     * each other; it must not leak into API responses.
     */
    private function isSyntheticKey($groupKey)
    {
        return strpos((string)$groupKey, '__single_') === 0;
    }

    private function emitPathChange(Path $path, $newState, $previousState)
    {
        try {
            $this->observer->notify("path:state-changed", [
                'path_id' => $path->getId(),
                'name' => $path->getName(),
                'group_key' => $path->getGroupKey(),
                'state' => $newState,
                'previous_state' => $previousState,
                'endpoint_a' => $path->getEndpointA() ? $path->getEndpointA()->getName() : null,
                'endpoint_b' => $path->getEndpointB() ? $path->getEndpointB()->getName() : null,
                'changed_at' => date("Y-m-d H:i:s"),
            ]);
        } catch (\Throwable $e) {
            //Losing a UI notification must not fail the calculation run.
            $this->logger->error("Failed to emit path state change - {$e->getMessage()}");
        }
    }
}
