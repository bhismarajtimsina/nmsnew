<?php
/**
 * Cover for the transport-path state engine.
 *
 * The costly failure modes here are asymmetric: reporting a broken path as "up"
 * hides a real outage, and reporting a healthy group as "unprotected" trains
 * operators to ignore the alert. Both directions are asserted.
 *
 * Reachability comes from Pinger, where a negative latency means unreachable.
 */

use WCC\Links\Models\Link;
use WCC\Paths\Controllers\StateCalculator;
use WCC\Paths\Models\Path;
use WCC\Paths\Models\PathSegment;
use WCC\Paths\Models\PathState;

/**
 * Build a path whose segments chain the given device ids:
 * [1,2,3] produces hops 1->2 and 2->3.
 */
function makePath($id, $name, array $deviceChain, $groupKey = null, $priority = 100)
{
    $path = (new Path($id))->setName($name)->setGroupKey($groupKey)->setPriority($priority);
    $segments = [];
    for ($i = 0; $i < count($deviceChain) - 1; $i++) {
        $link = (new Link($id * 100 + $i))
            ->setSrcDeviceId($deviceChain[$i])
            ->setDestDeviceId($deviceChain[$i + 1]);
        $segments[] = (new PathSegment($id * 1000 + $i))
            ->setPosition($i)
            ->setLinkId($link->getId())
            ->setLink($link);
    }
    return $path->setSegments($segments);
}

function calculator(array $latencies)
{
    $calc = new StateCalculator();
    $calc->setLatencies($latencies);
    return $calc;
}

