<?php

namespace WCAA\Infrastructure\Poller\Pollers;

use WCAA\Infrastructure\Poller\PollerAbstract;
use WCAA\Infrastructure\Poller\PollerInterface;
use WCAA\Models\Devices\Device;

/**
 * Deliberately minimal, same shape as LldpInfoPoller — there's no
 * dedicated DB table for unregistered-ONT history, the only reason this
 * poller exists is to make the underlying live call on a schedule so its
 * result lands in switcher-core's own cache/store for the "Unregistered
 * ONTs" tab (from=store) to read later without querying the device itself.
 */
class UnregisteredOntsPoller extends PollerAbstract implements PollerInterface
{
    function setManual(Device $device, $data = [])
    {
        $this->notifyPolledNow($device, 'unregistered_onts');
    }

    function poll(Device $device, $controller)
    {
        $controller->getUnregisteredOnts();
    }
}
