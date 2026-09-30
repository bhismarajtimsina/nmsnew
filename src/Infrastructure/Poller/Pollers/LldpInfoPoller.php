<?php

namespace WCAA\Infrastructure\Poller\Pollers;

use WCAA\Infrastructure\Poller\PollerAbstract;
use WCAA\Infrastructure\Poller\PollerInterface;
use WCAA\Models\Devices\Device;

/**
 * Deliberately minimal — LLDP neighbor data isn't stored anywhere of its
 * own (no dedicated DB table like FDB history has); the only reason this
 * poller exists at all is to make the underlying live call on a schedule
 * so its result lands in the normal switcher-core cache/store for
 * fromStore()/fromCache() readers elsewhere (LldpNeighborsAction, the
 * auto-topology discovery job) to pick up later without querying the
 * device themselves.
 */
class LldpInfoPoller extends PollerAbstract implements PollerInterface
{
    function setManual(Device $device, $data = [])
    {
        $this->notifyPolledNow($device, 'lldp_info');
    }

    function poll(Device $device, $controller)
    {
        // Calling this is the entire point — the return value itself
        // isn't used for anything here, just the side effect of a live
        // poll landing in cache/store.
        $controller->getLldpInfo();
    }
}