return [
    'all hops reachable => path up' => function () {
        $path = makePath(1, 'KTM -> Belbari -> Pathari', [1, 2, 3]);
        $result = calculator([1 => 5, 2 => 12, 3 => 20])->calculate($path);
        Assert::same(PathState::STATE_UP, $result['state'], 'healthy path is up');
        Assert::same(2, count($result['detail']['hops']), 'three devices produce two hops');
    },

    'one unreachable hop breaks the whole path' => function () {
        $path = makePath(1, 'KTM -> Belbari -> Pathari', [1, 2, 3]);
        //Belbari unreachable - the middle of the chain.
        $result = calculator([1 => 5, 2 => -1, 3 => 20])->calculate($path);
        Assert::same(PathState::STATE_DOWN, $result['state'], 'any down hop => path down');
    },

    'a down far endpoint is still a down path' => function () {
        $path = makePath(1, 'KTM -> Belbari -> Pathari', [1, 2, 3]);
        $result = calculator([1 => 5, 2 => 12, 3 => -1])->calculate($path);
        Assert::same(PathState::STATE_DOWN, $result['state'], 'destination down => path down');
    },

    'high latency degrades but does not break the path' => function () {
        $path = makePath(1, 'KTM -> Damak -> Pathari', [1, 4, 3]);
        $result = calculator([1 => 5, 4 => 900, 3 => 20])->calculate($path);
        Assert::same(PathState::STATE_DEGRADED, $result['state'], 'slow hop => degraded, still carrying traffic');
    },

    'down beats degraded when both are present' => function () {
        $path = makePath(1, 'KTM -> Damak -> Pathari', [1, 4, 3]);
        $result = calculator([1 => 900, 4 => -1, 3 => 20])->calculate($path);
        Assert::same(PathState::STATE_DOWN, $result['state'], 'a break outranks slowness');
    },

    'never claim up while a hop is unmeasured' => function () {
        $path = makePath(1, 'KTM -> Belbari -> Pathari', [1, 2, 3]);
        //Device 2 has never been polled - absent from the latency map.
        $result = calculator([1 => 5, 3 => 20])->calculate($path);
        Assert::same(PathState::STATE_UNKNOWN, $result['state'], 'missing data must not read as healthy');
    },

    'a known break outranks unmeasured hops' => function () {
        $path = makePath(1, 'KTM -> Belbari -> Pathari', [1, 2, 3]);
        $result = calculator([1 => -1])->calculate($path);
        Assert::same(PathState::STATE_DOWN, $result['state'], 'confirmed down still reports down');
    },

    'path with no segments is unknown, not up' => function () {
        $path = (new Path(1))->setName('unwired')->setSegments([]);
        $result = calculator([])->calculate($path);
        Assert::same(PathState::STATE_UNKNOWN, $result['state'], 'unwired path is unknown');
        Assert::same('no_segments', $result['detail']['reason'], 'reason is reported');
    },

    'a segment whose link was deleted is unknown, not up' => function () {
        $path = (new Path(1))->setName('broken ref')->setSegments([
            (new PathSegment(1))->setPosition(0)->setLinkId(999)->setLink(null),
        ]);
        $result = calculator([])->calculate($path);
        Assert::same(PathState::STATE_UNKNOWN, $result['state'], 'dangling link reference is unknown');
        Assert::same('link_missing', $result['detail']['hops'][0]['reason'], 'reason is reported');
    },

    'both paths up => group protected' => function () {
        $belbari = makePath(1, 'via Belbari', [1, 2, 3], 'ktm-pathari', 10);
        $damak = makePath(2, 'via Damak', [1, 4, 3], 'ktm-pathari', 20);
        $group = calculator([])->calculateGroup([$belbari, $damak], [
            1 => PathState::STATE_UP,
            2 => PathState::STATE_UP,
        ]);
        Assert::same('protected', $group['state'], 'full redundancy');
        Assert::true($group['protected'], 'protected flag set');
        Assert::same(2, $group['usable'], 'both usable');
    },

    'one path down => group unprotected, not an outage' => function () {
        $belbari = makePath(1, 'via Belbari', [1, 2, 3], 'ktm-pathari', 10);
        $damak = makePath(2, 'via Damak', [1, 4, 3], 'ktm-pathari', 20);
        $group = calculator([])->calculateGroup([$belbari, $damak], [
            1 => PathState::STATE_DOWN,
            2 => PathState::STATE_UP,
        ]);
        Assert::same('unprotected', $group['state'], 'service survives but redundancy is gone');
        Assert::false($group['protected'], 'protected flag cleared');
        Assert::same(1, $group['usable'], 'one path still carrying');
    },

    'a degraded path still counts as carrying traffic' => function () {
        $belbari = makePath(1, 'via Belbari', [1, 2, 3], 'ktm-pathari', 10);
        $damak = makePath(2, 'via Damak', [1, 4, 3], 'ktm-pathari', 20);
        $group = calculator([])->calculateGroup([$belbari, $damak], [
            1 => PathState::STATE_DEGRADED,
            2 => PathState::STATE_DEGRADED,
        ]);
        Assert::same('protected', $group['state'], 'degraded is usable, so redundancy holds');
        Assert::same(2, $group['usable'], 'both counted as usable');
    },

    'all paths down => outage' => function () {
        $belbari = makePath(1, 'via Belbari', [1, 2, 3], 'ktm-pathari', 10);
        $damak = makePath(2, 'via Damak', [1, 4, 3], 'ktm-pathari', 20);
        $group = calculator([])->calculateGroup([$belbari, $damak], [
            1 => PathState::STATE_DOWN,
            2 => PathState::STATE_DOWN,
        ]);
        Assert::same('outage', $group['state'], 'destination unreachable');
        Assert::same(0, $group['usable'], 'nothing usable');
    },

    'a lone path is never reported as unprotected' => function () {
        //Otherwise every standalone path would alert forever.
        $solo = makePath(1, 'single route', [1, 2], 'solo-group');
        $up = calculator([])->calculateGroup([$solo], [1 => PathState::STATE_UP]);
        Assert::same('up', $up['state'], 'lone healthy path is simply up');
        Assert::false($up['redundant'], 'not flagged as redundant');

        $down = calculator([])->calculateGroup([$solo], [1 => PathState::STATE_DOWN]);
        Assert::same('outage', $down['state'], 'lone failed path is an outage');
    },

    'an unmeasured path does not count as protection' => function () {
        $belbari = makePath(1, 'via Belbari', [1, 2, 3], 'ktm-pathari', 10);
        $damak = makePath(2, 'via Damak', [1, 4, 3], 'ktm-pathari', 20);
        $group = calculator([])->calculateGroup([$belbari, $damak], [
            1 => PathState::STATE_UP,
            2 => PathState::STATE_UNKNOWN,
        ]);
        Assert::same('unprotected', $group['state'], 'unknown is not proven redundancy');
        Assert::same(1, $group['usable'], 'only the proven path counts');
    },

    'numeric encoding matches the Alertmanager rules' => function () {
        //The shipped rules compare path_state == 0 and == 0.5.
        Assert::same(1, PathState::NUMERIC[PathState::STATE_UP], 'up = 1');
        Assert::same(0.5, PathState::NUMERIC[PathState::STATE_DEGRADED], 'degraded = 0.5');
        Assert::same(0, PathState::NUMERIC[PathState::STATE_DOWN], 'down = 0');
        Assert::same(-1, PathState::NUMERIC[PathState::STATE_UNKNOWN], 'unknown = -1');
    },
];
