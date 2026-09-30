# BDCOM S5612 — OID reference cross-check against official MIBs

This is a research note, not app documentation — it doesn't affect runtime
behavior. It exists so nobody has to re-do this research, and especially so
nobody re-runs a heavy SNMP walk against the live switch to answer the same
questions again (a full-tree walk against this device previously drove its
CPU up and made its SNMP agent unresponsive for a while).

Source: the 11 official BDCOM MIB files mirrored by Observium's MIB browser
(https://mibs.observium.org/), pulled from
https://github.com/pgmillon/observium/tree/master/mibs/bdcom on 2026-09-05:
`BDCOM-SMI`, `BDCOM-SYS`, `BDCOM-TC`, `BDCOM-TS`, `BDCOM-INTERFACES`,
`BDCOM-IF-MIB`, `BDCOM-IF-THRESHOLD-MIB`, `BDCOM-MEMORY-POOL-MIB`,
`BDCOM-PROCESS-MIB`, `BDCOM-QOS-PIB-MIB`, `BDCOM-FLASH`. All numeric OIDs
below were derived by parsing the real `::= { parent N }` ASN.1 assignments
in those files — not observed live on this device.

## Headline finding: these MIBs are from a much older, different product line

`BDCOM-SMI.mib` dates itself `LAST-UPDATED "20000628Z"` (June 2000) and
explicitly splits the tree into:
- `bdcom.1` (`bdcomProducts`) — sysObjectID root.
- `bdcom.2` (`bdlocal`) — *"Subtree beneath which **pre-10.2** MIBS were
  built"* — a full clone of the classic old-Cisco chassis/interface/
  terminal-server/flash MIB style (`bdfreeMem`, `bdavgBusy1/5`,
  `bdlocIf*` per-protocol interface counters for X.25/AppleTalk/DECnet/
  Vines/etc., `bdlts*` terminal lines, `bdflash*`). This is clearly a
  legacy router/access-server product, not a modern L2/L3 switch.
- `bdcom.9` (`bdMgmt`) — the modern management tree.

Every OID this project has ever gotten a real value back from on the S5612
(both the ones already wired up in `configs/oids/bdcom/s5612.yml` and the
ones captured under `unconfirmed.*` from the earlier full-tree walk) lives
under `bdcom.9` (`bdMgmt`) or higher branch numbers (`.150`, `.151`) that
don't appear in these 11 files at all — **never** under `bdcom.2`
(`bdlocal`). That's consistent with the S5612 being new enough to have
moved on from the legacy tree entirely. Practically: don't bother trying
`bdlocal`'s environmental-sensor OIDs (`bdenvTestPt1-6*`, meant for things
like power-supply/voltage test points) or its per-protocol interface
counters on this switch — they're the wrong product generation.

## Confirms what we already had right

- `resources.cpuUsage5sec` / `cpuUsage1min` / `cpuUsage5min` in `s5612.yml`
  (`.9.109.1.1.1.1.{3,4,5}`) are an **exact match** for the official
  `bdpmCPUTotal5sec` / `bdpmCPUTotal1min` / `bdpmCPUTotal5min` columns in
  `BDCOM-PROCESS-MIB.mib`'s `bdpmCPUTotalTable` (`bdMgmt.109` =
  `bdcomProcessMIB`). Good independent confirmation of an OID we'd already
  verified live.
- `bdMgmt` itself (`.9`) and the root `bdcom` (`1.3.6.1.4.1.3320`) check out
  exactly as expected.

## Does NOT explain any of the `unconfirmed.*` entries in s5612.yml

Checked every `unconfirmed.*` OID in `s5612.yml` against all 571 resolved
names from these 11 files (longest-prefix match). None resolve to anything
more specific than the bare `bdMgmt`/`bdcom` container — i.e. these files
document *some* things under those containers (see below) but not the
particular sub-branches (`.9.181`, `.9.184`, `.9.252`–`.9.360`, `.150.*`,
`.151.*`) this switch actually returned data at. Those remain genuinely
undocumented in public BDCOM MIBs; the `unconfirmed.*` labeling in
`s5612.yml` stays accurate and shouldn't be changed on the strength of this
research alone.

