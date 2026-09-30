<?php


namespace WCC\AutoTopology\Controllers;


use WCAA\Infrastructure\Components\AbstractComponentController;
use WCAA\Models\Devices\Device;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\PollerData\FdbHistoryStorage;
use WCAA\SwitcherCore\SwitcherCore;
use WCC\Links\Models\Link;
use WCC\Links\Storage\LinkStorage;

/**
 * Class Controller
 * @package WCC\AutoTopology
 *
 * Native PHP replacement for the vendor's own `bin/auto-topology` binary,
 * which panics on every single run ("panic: Input not correct" in its own
 * main.getInput(), confirmed via its schedule's run history — a closed-
 * source bug, not something fixable from this side). The DB schema
 * (`links.source` enum('manual','fdb','lldp')) already anticipated exactly
 * this feature; this is the first actual implementation of the 'fdb' and
 * 'lldp' sources.
 */
class Controller extends AbstractComponentController
{
    /**
     * @Inject
     * @var LinkStorage
     */
    protected $linkStorage;

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @Inject
     * @var DeviceInterfaceStorage
     */
    protected $deviceInterfaceStorage;

    /**
     * @Inject
     * @var FdbHistoryStorage
     */
    protected $fdbHistoryStorage;

    /**
     * @Inject
     * @var SwitcherCore
     */
    protected $switcherCore;

    function updateLink(Link $data)
    {
        $existedLinks = $this->linkStorage->getByDestDevice($data->getDestDevice());
        $sourceLinks = $this->linkStorage->getBySourceDevice($data->getDestDevice());
        foreach ($sourceLinks as $link) {
            if ($data->getSrcDevice()->getId() == $link->getDestDevice()->getId()) {
                $this->logger->info("Crosslinks - duplicated");
                return $data;
            }
        }

        if (count($existedLinks) == 0) {
            $data = $this->linkStorage->add($data);
            return $data;
        }
        foreach ($existedLinks as $link) {
            if ($link->getSource() === 'manual') {
                $this->logger->info("Has manual link, ignoring");
                continue;
            }
            if ($data->getSrcDevice()->getId() == $link->getDestDevice()->getId()) {
                $this->logger->info("Crosslinks - duplicated");
                continue;
            }
            if (!$link->getDestIface()) {
                $link->setDestIface($data->getDestIface());
            }
            if (!$link->getSrcIface()) {
                $link->setSrcIface($data->getSrcIface());
            }
            if (!$data->getDestIface() || !$data->getSrcIface()) {
                // Merge, don't overwrite: a discovery source that only ever
                // resolves ONE side (FDB always does — see
                // discoverFdbForDevice) must not blank out the other side
                // just because ITS OWN attempt didn't know it — that other
                // side may already have been correctly resolved by a fuller
                // source (LLDP) via the two guarded merges just above. Only
                // this run's genuinely-new, non-null side is ever applied.
                // (Bug found while making this whole branch run on every
                // rescan instead of only on brand-new pairs — previously
                // dormant since nothing revisited an already-linked pair.)
                if ($data->getDestIface()) $link->setDestIface($data->getDestIface());
                if ($data->getSrcIface()) $link->setSrcIface($data->getSrcIface());
                $this->linkStorage->update($link->setUpdatedAt(date('Y-m-d H:i:s')));
                return $link;
            }
            if (
                $link->getSrcIface()->getId() == $data->getSrcIface()->getId() &&
                $link->getDestIface()->getId() == $data->getDestIface()->getId()
            ) {
                $this->logger->info("Link already existed and not changed");
                $this->linkStorage->update($link->setUpdatedAt(date('Y-m-d H:i:s')));
                continue;
            }
            $link
                ->setDestDevice($data->getDestDevice())
                ->setDestIface($data->getDestIface())
                ->setSrcDevice($data->getSrcDevice())
                ->setSrcIface($data->getSrcIface());
            $this->linkStorage->update($link->setUpdatedAt(date('Y-m-d H:i:s')));
        }
    }

