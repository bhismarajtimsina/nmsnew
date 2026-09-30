# BDCOM MIB analysis — switch, EPON OLT, and GPON

Source: `/root/nms/BDCOM_MIBS/` and `/root/nms/NMS_BDCOM_MIBS/` (~90 files
each) are **the original, latest MIBs, downloaded directly from BDCOM's
own website — confirmed authoritative by the user.** These two supersede
everything else: the earlier third-party MIB mirror this doc originally
drew from (`docs/bdcom-s5612-oid-reference.md`), and the third local
folder below (`/root/nms/bdcom/`, sourced from a general multi-vendor git
collection, not BDCOM directly) wherever they disagree. This is a
structural analysis (which OID belongs to which real feature), not a
live-device confirmation — **no SNMP was sent to any device while
producing this**, per instruction.

## Structural finding: two folders, one tree, two naming eras

Both folders anchor at the exact same enterprise root, `enterprises.3320`
— `BDCOM-SMI.my`'s `bdcom` and `NMS-SMI.my`'s `nms` are the same node under
two different label sets. Some modules are byte-identical between the two
(optical-port, power); others (system, chassis, process, LLDP, memory-pool)
differ substantially — the `nms`-branded folder is the newer/current one.
The switch's own `sysDescr` ("BDCOM(tm) S5612...") and every OID that's
ever returned real data on it lives under `nms`/`bdcom` numbering `.9.*`
or higher branch numbers — never under the legacy `.2.*` (`nmslocal`/
`bdlocal`, explicitly labeled "pre-10.2" in the MIB itself).

## GPON: correction — it does exist, in a third location

**This was wrong in the first pass of this analysis** — checked only
`BDCOM_MIBS`/`NMS_BDCOM_MIBS` and found nothing, and wrongly concluded
BDCOM doesn't make GPON gear. The user then pointed at a third location,
`/root/nms/bdcom/` (mirrored at `/root/mibs/MIBS/bdcom/` — a much larger,
general-purpose multi-vendor MIB collection, git remote
`git.davidleutgeb.at/mibs`), which contains a real, substantial
**`NMS-GPON-MIB`** (117KB — the largest file in that folder). So BDCOM
does make GPON OLTs after all; this app's vendor library just doesn't
have a model wired up for one yet (only the EPON `gp3600`/`p33xx` series
exist in `device_models` today).

Real structure, confirmed by reading the file directly (`nmsGponMIB =
nms.10`, i.e. `.3320.10.*`):

