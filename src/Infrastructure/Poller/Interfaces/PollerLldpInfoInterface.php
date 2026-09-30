<?php

namespace WCAA\Infrastructure\Poller\Interfaces;

/**
 * Triggers a live LLDP poll for a device whose model declares the
 * `lldp_info` module, so its result gets written into the normal
 * switcher-core response cache/store — the same cache
 * `fromStore()`/`fromCache()` read from elsewhere (the on-demand
 * Topology-tab LLDP card, and the auto-topology discovery job, which is
 * deliberately cache-only and never queries a device live itself).
 * Without a poller actually calling this on a schedule, that cache never
 * gets populated in the first place — confirmed live this session: no
 * model anywhere had `lldp_info` in its poller schedule, so every
 * cache/store read for it always came back empty.
 */
interface PollerLldpInfoInterface
{
    /**
     * Return the raw `lldp_info` module response
     * (`{local: {chassis_id, ports}, remotes: [...]}`), or null if this
     * device's model doesn't declare LLDP support at all.
     *
     * @return array|null
     */
    function getLldpInfo();
}