One near-miss worth flagging explicitly so it isn't mistaken for a match
later: `unconfirmed.resourceSummary.*` sits at `.9.48.1.0` through
`.9.48.9.0` (nine flat scalars), and `.9.48` is indeed officially
`bdcomMemoryPoolMIB` (`BDCOM-MEMORY-POOL-MIB.mib`) — **but** the real
`bdcomMemoryPoolMIB` is a proper indexed table (`bdcomMemoryPoolObjects.1.1`
= `bdcomMemoryPoolTable`, indexed by `bdcomMemoryPoolType`), not nine flat
`.N.0` scalars. The shapes don't match, so this switch is reusing the
`.9.48` branch number for something else entirely, not implementing the
standard Memory-Pool-MIB. Don't rename these fields to
`bdcomMemoryPool*`-style names — it would be wrong.

## Real, documented features found here that aren't wired up yet

Untested on this specific switch (per the "don't query the device"
instruction for this pass) — listed as candidates for a future,
low-risk confirmation pass (single scalar `snmpget`s, not a bulk walk):

- **`BDCOM-IF-THRESHOLD-MIB` (`bdMgmt.218`)** — a real, fully-specified
  per-interface threshold/alerting subsystem: define named threshold
  *templates* (`bdifthTemplateTable`, `.9.218.1.1.3`) containing one or
  more monitored-object thresholds (`bdifthThresholdTable`, `.9.218.1.1.5`
  — object OID, rising/falling direction, severity, sample interval,
  fired/cleared values), assign a template to specific interfaces
  (`bdifthTemplateIfAssignTable`, `.9.218.1.2.2`), and read back which
  thresholds have actually fired (`bdifthIfThresholdFiredTable`,
  `.9.218.1.3.3`) plus two traps (`bdifthIfThresholdFired` /
  `bdifthIfThresholdCleared`, `.9.218.2.0.{1,2}`). If the S5612 firmware
  implements this, it would let this app configure "alert me when this
  port's error rate/utilization crosses X" server-side on the switch
  itself, instead of only client-side threshold checks against polled
  counters. Worth one cautious read-only `snmpget` against
  `bdifthTemplateIndexNext.0` (`.9.218.1.1.1.0`) next time the device is
  healthy, to see if it returns a value at all before investing more.
- **`BDCOM-IF-MIB` (`bdMgmt.63`, `.9.63.1.1.*`, `vifTable`)** — a small
  IF-MIB-shaped extension table (index/descr/type/mtu/speed/physAddress/
  admin+operStatus/lastChange). Likely redundant with standard `IF-MIB`
  and this project's existing `interfaces_list`/`link_info` modules, so
  low priority — flagged only for completeness since it shares the same
  `.9.63` container as the switch's own `sfp.ddm.*` OIDs (which are an
  undocumented sibling branch, `.9.63.1.7.*`, not covered by this public
  MIB at all).
- **`BDCOM-FLASH` (`bdlocal.10`, i.e. `bdcom.2.10`)** — flash storage
  size/free/directory listing and erase/upload/download control. Sits
  under the legacy `bdlocal` tree flagged above as probably-wrong-product-
  generation, so low confidence this applies to the S5612 at all.

## Full resolved OID list

All 571 names this pass resolved to numeric OIDs (root-relative, i.e.
`1.3.6.1.4.1.3320...`) are saved alongside the raw `.mib` files at
`/tmp/claude-0/-root-nms/51c32123-f17c-4dfd-89e3-fffe7f6f4126/scratchpad/bdcom_mibs/`
for this session — not committed here since it's a bulk data dump rather
than something worth tracking in git; regenerate by re-fetching the same
GitHub path if needed again.

## Update: the actually-correct MIB set (found via LibreNMS, not Observium)

The Observium mirror above turned out to be the wrong generation (see
"Headline finding" above). **LibreNMS actively maintains a real, current
BDCOM switch driver** (`LibreNMS/OS/Bdcom.php`, `resources/definitions/
os_detection/bdcom.yaml`, `resources/definitions/os_discovery/bdcom.yaml`),
backed by a completely different, modern `NMS-*`-prefixed MIB set at
`github.com/librenms/librenms/tree/master/mibs/bdcom`: `NMS-SMI`,
`NMS-IF-MIB`, `NMS-CHASSIS`, `NMS-CARD-SYS-MIB`, `NMS-FAN-TRAP`,
`NMS-POWER-MIB`, `NMS-OPTICAL-PORT-MIB`, `NMS-LLDP-MIB`, `NMS-GPON-MIB`,
`NMS-EPON-OLT-PON`. LibreNMS detects BDCOM via `sysObjectID` starting
`.1.3.6.1.4.1.3320.1` and `sysDescr` matching `BDCOM(tm) Software` — exactly
what this switch's own `sysDescr` looks like ("BDCOM(tm) S5612 Software,
Version ...").