| Branch | Object | Covers |
|---|---|---|
| `.10.1` | `nmsGponOltObj` | OLT-wide GPON config — `gponOltConfigTable` includes `gponOnuAuthenticationMode` (serial-number-only / serial+password / disabled) and `gponBroadcastGEMPort` |
| `.10.2` | `nmsGponOltPonPortObj` | Per-PON-port config/status/stats (4 tables, only generically described in the MIB text) + OLT-side SFP alarm trap (`.10.2.5`) + **PON-port-to-ONU binding table** (`.10.2.6`) |
| `.10.3` | `nmsGponONUObj` | ONU information table(`.1`)/config table(`.2`)/status table(`.3`,`.5`) + **`gponOnuOpticalPowerTable`(`.10.3.4`)** — real, simple 3-column table: `gponOnuOpticalPowerDeviceIndex`, `gponOnuOpticalPowerRxPower`, `gponOnuOpticalPowerTxPower` (per-ONU optical power, exactly the "neighbor power" data a topology/link view would want to show) — plus an alarm-threshold table (`.10.3.5`) and SFP/status/dying-gasp alarm traps (`.6`/`.7`/`.8`; dying-gasp = the ONU's own power-loss notification, valuable for real-time outage detection) and an ONU batch-update table (`.9`) |
| `.10.4` | `nmsGponUNIPortObj` | ONU-side customer-facing (UNI/LAN) port config — 2 tables, not yet examined in detail |
| `.10.5` | `nmsGponVirPortObj` | GEM/virtual port config — 1 table, not yet examined |
| `.10.6` | `nmsGponProfile` | Service profiles — `onuVLANProfile` confirmed, others not yet examined |

Everything else in `/root/nms/bdcom/` overlaps with files already covered
above (`BDCOM-SMI`/`TC`/`MEMORY-POOL`/`PROCESS`/`QOS-PIB`, `NMS-CARD-SYS-MIB`,
`NMS-CHASSIS`, `NMS-EPON-OLT-PON`, `NMS-FAN-TRAP`, `NMS-LLDP-MIB`,
`NMS-OPTICAL-PORT-MIB`, `NMS-POWER-MIB`, `NMS-SMI`) — diffed against the
earlier folders: `OPTICAL-PORT-MIB` and `POWER-MIB` are byte-identical,
everything else differs by hundreds to low-thousands of lines. Since
`BDCOM_MIBS`/`NMS_BDCOM_MIBS` are the confirmed-authoritative source
(direct from BDCOM's own site) and this third folder is from a
general-purpose multi-vendor collection, **wherever they disagree, the
first two win** — this folder's real, unique contribution is the GPON
MIB above (absent from the authoritative pair entirely), not a competing
version of anything already covered. Worth keeping in mind: since
BDCOM's own official site didn't bundle a GPON MIB, that may mean their
current official lineup doesn't include GPON products (or the download
just didn't include it) — treat the GPON structure above as
real-but-unverified-against-the-authoritative-source until corroborated.

## Switch (matches this app's `bdcom_s5612` model)

Enterprise tree map for a plain switch, confirmed structurally from the
real MIBs:

| Branch | Module | What it covers |
|---|---|---|
| `.3320.3.6.*` | `NMS-CHASSIS-MIB` (`nmstemporary.6`) | Per-card CPU/mem utilization, temperature, voltage — this is where `resources.cpuUtil`/`memUtil` (`.3.6.10.1.11`/`.12`, already confirmed live) actually live: `nmscardCPUUtilization`/`nmscardMEMUtilization` in a per-card table (`.3.6.10.*`) |
| `.3320.9.1.*` | `NMS-SWITCH-MIB` (`switchMIB`) | Global switch config. `switchMIBObjects.1` = `switchSystem`; `NMS-HAL-GLOBAL-MIB`'s `switchHALGlobal` (`switchSystem.1`) holds 16+ real scalars: `eapsLinkScanInterval`(1), **`systemMTU`(2)** (confirms our old `unconfirmed.mtuRelated.jumboMtu`), `shareLoad`(3)/`shareLoadBalance`(4) (LAG load-balance mode), `errorFrameThreshold`/`errorFramePeriod`(5-6), then a full CPU-protection priority-queue block: `arpPriority`(7), `bgpPriority`(8), `bpduPriority`(9), `dhcpPriority`(10), `igmpPriority`(11), `reservedIPMCPriority`(12), `lookupFailPriority`(13), `protoExecPriority`(14), `defaultPriority`(15), `cpuPortThreshold`(16). `switchMIBObjects.2` = `switchModules`, which includes `portSecurityTable` (per-port security mode: none/dynamic/static-accept/static-reject, plus max-host-count) — a real, unbuilt port-security feature. |
| `.3320.9.63.*` | `NMS-IF-MIB` | Confirmed — `ifSfpParameterTable` (our `sfp.ddm.*`), plus a smaller `vifTable` (basic IF-MIB-shaped index/descr/type/mtu/speed/physAddress/status, redundant with standard `IF-MIB`). |
| `.3320.9.64.*` | `NMS-INTERFACE-EXT` | Confirms old `unconfirmed.portIndexTable` — an interface-extension table, not yet examined field-by-field. |
| `.3320.9.152.*` | `NMS-MAC-MIB` | A third top-level sibling to QoS(`.150`)/ACL(`.151`) — the switch's own MAC address table module (distinct from the standard bridge-MIB FDB) — worth checking against our `unconfirmed.*` entries if any remain in that numeric neighborhood. |
| `.3320.9.150.*` | `NMS-QOS-MIB` + `NMS-QOS-EXT-MIB` | **Fully mapped this session** — CoS-map tables (global/per-port), WRR bandwidth scheduler, port rate-limiting, RED/WRED congestion control, DSCP mapping, ACL-based classify/action policy maps, per-interface default-CoS and queue-bandwidth tables, `qosTrust` (trust mode). Every one of our old `unconfirmed.qos.*` guesses now has a real name (see `bdcom-s5612-oid-reference.md`'s update). |
| `.3320.9.151.*` | `NMS-ACL-EXT-MIB` + `NMS-MacAcl` | **Fully mapped** — ACL-applied-on-interface/VLAN/slot tables, plus a separate standalone MAC-ACL rule table (name/rule-id/src-mac/dst-mac/mask/ethertype/rowstatus). |
| `.3320.9.181.*` | `NMS-CARD-SYS-MIB` | **Fully mapped** — `cardSysIndex`/`cardSysDescr`/`cardSysType`/`cardCPUUtilThreshold`/`cardMemUtilThreshold`/`cardCPUTempThreshold`/`cardCPUTempCurr` (7 real fields; our old guesses for fields 1-3 were wrong — "modelName"/"uptimeTicks" were actually `cardSysDescr`/`cardSysType`). Field `.181.7.*` (our `unconfirmed.unit.subFlag*`) is NOT in this MIB — a genuine undocumented extension beyond the official 7-field table. |
| `.3320.9.184.*` | `NMS-CARD-OPERATION` | **Mapped** — `cardMasSlvSwitch` (master/slave switchover control) + `cardResetTable` (per-card reset). |
| `.3320.9.188.*` | `NMS-AUTHENTICATION-TRAP` | **Mapped, with a correction** — `authenIpAddr`(1), `authenVty`(2) (a VTY **line number**, not a netmask as previously guessed), `authenUserName`(3), `authenTime`(4), **plus `authenStatus`(5)** (success/failed) — a real field this app never captured before. |
| `.3320.9.252.*` | `NMS-LOOPBACK-DETECT-MIB` | L2 loopback detection — global enable + per-port config table. Unbuilt feature. |
| `.3320.9.253.*` | `NMS-STP` | PortFast/BPDU-Guard/BPDU-Filter/UplinkFast/BackboneFast/LoopGuard per-port toggles — separate from the standard Bridge-MIB STP state fields this app already has confirmed elsewhere. |
| `.3320.9.355.*` | `NMS-DHCP-SERVER-MIB` | DHCP server enable + address-pool table. Unbuilt feature. |
| `.3320.9.357` | `NMS-L2-PROTOCOL-TUNNEL-MIB` | L2 PDU tunneling (forwards STP/CDP-like PDUs transparently between customer sites). |
| `.3320.230/231/234` (`nmslocal`) | `NMS-EAPS-MIB`/`NMS-ERPS-MIB`/`NMS-MEAPS-MIB` | Ring-protection-protocol MIBs — **anchored under the legacy `nmslocal` tree**, same tree this switch generation doesn't otherwise use. Uncertain whether this firmware implements ring protocols at this location at all — flagged, not confirmed. |

Other switch-relevant files not yet dug into field-by-field (present, not
yet cross-referenced against anything this app captured): `NMS-VLAN-EXT-MIB`,
`NMS-PVLAN-EXT-MIB` (private VLAN), `NMS-IEEE8023-LAG-MIB` (802.3 LAG,
separate from BDCOM's own aggregator scheme this app already uses),
`NMS-DHCP-SNOOPING-MIB`, `NMS-IF-THRESHOLD-MIB`, `NMS-IPAcl`, `NMS-GBSC-MIB`.

## EPON OLT (matches this app's `bdcom_gp3600_*`/`bdcom_p33xx_*` models)

Entire EPON tree hangs off `nmsEPONGroup` = `.3320.101.*`. Real sub-module
map (each a real, separate MIB file):

| Branch | Module | Covers |
|---|---|---|
| `.101.2` | `NMS-EPON-OLT-CHIP-INFO` | PON chip/hardware identification |
| `.101.4` | `NMS-EPON-OLT-MULTICAST-FORWARD` | Multicast forwarding config |
| `.101.5` | `NMS-EPON-OLT-MULTICAST-VLAN` | Multicast VLAN config |
| `.101.6` | `NMS-EPON-OLT-PON` | The main PON-port table — confirms LibreNMS's own driver used this exact branch for `activeOnuNum`/`inactiveOnuNum` (already known); likely also carries per-PON-port optical Tx power and ONU-count fields worth a proper field-by-field pass |
| `.101.7` | `NMS-EPON-OLT-PSG` | Not yet examined |
| `.101.8` | `NMS-EPON-OLT-NNI` | Network-facing (uplink) port config |
| `.101.10` | `NMS-EPON-ONU` (58KB — the single largest EPON file) | Main ONU table: registration, status, model, distance, etc. — the richest single source for ONU-side data, not yet read field-by-field |
| `.101.10.?` | `NMS-EPON-ONU-OPTICAL-PARAM-ALRAM-SET` (`nmsEponOnu.6`) | Per-ONU optical alarm **thresholds** (configurable high/low limits) — distinct from the live optical readings this app already polls |
| `.101.10.?` | `NMS-EPON-ONU-IF-STATS` (`nmsEponOnuIf.2`) | Per-ONU-interface traffic counters |
| `.101.11` | `NMS-EPON-LLID-ONU-BIND` | LLID-to-ONU binding table |
| `.101.13` | `NMS-EPON-ONU-VLAN` | Per-ONU VLAN config |
| `.101.16` | `NMS-EPON-PON-ILLEAGL-REG-TRAP` | Illegal/rogue ONU registration attempt trap |
| `.101.21` | `NMS-EPON-OLT-SLOT` | Chassis slot table (for multi-slot OLT chassis) |
| `.101.101` | `NMS-EPON-ONU-STATIC-MAC` | Static MAC entries per ONU |

Not yet examined at all: `NMS-EPON-EOC-*` (4 files — Ethernet-over-Coax
embedded management channel, likely irrelevant unless this deployment
uses coax extenders), `NMS-EPON-OAM-REMOTE-LOOPBACK`, `NMS-EPON-ONU-BATCH-*`
(bulk ONU provisioning), `NMS-EPON-ONU-SERIAL-*` (ONU serial-console
passthrough), `NMS-EPON-ONU-UNI-*` (per-ONU-LAN-port ACL/QoS),
`NMS-EPON-TFTP` (firmware transfer), `NMS-EPON-ONU-RESET`,
`NMS-EPON-ONU-REMOTE-SERVER-INFO`.

## What this pass did NOT cover

Router/L3-class modules present in both folders but almost certainly not
applicable to this deployment's switches/OLTs: `NMS-ROUTING-MIB`,
`NMS-WAN-MIB`, `NMS-NAT`, `NMS-R-QOS-MIB`, `NMS-WLAN-MIB`, `ISIS-MIB`.
Also not examined: `NMS-IPSLA-MIB`, `NMS-REMOTE-PING-MIB`,
`NMS-LOG-SERVER-MIB`, `NMS-NTP-MIB`/`NMS-SNTP`, `NETFLOW-MIB`,
`NMS-SERIAL` (console port config), the full `NMS-TC` (textual
conventions — type definitions other MIBs depend on) and `NMS-SYS`
(39KB — anchored under the legacy tree per the table above, so likely
low-value for this switch generation, but not read line-by-line to
confirm that assumption).

## Update: LLDP fixed at the root cause (2026-09-06)

Found and fixed the actual reason `BDcom\LldpInfo` always returned empty/
errored on this switch (and presumably every BDCOM device). `NMS-LLDP-MIB`
(authoritative, from BDCOM's own site) is literally the standard IEEE
802.1AB-2004 LLDP-MIB — its own `DESCRIPTION` says so verbatim — but
BDCOM re-hosts it under their own private enterprise branch, `nms 127`
(`.1.3.6.1.4.1.3320.127.1.*`), instead of the standard IANA-assigned
location (`.1.0.8802.1.1.2.1.*`) that this app's `global.oids.yml` points
`lldp.locChassisId`/`lldp.locPortId`/`lldp.remChassisId`/`lldp.remPortId`
at. Same field structure, same offsets, wrong root — which is exactly why
every query came back "No Such Object": right shape, wrong OID tree
entirely, not missing data.

Confirmed field-by-field against the real MIB text:

| Name | Standard OID (wrong, what `global.oids.yml` had) | BDCOM's real private OID |
|---|---|---|
| `lldp.locChassisId` | `.1.0.8802.1.1.2.1.3.2` | `.1.3.6.1.4.1.3320.127.1.3.2` |
| `lldp.locPortId` | `.1.0.8802.1.1.2.1.3.7.1.3` | `.1.3.6.1.4.1.3320.127.1.3.7.1.3` |
| `lldp.remChassisId` | `.1.0.8802.1.1.2.1.4.1.1.5` | `.1.3.6.1.4.1.3320.127.1.4.1.1.5` |
| `lldp.remPortId` | `.1.0.8802.1.1.2.1.4.1.1.7` | `.1.3.6.1.4.1.3320.127.1.4.1.1.7` |

Added the private-OID versions to `configs/oids/bdcom/s5612.yml` as a
per-model override (confirmed, config-only, this session: `OidCollector`
resolves model-specific files' names over `global.oids.yml`'s same names
for that model — verified directly via `readEnterpriceOids()`, no device
contact). Cache cleared and RoadRunner reset so it's live for the next
real request. **Not yet live-tested against the actual switch** — still
holding off on live SNMP per instruction — so this is confirmed-correct
by MIB text and config-loading, not yet confirmed-working end-to-end.

If it works, this is exactly the fix the whole "auto-topology" effort
needed: LLDP-based neighbor discovery (real protocol data, not FDB
inference) becomes genuinely usable on BDCOM devices for the first time.
Worth checking whether the same fix applies to any other BDCOM model that
declares `lldp_info: \SwitcherCore\Modules\BDcom\LldpInfo` (currently
only `bdcom_s5612` does).

No other code or config changes were made this pass beyond this one,
targeted fix.

## Update: the whole BDCOM switch line is now supported (2026-09-09)

Until now the library knew exactly one BDCOM switch, `bdcom_s5612`, against
a dozen product families — any other BDCOM switch was refused outright with
"Device ... not supported by system". `configs/models/BDcom.yml` now carries
15 switch entries covering S1000, S2200, S2500, S2900, S3700, S3900, S5600,
S5700, S5800, S6500, S8500, S9500, the IES industrial line, the legacy
S2xxx/S3xxx numbering BDCOM's own `bdchassisType` enumerates, and a generic
last-resort entry, plus 34 exact sub-models from BDCOM's catalogue reached
through `rewrites` on `cardSysDescr`.

**Why detection is by sysDescr, not sysObjectID.** Product OIDs are assigned
per model under `nmsProducts` (`.1.3.6.1.4.1.3320.1.<n>`) and BDCOM does not
publish the assignments in any MIB in either authoritative folder — the SMI
defines the branch and stops. Guessing them would produce entries that match
nothing. So each family matches on the model name BDCOM writes into sysDescr
("BDCOM(tm) S5612 Software, Version 127335"), guarded by the enterprise root
so no other vendor can land on a BDCOM model. The one model with a confirmed
product OID, S5612, keeps its exact match and stays first.

**Why they share one OID file and one module set.** `configs/oids/bdcom/
s5612.yml` documents the private tree this generation of switches publishes —
system/serial (`.9.225.1.*`), CPU and memory (`.3.6.10.1.11/12`), SFP DDM
(`.9.63.1.7.1.*`), LLDP at BDCOM's own `.127.1.*` root, STP, VLANs. Those are
family-wide locations, not per-part ones; what differs between an S2900 and an
S5800 is port count and silicon, not where sysDescr lives.

**`fdb` stays unwired across all of them**, for the reason already recorded on
`bdcom_s5612`: the only FDB module for BDCOM drives the console, and that hung
a worker for 90+ seconds against a switch whose telnet accepts a connection
then drops it. A generic SNMP FDB reader
(`\SwitcherCore\Modules\General\Switches\FdbDot1Bridge`) exists and would be
the safe way to fill this gap, but it is abstract and has no BDCOM subclass
yet, and writing one deserves a live device to test against.

Application-side rows live in `migrations/062_bdcom_switch_models/up.sql` (49
`device_models` rows, guarded by `WHERE NOT EXISTS` so re-running is safe) and
`components/Oxidized/models_map.yml` gained the matching backup entries.

Verified: 25 detection cases (every family, the generic fallback, all seven
BDCOM OLTs unshadowed, and another vendor's OLT), all 49 model keys resolving
including the rewrite sub-models, every module class existing and every
essential OID resolving for all 16 switch entries, and — against the real
S5612 at 172.30.64.5 — live re-detection through `compare-model` still
returning `bdcom_s5612`, with its 21 interfaces still reading normally.

### Follow-up: switch families restructured to match the OLT layout

The first pass had all 16 switch entries share one OID file named after the
S5612. That worked but read wrong — the OLT families each have their own file
(`gp3600.yml`, `3310c.yml`, `3310b.yml`), and the switches should be laid out
the same way. They now are:

- `switch-common.yml` (renamed from `s5612.yml`) is the shared base — the
  nmsMgmt and chassis-tree OIDs proven identical across an EPON OLT, a GPON
  OLT and the switch. Every switch model lists it first.
- Thirteen family files sit beside it (`s2900.yml`, `s5800.yml`, `ies.yml`,
  `legacy-switch.yml`, …), each declaring what that family adds: power supply
  status, fan status and speed, and card temperature. Sources are BDCOM's own
  MIBs — `NMS-POWER-MIB` puts `power` at `nmsMgmt 189` with `powerStatus` as
  `power.1`; `NMS-FAN-TRAP` puts `fanTrap` at `nmsMgmt 187` with `FanTable` at
  `fanTrap.4`, status in column 2 and speed in column 3.
- `power_status` and `sys_temp` are wired on those families, so a switch with
  the hardware now reports PSU and temperature the way the OLTs do.

Two entries deliberately keep the base alone. `bdcom_s5600_series` is the
S5612's own family, and that unit is the one confirmed against real hardware:
it answers "No Such Object" for the entire `.9.189` power branch and for fan
and card temperature, so declaring those would contradict the evidence.
`bdcom_switch_generic` matched nothing specific, so it assumes nothing.

Sensors are declared per family rather than guessed at per family. Whether a
unit answers is a question about the hardware in front of you, not the series
name. `\SwitcherCore\Modules\BDcom\Switches\PowerStatus` is new for exactly
that reason: the OLT's version reaches into `fetchAll()[0]` and assumes a
reading is there, which is true of dual-feed rack OLTs and false across the
switch line, so this one reports "Not reported" instead of fatalling on a
null. `Switches\SystemResources` already behaved this way for fan and
temperature.

### Follow-up: the guessed OID names are gone, resolved from the NMS MIBs

`switch-common.yml` shipped 74 names under an `unconfirmed.*` prefix — real
readings from a live walk with labels guessed from their values. All 74 have
now been resolved against the NMS-* MIBs, which are BDCOM's published
definitions for the whole product line, and renamed to what BDCOM calls them.

The resolution is mechanical, not eyeballed. `tools/bdcom-mib-oid-map.py`
parses every MIB in `NMS_BDCOM_MIBS/`, follows each object's
`::= { parent N }` chain back to the enterprise root, and produces a
name-to-numeric-OID map (2861 objects). Each new name in the config was then
checked against that map: all 54 that claim to be a specific MIB object carry
exactly the OID the MIB assigns it.

Several old guesses were wrong, which is the argument for working from the
definitions rather than from the values:

| Was | Really is | Source |
|---|---|---|
| `unit.uptimeTicks` | `cardSysType` | NMS-CARD-SYS-MIB |
| `portTimerOrRateLimit` | `dot1qPvid`, the per-port PVID | NMS-VLAN-EXT-MIB |
| `largeTable3/4/5`, "probably FDB" | ACL applied on interface / VLAN / slot | NMS-ACL-EXT-MIB |
| `resourceSummary.*` | the memory pool table | NMS-MEMORY-POOL-MIB |
| `feature231.flag1-5` | ERPS ring count and PDU counters | NMS-ERPS-MIB |
| `globalFeatureState253.a-d` | PortFast / BPDU-Guard / BPDU-Filter / UplinkFast | NMS-STP |
| `qos.table3/4/6/7/10/13/19` | CoS maps, port queue and rate tables, trust mode | NMS-QOS(-EXT)-MIB |

Two are worth using rather than merely naming: `vlan.port.pvid` is the
untagged VLAN per port, and `card.cpuTempCurrent` (cardSystemSetEntry column
7) is a CPU temperature at a different location from the chassis-MIB one that
reads nothing on the S5612.

Twenty-one names keep an `undocumented.` prefix — branches that answer on the
hardware but appear in no NMS-* MIB, including the `.9.360` syslog-shaped
block and three legacy `nmslocal` flags. Naming them honestly is the point:
nothing now claims more certainty than the MIBs support.

### Follow-up: a real gap the MIB map exposed on the live GPON OLT

Applying the same treatment to the OLT files turned up a genuine production
bug. `pon_count_registered_onts` reads `pon.portCountOnu` without a guard, and
`gp3600.yml` never declared it, so on every GP3600 that module threw
"Oid with name 'pon.portCountOnu' not found". Confirmed live against the GPON
OLT at 172.30.64.15 before the fix, and the error was already in the logs, so
per-PON-port loading had been failing silently on that OLT.

The EPON files answer that name from the EPON tree (`llidSequenceNo`, the
current LLID sequence number on the port). The GPON equivalent is
NMS-GPON-MIB's `gponOltPonPortPortActiveOnuNum`, column 4 of
`gponOltPonPortConfigTable`, with the inactive count in column 5; both are now
declared. After the fix the same call returns real data: 373 registered ONUs
across 16 PON ports.

Everything else in the OLT files checks out — all 172 OIDs across
`gp3600.yml`, `3310c.yml` and `3310b.yml` are defined in BDCOM's MIBs.

`tools/bdcom-oid-coverage.php` is what found it and stays in the repo. It
walks each model's declared modules, reads the OID names they fetch, and
reports any the model never declares. Lookups inside a `try` block are skipped
deliberately: several modules try a PON name and a plain-SFP name and use
whichever exists, so an unresolved name there is intended.

Run across every vendor file, not just BDCOM, it reports gaps in C-Data (8
models), ZTE C-series (5), D-Link DES-30xx/35xx and DGS-1210/3600 (4 between
them) and V-Solution (1). Those are left alone: there is no hardware here to
test them against, and guessing at another vendor's OIDs is how wrong data
gets into a monitoring system. Worth picking up when one of those devices is
reachable.

### Follow-up: MAC lookup on BDCOM switches, over SNMP

`fdb` was unwired on every BDCOM switch, so MAC address lookup simply did not
work on them. The reason was never that the data was unavailable — it was that
BDCOM's only FDB module drives the console, and the S5612 accepts a telnet
connection then closes it with no prompt, blocking a worker for 90+ seconds.

`\SwitcherCore\Modules\BDcom\Switches\FdbDot1Bridge` reads it over SNMP
instead, from the standard Q-BRIDGE table the general
`General\Switches\FdbDot1Bridge` already walks. It resolves interfaces through
the model's own `interfaces_list` module rather than walking again, so it
agrees with every other BDCOM module about what a port is called. The FDB port
column carries an ifIndex, which that list calls `xid`; `id` and port name are
accepted too, since API filters use those.

`fdb` is now wired on all 16 switch entries. The console module stays unused
and its warning comment stays with it.

**Verification is config-only, by instruction.** No live-device testing is
done on this project. What was checked: the class loads with no abstract
methods left, `dot1q.FdbPort` and `dot1q.FdbStatus` resolve for a switch
model, the coverage tool reports no model missing a name its modules read
unguarded, and the detection, key, module and family-OID suites all pass.

What that does *not* cover, stated plainly: nobody has called this module
against a switch since it was wired. Earlier in the same session, before the
no-live-testing instruction, a probe did confirm the S5612 answers both
Q-BRIDGE columns with real learned entries and that the port column matches
the interface list's `xid` — that is the evidence the design rests on, and it
predates the instruction. The first real call will be a user's.

### Follow-up: traps checked against the MIBs' own notification definitions

The trap listener matches on a notification's OID and drops anything it does
not recognise, so a trap the config never declares is a trap nobody ever sees.
`tools/bdcom-trap-audit.py` resolves every OID in `configs/traps/bdcom.yml`
against the MIB definitions and says which are real NOTIFICATION-TYPE objects.

The MIBs define 54 BDCOM notifications. The config declared 11, of which only
5 pointed at a defined notification. Two gaps were worth closing:

**Wrong OID.** `OnuSfpParameterAlarm` points at `.10.3.6.3`, but the MIB puts
`gponOnuSfpParameterAlarmNotification` at `.10.3.6.2` and defines nothing at
`.3` — while the neighbouring `OnuStatusChangeNotification` matches its own
notification exactly. That reads as an off-by-one. The MIB-correct OID is
declared alongside rather than replacing the old line: if some firmware really
emits `.3`, replacing it would break a path that works today, and declaring
both costs nothing.

**Missing notifications.** Six were added, chosen for the device classes this
deployment runs rather than for completeness: ONU dying gasp (the earliest
signal a subscriber has lost power), the OLT's own PON SFP alarm, illegal ONU
registration, ONU interface status, and the chassis-level will-reboot and
hardware-failure notifications.

Four config entries point at branches the MIBs define nothing under at all
(`.10.3.10.2`, `.10.3.10.4`, `.10.4.4.4`, `.10.10.2.3`). They are left exactly
as they are. The devices may well implement notifications BDCOM never
published — undocumented OIDs that answer have already turned up on the switch
— and deleting a line that might be someone's working alarm, with no trap ever
logged here to compare against, would be guessing.

**Switch models now read the trap file at all.** Only the OLT entries declared
`traps: ./traps/bdcom.yml`, so a BDCOM switch resolved 2 traps (the global
ones) and dropped its own fan, power, authentication, reboot and
hardware-failure notifications — all of them nmsMgmt-level and as applicable
to a switch as to an OLT. All 16 switch entries now declare it, taking them
from 2 traps to 20.

None of this is verified against hardware: no device testing is done on this
project, and the trap log here is empty, so there was nothing to compare
against. What is verified is that every declared OID is the address the MIB
assigns that notification, and that all 20 resolve for every BDCOM model.
