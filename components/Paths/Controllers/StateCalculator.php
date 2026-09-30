<?php

namespace WCC\Paths\Controllers;

use Monolog\Logger;
use WCC\Paths\Models\Path;
use WCC\Paths\Models\PathState;
use WCC\Pinger\Storage\PingerDeviceStatusStorage;

/**
 * Derives path and group state from data the system already collects.
 *
 * Reachability comes from the Pinger component, where a negative latency means
 * the device did not answer. Nothing here probes devices directly - the point is
 * to interpret existing signals, not add another polling source.
 */
class StateCalculator
{
    /**
     * @Inject
     * @var PingerDeviceStatusStorage
     */
    protected $pingerStatus;

    /**
     * @Inject
     * @var Logger
     */
    protected $logger;

    /**
     * device_id => latency (ms, negative when unreachable)
     *
     * @var array|null
     */
    private $latencyCache = null;

    /**
     * Load every ping status once per run rather than per hop.
     */
    public function primeCache()
    {
        $latencies = [];
        foreach ($this->pingerStatus->getAllStatuses(false) as $status) {
            $latencies[(int)$status->getDeviceId()] = (int)$status->getLatency();
        }
        return $this->setLatencies($latencies);
    }

    /**
     * Seed reachability directly. Used by primeCache() and by tests, which need
     * to drive state transitions without a database.
     *
     * @param array $latencies device_id => latency ms (negative = unreachable)
     */
    public function setLatencies(array $latencies)
    {
        $this->latencyCache = $latencies;
        return $this;
    }

    /**
     * @param int $deviceId
     * @return int|null null when the device has never been polled
     */
    private function latencyFor($deviceId)
    {
        if ($this->latencyCache === null) {
            $this->primeCache();
        }
        return isset($this->latencyCache[$deviceId]) ? $this->latencyCache[$deviceId] : null;
    }

    /**
     * Compute the state of a single path plus a per-hop breakdown.
     *
     * @param Path $path
     * @return array{state: string, detail: array}
     */
    public function calculate(Path $path)
    {
        $segments = $path->getSegments();
        if (!$segments) {
            return [
                'state' => PathState::STATE_UNKNOWN,
                'detail' => ['reason' => 'no_segments', 'hops' => []],
            ];
        }

        $degradedThreshold = (int)_env('PATHS_DEGRADED_LATENCY_MS', 150);
        $hops = [];
        $anyDown = false;
        $anyUnknown = false;
        $anySlow = false;

        foreach ($segments as $segment) {
            $link = $segment->getLink();
            if ($link === null) {
                $anyUnknown = true;
                $hops[] = [
                    'position' => $segment->getPosition(),
                    'link_id' => $segment->getLinkId(),
                    'state' => PathState::STATE_UNKNOWN,
                    'reason' => 'link_missing',
                ];
                continue;
            }

            $endpoints = [];
            foreach ([$link->getSrcDeviceId(), $link->getDestDeviceId()] as $deviceId) {
                $deviceId = (int)$deviceId;
                $latency = $this->latencyFor($deviceId);
                $endpoints[] = [
                    'device_id' => $deviceId,
                    'latency' => $latency,
                    'reachable' => $latency === null ? null : ($latency >= 0),
                ];
            }

            $segmentDown = false;
            $segmentUnknown = false;
            $segmentSlow = false;
            foreach ($endpoints as $endpoint) {
                if ($endpoint['reachable'] === null) {
                    $segmentUnknown = true;
                } elseif ($endpoint['reachable'] === false) {
                    $segmentDown = true;
                } elseif ($degradedThreshold > 0 && $endpoint['latency'] > $degradedThreshold) {
                    $segmentSlow = true;
                }
            }

            if ($segmentDown) {
                $segmentState = PathState::STATE_DOWN;
                $anyDown = true;
            } elseif ($segmentUnknown) {
                $segmentState = PathState::STATE_UNKNOWN;
                $anyUnknown = true;
            } elseif ($segmentSlow) {
                $segmentState = PathState::STATE_DEGRADED;
                $anySlow = true;
            } else {
                $segmentState = PathState::STATE_UP;
            }

            $hops[] = [
                'position' => $segment->getPosition(),
                'link_id' => $segment->getLinkId(),
                'state' => $segmentState,
                'endpoints' => $endpoints,
            ];
        }

        //A single broken hop breaks the path, regardless of the rest.
        if ($anyDown) {
            $state = PathState::STATE_DOWN;
        } elseif ($anyUnknown) {
            //Never claim a path is up while part of it is unmeasured.
            $state = PathState::STATE_UNKNOWN;
        } elseif ($anySlow) {
            $state = PathState::STATE_DEGRADED;
        } else {
            $state = PathState::STATE_UP;
        }

        return [
            'state' => $state,
            'detail' => [
                'hops' => $hops,
                'degraded_threshold_ms' => $degradedThreshold,
                'calculated_at' => date("Y-m-d H:i:s"),
            ],
        ];
    }

    /**
     * Roll per-path states up to a redundancy group.
     *
     * A group of one has no redundancy to lose, so it is never reported as
     * unprotected - that would alert on every standalone path forever.
     *
     * @param array<string, string> $statesByPathId path_id => state
     * @param Path[] $paths members of one group
     * @return array
     */
    public function calculateGroup(array $paths, array $statesByPathId)
    {
        $total = count($paths);
        $usable = 0;
        $members = [];

        foreach ($paths as $path) {
            $state = isset($statesByPathId[$path->getId()])
                ? $statesByPathId[$path->getId()]
                : PathState::STATE_UNKNOWN;
            $isUsable = in_array($state, [PathState::STATE_UP, PathState::STATE_DEGRADED], true);
            if ($isUsable) {
                $usable++;
            }
            $members[] = [
                'path_id' => $path->getId(),
                'name' => $path->getName(),
                'priority' => $path->getPriority(),
                'state' => $state,
                'usable' => $isUsable,
            ];
        }

        $redundant = $total > 1;
        if ($usable === 0) {
            $groupState = 'outage';
        } elseif ($redundant && $usable < $total) {
            $groupState = 'unprotected';
        } elseif ($redundant) {
            $groupState = 'protected';
        } else {
            $groupState = 'up';
        }

        return [
            'state' => $groupState,
            'total' => $total,
            'usable' => $usable,
            'redundant' => $redundant,
            'protected' => $redundant && $usable === $total,
            'members' => $members,
        ];
    }
}