Even better: LibreNMS's test suite ships a **real captured SNMP recording**
from an actual BDCOM switch of the same S-series family —
`tests/snmpsim/bdcom_s2900-24s8c4x.snmprec` (an S2900-24S8C4X, sysObjectID
`.1.3.6.1.4.1.3320.1.458.0`) — real OID→value pairs from a live device,
usable to cross-check shapes and sample values with zero SNMP traffic to
our own switch.

Confirmed against that real capture:

- **`sfp.ddm.*` in `s5612.yml` is exactly LibreNMS's `NMS-IF-MIB::ifSfpParameterTable`** —
  same base `.9.63.1.7.1.*`, same field offsets (rxPower1=`.3`, txPower1=`.2`,
  temperature=`.4`, voltage=`.5`), and the **same divisors** our
  `SfpOpticalInfo.php` already uses (txPower/rxPower ÷100, temp ÷256,
  voltage ÷10000) and the same `-65535` "no reading" sentinel. Independent
  confirmation this module was already implemented correctly.
- On that real S2900 capture, most optical rows (902 of 1232) are **real,
  non-sentinel values** — e.g. `txPower1 = -508` (–5.08 dBm) — proving DDM
  readings genuinely work on BDCOM switches in general. Our own S5612
  returning `-65535` on every port is therefore this specific unit's
  transceivers/config, not a universal BDCOM/firmware limitation as
  previously written — worth revisiting if the SFPs ever get swapped.
- **Fan and power-supply sensors are real and populated** on that capture —
  genuinely new, not yet wired up here, and not mentioned in the Observium
  MIBs at all:
  - `NMS-FAN-TRAP::FanStatus` — `.9.187.4.1.2.{index}` (states: 1=Normal,
    2=Stop, 3=Unused). Real capture: 9 fans, all `1`.
  - `NMS-FAN-TRAP::FanSpeed` — `.9.187.4.1.3.{index}` (RPM). Real capture:
    `3070`.
  - `NMS-POWER-MIB::powerStatus` — `.9.189.1.{index}` (states: 1=power-A-
    normal, 2=power-B-normal, 3=power-A-B-normal, 4=other). Real capture: `3`.
  - `NMS-IF-MIB::sfpPresentStatus` — `.9.63.1.7.1.20.{ifIndex}` (1=online,
    2=offline) — lets optical-info code skip ports with no transceiver
    inserted instead of showing nulls; not currently used by
    `SfpOpticalInfo.php`.
  - Chassis-level `nmscardTemperature` (`.3.6.10.1.13`) and `nmscardVoltage`
    (`.3.6.10.1.14`) are in the schema but **absent from the real capture
    too** (0 rows) — so unlike fan/power, chassis temperature genuinely
    looks unpopulated across this device family, not just our unit.
  - `resources.cpuUtil`/`memUtil` in `s5612.yml` (`.3.6.10.1.{11,12}`) match
    `NMS-CHASSIS::nmscardCPUUtilization`/`nmscardMEMUtilization` exactly —
    another already-correct entry now independently confirmed.

## Update: implemented (2026-09-05) — single-OID confirmation, then real fixes

Checked live against the actual switch, but only ever via single `snmpget`s
(never a walk), in the confidence order above:

- **`sfpPresentStatus` (`.9.63.1.7.1.20.{xid}`) — confirmed real and now
  wired up.** Every port (all 9 checked individually) reads `offline(2)`.
  This is the actual reason `sfp.ddm.*` returns nulls everywhere — no
  monitored transceiver is inserted anywhere on this unit right now — not a
  DDM/firmware limitation as this doc previously said. Added
  `sfp.ddm.present` to `s5612.yml`, wired into
  `BDcom\GP3600\SfpOpticalInfo` (shared with the OLT modules, optional per
  model like the other fields), surfaced as `optical.present` in the API
  and as an explicit "No transceiver present in this port" message in the
  UI (`SwitchInterfacesTab.vue`'s Optical column, `SwitchInterfaceDetailPanel.vue`'s
  SFP card) instead of blank cells.