    /**
     * Normalizes a MAC-like string (strips separators, uppercases) so a
     * vendor module's own formatting (colons/dashes/case vary) can be
     * compared against however this system stores `devices.mac`. Same
     * logic already used by LldpNeighborsAction.
     */
    private function normalizeMac(?string $mac): ?string
    {
        if (!$mac) return null;
        $clean = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $mac));
        return strlen($clean) === 12 ? $clean : null;
    }

    /**
     * A neighbor's LLDP `rem_interface` string is whatever THAT device's own
     * vendor module puts in lldpRemPortId — not necessarily this app's own
     * normalized interface name. Some vendors' modules already report a
     * name that matches byte-for-byte (confirmed live: a Huawei OLT reports
     * "ethernet0/8/0", exactly matching this system's own interfaces_list
     * naming for it). Others don't: confirmed live, a BDCOM GP3600 OLT's
     * LLDP reports its TenGig uplink as "TGi0/2" — its own internal
     * shorthand — while this system's interfaces_list module (parsing the
     * SAME port's ifDescr, "TGigaEthernet0/2") normalizes it to "tg0/2",
     * so a raw exact-name match against `device_interfaces` never found
     * it, and the link's dest_iface (and by extension the port itself, in
     * anything that displays a link's far-end interface) silently stayed
     * empty. Tries the exact name first (keeps the already-working Huawei
     * case working), then falls back to translating common Ethernet-port
     * abbreviations to this app's own tg/g/gpon short-form.
     */
    private function findInterfaceByRemoteName(Device $device, string $rawName)
    {
        $raw = strtolower(trim($rawName));
        try {
            return $this->deviceInterfaceStorage->getByDeviceAndName($device, $raw);
        } catch (\Throwable $e) {
            // fall through to normalized attempt below
        }
        $normalized = $this->normalizeRemoteIfaceName($raw);
        if ($normalized === null || $normalized === $raw) return null;
        try {
            return $this->deviceInterfaceStorage->getByDeviceAndName($device, $normalized);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function normalizeRemoteIfaceName(string $raw): ?string
    {
        if (preg_match('/^(?:te|tge|tgi|tgig|tgiga|tgigabit|tgigaethernet|tengig|tengigabit|tengigabitethernet|xge)(\d+\/\d+(?:\/\d+)?)$/i', $raw, $m)) {
            return 'tg' . $m[1];
        }
        if (preg_match('/^(?:ge|gi|gig|giga|gigabit|gigaethernet|gigabitethernet)(\d+\/\d+(?:\/\d+)?)$/i', $raw, $m)) {
            return 'g' . $m[1];
        }
        if (preg_match('/^gpon(\d+\/\d+)$/i', $raw, $m)) {
            return 'gpon' . $m[1];
        }
        return null;
    }

    /**
     * Decides which of two neighboring devices is the "core"/upstream
     * side (`dest_device` in this schema — see LinkStorage::getCoreByDevice,
     * which walks dest_device_id backwards to find the root of a tree).
     * The vendor's own (broken) tool determines this via ARP/gateway-MAC
     * heuristics; this is a much simpler, domain-specific stand-in: in
     * this system's real deployments, an OLT aggregates switches (not the
     * reverse), so when exactly one side is an OLT, treat it as upstream.
     * Otherwise, fall back to "whichever device we discovered the
     * neighbor FROM stays downstream" — an arbitrary but stable choice
     * (paired with updateLink()'s own cross-direction dedup, so the choice
     * only matters the first time a pair is ever discovered).
     *
     * @return array{0: Device, 1: Device} [downstream/src, upstream/dest]
     */
    private function orderBySourceDest(Device $discoveredFrom, Device $neighbor): array
    {
        $fromIsOlt = $discoveredFrom->getModel() && $discoveredFrom->getModel()->getType() === 'OLT';
        $neighborIsOlt = $neighbor->getModel() && $neighbor->getModel()->getType() === 'OLT';
        if ($neighborIsOlt && !$fromIsOlt) {
            return [$discoveredFrom, $neighbor];
        }
        if ($fromIsOlt && !$neighborIsOlt) {
            return [$neighbor, $discoveredFrom];
        }
        return [$discoveredFrom, $neighbor];
    }

    /**
     * @return array{lldp_created: int, fdb_created: int, errors: int}
     */
    function discoverLinks(?callable $log = null): array
    {
        $log = $log ?? function ($msg) {};
        $devices = $this->deviceStorage->fetchAll();

        $macToDevice = [];
        foreach ($devices as $d) {
            $mac = $this->normalizeMac($d->getMac());
            if ($mac) $macToDevice[$mac] = $d;
        }

        $stats = ['lldp_created' => 0, 'fdb_created' => 0, 'errors' => 0];

        // LLDP first — real protocol data reported by the device itself,
        // more trustworthy than FDB's "reachable via this port" inference.
        foreach ($devices as $device) {
            try {
                $this->discoverLldpForDevice($device, $macToDevice, $stats, $log);
            } catch (\Throwable $e) {
                $stats['errors']++;
                $log("LLDP discovery failed for {$device->getIp()}: {$e->getMessage()}");
            }
        }
        // FDB second — LLDP is real protocol data straight from the device,
        // so it should win when both sources see the same pair; updateLink()
        // itself is what enforces that (it patches a missing iface on an
        // existing link but won't downgrade/overwrite one LLDP already
        // populated), not a check here.
        foreach ($devices as $device) {
            try {
                $this->discoverFdbForDevice($device, $macToDevice, $stats, $log);
            } catch (\Throwable $e) {
                $stats['errors']++;
                $log("FDB discovery failed for {$device->getIp()}: {$e->getMessage()}");
            }
        }
        return $stats;
    }

    private function discoverLldpForDevice(Device $device, array $macToDevice, array &$stats, callable $log)
    {
        $core = $this->switcherCore->getCore($device);
        if (!$core->isModuleExist('lldp_info')) return;

        // STRICTLY cache/store-only — NEVER a live device query. This
        // component runs unattended on a 3-hour schedule against every
        // device in the system; live SNMP from an automated job is
        // exactly the kind of thing that has caused real live-network
        // outages this session (devices going down repeatedly). A live
        // LLDP read was tried once and removed after that — this is
        // deliberately conservative: if lldp_info was never polled and
        // cached through the device's own normal poller schedule, this
        // just finds nothing for that device, which is the correct
        // trade-off here.
        {
            $resp = $this->switcherCore->fromStore(
                [\WCAA\SwitcherCore\Request::init($device, 'lldp_info')],
                true,
                true
            )->getFirstByModule('lldp_info');
            if (!$resp || $resp->getError()) return;
            $lldp = $resp->getData();
        }
        foreach (($lldp['remotes'] ?? []) as $remote) {
            $normMac = $this->normalizeMac($remote['rem_chassis_id'] ?? null);
            if (!$normMac || !isset($macToDevice[$normMac])) continue;
            $neighbor = $macToDevice[$normMac];
            if ($neighbor->getId() == $device->getId()) continue;

            $locIface = $remote['loc_interface'] ?? null;
            if (!$locIface || !isset($locIface['id'])) continue;
            try {
                $localIface = $this->deviceInterfaceStorage->getByDeviceAndKey($device, $locIface['id']);
            } catch (\Throwable $e) {
                // Discovered a neighbor on a port this system hasn't
                // persisted a device_interfaces row for yet (e.g. never
                // polled/viewed) — nothing to attach the link to yet.
                continue;
            }

            [$srcDevice, $destDevice] = $this->orderBySourceDest($device, $neighbor);
            $srcIface = $srcDevice->getId() === $device->getId() ? $localIface : null;
            $destIface = null;
            if ($destDevice->getId() === $neighbor->getId() && !empty($remote['rem_interface'])) {
                $destIface = $this->findInterfaceByRemoteName($neighbor, (string)$remote['rem_interface']);
            } elseif ($srcDevice->getId() === $neighbor->getId()) {
                // Roles flipped by orderBySourceDest (neighbor is actually
                // the downstream side) — the interface we resolved above
                // belongs to the neighbor's src role, this device's own
                // local interface becomes the dest side instead.
                $srcIface = null;
                $destIface = $localIface;
            }

            // No linkExistsBetween() short-circuit here on purpose — it used
            // to skip straight past updateLink() for any pair that already
            // had a link, which meant a link created before this device's
            // iface data (or, confirmed live, before the BDCOM short-form
            // iface-name fix above) resolved never got a later chance to
            // fill in a still-missing src/dest iface: updateLink() ALREADY
            // has that exact patch-if-missing logic, it just never ran.
            $link = (new Link())
                ->setSource('lldp')
                ->setSrcDevice($srcDevice)
                ->setDestDevice($destDevice);
            if ($srcIface) $link->setSrcIface($srcIface);
            if ($destIface) $link->setDestIface($destIface);
            $this->updateLink($link);
            $stats['lldp_created']++;
            $log("LLDP: {$srcDevice->getName()} -> {$destDevice->getName()}" . ($srcIface ? " (via {$srcIface->getName()})" : ''));
        }
    }

    private function discoverFdbForDevice(Device $device, array $macToDevice, array &$stats, callable $log)
    {
        $entries = $this->fdbHistoryStorage->getByDevice($device, true);
        foreach ($entries as $entry) {
            $normMac = $this->normalizeMac($entry->getMacAddress());
            if (!$normMac || !isset($macToDevice[$normMac])) continue;
            $neighbor = $macToDevice[$normMac];
            if ($neighbor->getId() == $device->getId()) continue;

            $localIface = $entry->getInterface();
            if (!$localIface) continue;

            [$srcDevice, $destDevice] = $this->orderBySourceDest($device, $neighbor);
            // See the matching comment in discoverLldpForDevice() — no
            // linkExistsBetween() short-circuit here either, so an existing
            // link missing an iface still gets a chance to be patched by
            // updateLink()'s own logic instead of being skipped forever.
            $link = (new Link())
                ->setSource('fdb')
                ->setSrcDevice($srcDevice)
                ->setDestDevice($destDevice);
            if ($srcDevice->getId() === $device->getId()) {
                $link->setSrcIface($localIface);
            } else {
                $link->setDestIface($localIface);
            }
            $this->updateLink($link);
            $stats['fdb_created']++;
            $log("FDB: {$srcDevice->getName()} -> {$destDevice->getName()} (learned {$neighbor->getName()}'s MAC on {$localIface->getName()})");
        }
    }
}
