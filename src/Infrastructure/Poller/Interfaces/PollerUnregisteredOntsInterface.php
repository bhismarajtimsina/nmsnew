<?php

namespace WCAA\Infrastructure\Poller\Interfaces;

/**
 * Triggers a poll of an OLT's "unregistered ONTs" auto-find table so the
 * result lands in switcher-core's normal cache/store, the same place
 * `fromStore()`/`fromCache()` read from elsewhere. Without a poller
 * actually calling this on a schedule, the only way that data ever got
 * populated was a live device query hidden inside `fromCache()`'s
 * cache-miss fallback — meaning simply OPENING the "Unregistered ONTs" tab
 * fired a real SNMP query at the OLT every single time, unscheduled and
 * unbounded. Confirmed live this session.
 */
interface PollerUnregisteredOntsInterface
{
    /**
     * @return array|null
     */
    function getUnregisteredOnts();
}