- **`FanStatus`/`FanSpeed` (`.9.187.*`) and `powerStatus` (`.9.189.1`) — confirmed
  ABSENT on this unit** ("No Such Object", not a timeout) — unlike the
  S2900 test capture. Deliberately NOT wired up for `bdcom_s5612`; this
  specific model's firmware/hardware genuinely doesn't expose these
  sensors. Documented in `s5612.yml` so nobody re-tries this.
- **Chassis temperature (`.3.6.10.1.13.0`) — confirmed ABSENT** ("No Such
  Instance"), consistent with it also being empty on the S2900 capture —
  a family-wide gap, not unit-specific.

### A real, separate bug found and fixed along the way

While wiring up `sfp.ddm.present`, discovered `BDcom\GP3600\SfpOpticalInfo::getPretty()`
had a scoping bug that predates this work entirely: each metric's
`foreach` over its SNMP rows lived INSIDE a single try/catch wrapping the
whole loop, so one bad row (a `parseInterface()` failure — see below) threw
partway through and discarded every already-collected reading for that
metric, not just the bad row. In practice this meant `sfp_optical` had
been returning an **empty result for every port on this switch, always** —
independent of the `-65535`/DDM question entirely. Fixed by giving each row
its own try/catch (`collectByName()` helper) so one bad interface can't
take down the others. Confirmed live: `optical.present` now correctly
shows `false` for all 9 real ports post-fix.

The "bad row" that triggered this was itself worth writing down:
`BDcomAbstractModule::getIdByName()` hardcodes `tg0/N`/`g0/N`/`fe0/N`
(slot **0** only) when computing a port's stable `id`. This switch has an
11-port 10G bank actually named `tg1/1`–`tg1/12` (slot **1**) — none of
those match the slot-0 regexes, so `getIdByName()` returned `null` → `id=0`
for **all eleven** of them, colliding into a single `id=0` slot — only
whichever one the SNMP walk happened to return last (`tg1/12`) was ever
visible in this app; the other ~10 were silently invisible.

**Fixed (2026-09-05).** `getIdByName()`'s `tg`/`g`/`fe` patterns are now
slot-aware: `id = base + slot*multiplier + port` (multiplier 1000 for
tg/g, 100 for fe — ample headroom before colliding with the next port
type's namespace or another slot, verified against this device). Slot 0
produces byte-for-byte the same ids as before (0 contributes nothing), so
every other already-deployed BDCOM device (all of which only ever had
slot-0 ports, since that's the only slot the old code could ever resolve)
is completely unaffected. `epon`/aggregator patterns were deliberately
left untouched — they affect live OLTs with real historical bind_key data,
a separate, higher-stakes change not needed to fix this switch.

Fixing the id collision surfaced a second, smaller, real bug: with real
distinct ids, `SwitchesController::getInterfaceFullInfo()`'s per-module
mapping loops (`fdb`/`link_info`/`errors`/`counters`/`descriptions`/
`cable_diag`/`sfp_optical`/`sfp_media`/`vlans_by_port`/`rmon`) started
crashing (`Undefined index: interface`) — root cause: `interface_counters`'s
raw counter-OID walk includes a port (`tg1/10`) that `interfaces_list`'s
own port-discovery logic doesn't return at all (a separate, pre-existing,
still-unresolved small inconsistency between the two — worth investigating
further some day, but not blocking). Since `$RESPONSE` is seeded only from
`interfaces_list`, that extra id auto-vivified a new entry with no
`'interface'` key, crashing the sort. Fixed by guarding every mapping loop
with `if (!isset($RESPONSE[$id])) continue;` — safe in general (a module
reporting data for an id `interfaces_list` doesn't know about now gets
silently dropped instead of crashing).

**Confirmed live, stable across repeated requests:** the interfaces API
now returns all 21 real ports (up from 11) — including 8 of the 10G ports
that are actually `Up` and previously never appeared in the UI at all
(`tg1/1`, `tg1/2`, `tg1/3`, `tg1/6`, `tg1/7`, plus the two that were
already visible, `po1`/`g0/1`/`g0/2`). `tg1/10` remains the one known gap
(present in counters, absent from the list) — low-impact, flagged above
for a future look rather than fixed now.
