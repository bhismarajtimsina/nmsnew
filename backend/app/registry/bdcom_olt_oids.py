"""The BDCOM OLT polling profiles (Plan 14): one for GPON (GP3600 family) and one for EPON (P36xx / 3310 families).

Built from the legacy switcher-core definitions (`configs/oids/bdcom/gp3600.yml` and `3310c.yml`) and kept only where
BDCOM's own MIB agrees: every numeric OID is re-derived each test run from the MIB file named next to it
(tests/test_bdcom_olt_profiles.py), its ACCESS must be read-only, a scalar is read with `get` and a table column with a
bounded `walk`. GPON objects live in `bdcom/NMS-GPON-MIB`; EPON and the shared system objects in `NMS_BDCOM_MIBS/`.

Deliberately left out:
- Every writable object: ONU reboot, enable, activate, description, bind type, IP, bandwidth (PIR/CIR/FIR), UNI admin
  state, speed and VLAN settings, ONU profiles, config save. Actions are Plan 26/38's, never a polling profile's.
- Legacy names the MIB contradicts. GPON `ont.action.delete` is `gponOnuConfigActicate` (activate), `ont.action.disable`
  is `gponOnuConfigEnable` and `profile.onu.flow.uni_type` is a T-CONT bandwidth-profile id; EPON `pon.portCountOnu`
  is `llidSequenceNo`, not an ONU count. Plans 26 and 38 must not reuse the legacy action names.
- Legacy names with no MIB object in the repository (GPON UNI counters, `ont.ports.total*`, the GPON FDB search
  objects, `ont.aliveTime`, the LLID last-registration times, `resources.fanStatus` and others): they wait for a
  fixture.
- Per-UNI tables. An OLT with thousands of ONUs has several times as many UNIs, past the 5000-row walk cap; they need a
  per-ONU bounded read, not a table walk.

ONU identity is the serial (GPON, `gponOnuStatusOnuSn`) or the MAC (EPON, `onuID`), never an index: indexes change when
ONUs are re-registered (Plan 14). ONU_IDENTITY names the definition that carries it per technology.

Both profiles are seeded as drafts and never activated here: vendor-private OIDs need a recorded fixture per family
and an operator's hardware sign-off first (safety policy sections 3 and 4). Not verified against a device.
"""
from __future__ import annotations

from dataclasses import dataclass

GPON_PROFILE = "bdcom_olt_gpon"
EPON_PROFILE = "bdcom_olt_epon"
VENDOR_SLUG = "bdcom"
FAMILY_SLUG = "bdcom-olt"
GPON_MIB_DIR = "bdcom"
COMMON_MIB_DIR = "NMS_BDCOM_MIBS"


@dataclass(frozen=True)
class OltDefinition:
    logical_name: str
    mib_dir: str
    mib_file: str
    mib_object: str
    numeric_oid: str
    strategy: str  # "get" for a scalar, "walk" for a table column
    max_rows: int | None = None
    timeout_ms: int | None = None
    unit: str | None = None


CARD_ROWS = 16
PON_ROWS = 64        # PON ports on one OLT
ONU_ROWS = 4096      # ONUs on one OLT; below the 5000-row hard cap
CARD_TIMEOUT_MS = 8000
ONU_TIMEOUT_MS = 15000
GET_TIMEOUT_MS = 3000


def _common(prefix: str) -> list[OltDefinition]:
    d = COMMON_MIB_DIR
    return [
        OltDefinition(f"{prefix}.system.serial_number", d, "NMS-SNMP.my", "neSerialNo", "1.3.6.1.4.1.3320.9.225.1.2", "get"),
        OltDefinition(f"{prefix}.system.hardware_version", d, "NMS-SNMP.my", "neHardwareversion", "1.3.6.1.4.1.3320.9.225.1.7", "get"),
        OltDefinition(f"{prefix}.system.software_version", d, "NMS-SNMP.my", "neSoftwareversion", "1.3.6.1.4.1.3320.9.225.1.8", "get"),
        OltDefinition(f"{prefix}.system.mac_address", d, "NMS-SNMP.my", "neMacAddress", "1.3.6.1.4.1.3320.9.225.1.9", "get"),
        OltDefinition(f"{prefix}.system.power_status", d, "NMS-POWER-MIB.my", "powerStatus", "1.3.6.1.4.1.3320.9.189.1", "get"),
        OltDefinition(f"{prefix}.resources.card_cpu_utilization", d, "NMS-CHASSIS-MIB.my", "nmscardCPUUtilization",
                      "1.3.6.1.4.1.3320.3.6.10.1.11", "walk", CARD_ROWS, CARD_TIMEOUT_MS, "%"),
        OltDefinition(f"{prefix}.resources.card_memory_utilization", d, "NMS-CHASSIS-MIB.my", "nmscardMEMUtilization",
                      "1.3.6.1.4.1.3320.3.6.10.1.12", "walk", CARD_ROWS, CARD_TIMEOUT_MS, "%"),
        OltDefinition(f"{prefix}.resources.card_temperature", d, "NMS-CHASSIS-MIB.my", "nmscardTemperature",
                      "1.3.6.1.4.1.3320.3.6.10.1.13", "walk", CARD_ROWS, CARD_TIMEOUT_MS, "degC"),
    ]


def _gpon(name: str, obj: str, oid: str, rows: int, timeout: int, unit: str | None = None) -> OltDefinition:
    return OltDefinition(name, GPON_MIB_DIR, "NMS-GPON-MIB", obj, oid, "walk", rows, timeout, unit)


def _epon(name: str, file: str, obj: str, oid: str, rows: int, timeout: int, unit: str | None = None) -> OltDefinition:
    return OltDefinition(name, COMMON_MIB_DIR, file, obj, oid, "walk", rows, timeout, unit)


GPON_DEFINITIONS: list[OltDefinition] = _common("bdcom_gpon") + [
    # PON ports.
    _gpon("bdcom_gpon.pon.active_onus", "gponOltPonPortPortActiveOnuNum", "1.3.6.1.4.1.3320.10.2.1.1.4", PON_ROWS, CARD_TIMEOUT_MS),
    _gpon("bdcom_gpon.pon.inactive_onus", "gponOltPonPortPortInactiveOnuNum", "1.3.6.1.4.1.3320.10.2.1.1.5", PON_ROWS, CARD_TIMEOUT_MS),
    _gpon("bdcom_gpon.pon.optical_temperature", "gponOltPonPortOpticalParameterTemperature", "1.3.6.1.4.1.3320.10.2.2.1.2", PON_ROWS, CARD_TIMEOUT_MS),
    _gpon("bdcom_gpon.pon.optical_voltage", "gponOltPonPortOpticalParameterVoltage", "1.3.6.1.4.1.3320.10.2.2.1.3", PON_ROWS, CARD_TIMEOUT_MS),
    _gpon("bdcom_gpon.pon.optical_bias", "gponOltPonPortOpticalParameterCurrent", "1.3.6.1.4.1.3320.10.2.2.1.4", PON_ROWS, CARD_TIMEOUT_MS),
    _gpon("bdcom_gpon.pon.optical_tx_power", "gponOltPonPortOpticalParameterTxPower", "1.3.6.1.4.1.3320.10.2.2.1.5", PON_ROWS, CARD_TIMEOUT_MS),
    # Upstream power the OLT receives from each ONU; per ONU, so ONU-sized.
    _gpon("bdcom_gpon.pon.onu_rx_power_at_olt", "gponOltPonPortOpticalRxPowerRxPower", "1.3.6.1.4.1.3320.10.2.3.1.3", ONU_ROWS, ONU_TIMEOUT_MS, "0.1 dBm"),
    # ONUs. The serial is the ONU's identity.
    _gpon("bdcom_gpon.onu.serial", "gponOnuStatusOnuSn", "1.3.6.1.4.1.3320.10.3.3.1.2", ONU_ROWS, ONU_TIMEOUT_MS),
    _gpon("bdcom_gpon.onu.vendor", "onuVendorID", "1.3.6.1.4.1.3320.10.3.1.1.2", ONU_ROWS, ONU_TIMEOUT_MS),
    _gpon("bdcom_gpon.onu.version", "onuVersion", "1.3.6.1.4.1.3320.10.3.1.1.3", ONU_ROWS, ONU_TIMEOUT_MS),
    _gpon("bdcom_gpon.onu.equipment_id", "onuEquipmentID", "1.3.6.1.4.1.3320.10.3.1.1.9", ONU_ROWS, ONU_TIMEOUT_MS),
    _gpon("bdcom_gpon.onu.image0_version", "onuImageInstance0Version", "1.3.6.1.4.1.3320.10.3.1.1.20", ONU_ROWS, ONU_TIMEOUT_MS),
    _gpon("bdcom_gpon.onu.image1_version", "onuImageInstance1Version", "1.3.6.1.4.1.3320.10.3.1.1.24", ONU_ROWS, ONU_TIMEOUT_MS),
    _gpon("bdcom_gpon.onu.uptime", "onuSysUpTime", "1.3.6.1.4.1.3320.10.3.1.1.19", ONU_ROWS, ONU_TIMEOUT_MS),
    _gpon("bdcom_gpon.onu.distance", "onuDistance", "1.3.6.1.4.1.3320.10.3.1.1.33", ONU_ROWS, ONU_TIMEOUT_MS),
    _gpon("bdcom_gpon.onu.deactivate_reason", "onuDeActiveReason", "1.3.6.1.4.1.3320.10.3.1.1.35", ONU_ROWS, ONU_TIMEOUT_MS),
    # The MIB states no unit for these two; the scale is fixed by a fixture before any range check uses them.
    _gpon("bdcom_gpon.onu.rx_power", "gponOnuOpticalPowerRxPower", "1.3.6.1.4.1.3320.10.3.4.1.2", ONU_ROWS, ONU_TIMEOUT_MS),
    _gpon("bdcom_gpon.onu.tx_power", "gponOnuOpticalPowerTxPower", "1.3.6.1.4.1.3320.10.3.4.1.3", ONU_ROWS, ONU_TIMEOUT_MS),
]

EPON_DEFINITIONS: list[OltDefinition] = _common("bdcom_epon") + [
    # PON ports (the -EXT table is indexed by PON interface).
    _epon("bdcom_epon.pon.link_status", "NMS-EPON-OLT-PON-EXT.my", "linkStatus", "1.3.6.1.4.1.3320.101.107.1.2", PON_ROWS, CARD_TIMEOUT_MS),
    _epon("bdcom_epon.pon.optical_tx_power", "NMS-EPON-OLT-PON-EXT.my", "txPower", "1.3.6.1.4.1.3320.101.107.1.3", PON_ROWS, CARD_TIMEOUT_MS, "0.1 dBm"),
    _epon("bdcom_epon.pon.optical_temperature", "NMS-EPON-OLT-PON-EXT.my", "temperature", "1.3.6.1.4.1.3320.101.107.1.6", PON_ROWS, CARD_TIMEOUT_MS),
    _epon("bdcom_epon.pon.optical_voltage", "NMS-EPON-OLT-PON-EXT.my", "vlotage", "1.3.6.1.4.1.3320.101.107.1.7", PON_ROWS, CARD_TIMEOUT_MS),  # sic
    _epon("bdcom_epon.pon.optical_bias", "NMS-EPON-OLT-PON-EXT.my", "curr", "1.3.6.1.4.1.3320.101.107.1.8", PON_ROWS, CARD_TIMEOUT_MS),
    _epon("bdcom_epon.pon.splitting_ratio", "NMS-EPON-OLT-PON.MIB", "splittingRatio", "1.3.6.1.4.1.3320.101.6.1.1.20", PON_ROWS, CARD_TIMEOUT_MS),
    _epon("bdcom_epon.pon.onu_rx_power_at_olt", "NMS-EPON-OLT-PON-EXT.my", "rxPower", "1.3.6.1.4.1.3320.101.108.1.3", ONU_ROWS, ONU_TIMEOUT_MS, "0.1 dBm"),
    # ONUs. The MAC is the ONU's identity.
    _epon("bdcom_epon.onu.mac", "NMS-EPON-ONU.MIB", "onuID", "1.3.6.1.4.1.3320.101.10.1.1.3", ONU_ROWS, ONU_TIMEOUT_MS),
    _epon("bdcom_epon.onu.vendor", "NMS-EPON-ONU.MIB", "onuVendorID", "1.3.6.1.4.1.3320.101.10.1.1.1", ONU_ROWS, ONU_TIMEOUT_MS),
    _epon("bdcom_epon.onu.model", "NMS-EPON-ONU.MIB", "onuModuleID", "1.3.6.1.4.1.3320.101.10.1.1.2", ONU_ROWS, ONU_TIMEOUT_MS),
    _epon("bdcom_epon.onu.hardware_version", "NMS-EPON-ONU.MIB", "onuHardwareVersion", "1.3.6.1.4.1.3320.101.10.1.1.4", ONU_ROWS, ONU_TIMEOUT_MS),
    _epon("bdcom_epon.onu.firmware_version", "NMS-EPON-ONU.MIB", "onuFirmwareVersion", "1.3.6.1.4.1.3320.101.10.1.1.6", ONU_ROWS, ONU_TIMEOUT_MS),
    _epon("bdcom_epon.onu.status", "NMS-EPON-ONU.MIB", "onuStatus", "1.3.6.1.4.1.3320.101.10.1.1.26", ONU_ROWS, ONU_TIMEOUT_MS),
    _epon("bdcom_epon.onu.distance", "NMS-EPON-ONU.MIB", "onuDistance", "1.3.6.1.4.1.3320.101.10.1.1.27", ONU_ROWS, ONU_TIMEOUT_MS),
    _epon("bdcom_epon.onu.rx_power", "NMS-EPON-ONU.MIB", "opModuleRxPower", "1.3.6.1.4.1.3320.101.10.5.1.5", ONU_ROWS, ONU_TIMEOUT_MS, "0.1 dB"),
    _epon("bdcom_epon.onu.tx_power", "NMS-EPON-ONU.MIB", "opModuleTxPower", "1.3.6.1.4.1.3320.101.10.5.1.6", ONU_ROWS, ONU_TIMEOUT_MS),
    _epon("bdcom_epon.onu.optical_temperature", "NMS-EPON-ONU.MIB", "opModuleTemp", "1.3.6.1.4.1.3320.101.10.5.1.2", ONU_ROWS, ONU_TIMEOUT_MS),
    _epon("bdcom_epon.onu.optical_voltage", "NMS-EPON-ONU.MIB", "opModuleVolt", "1.3.6.1.4.1.3320.101.10.5.1.3", ONU_ROWS, ONU_TIMEOUT_MS),
]

PROFILES: dict[str, list[OltDefinition]] = {GPON_PROFILE: GPON_DEFINITIONS, EPON_PROFILE: EPON_DEFINITIONS}

# One mapping for the GPON/EPON differences (Plan 14): the shared meaning on the left, each technology's definition on
# the right. A meaning one technology does not report maps to None, so callers never assume a reading exists.
NORMALIZED: dict[str, dict[str, str | None]] = {
    "onu.identity": {"gpon": "bdcom_gpon.onu.serial", "epon": "bdcom_epon.onu.mac"},
    "onu.vendor": {"gpon": "bdcom_gpon.onu.vendor", "epon": "bdcom_epon.onu.vendor"},
    "onu.model": {"gpon": "bdcom_gpon.onu.equipment_id", "epon": "bdcom_epon.onu.model"},
    "onu.firmware": {"gpon": "bdcom_gpon.onu.image0_version", "epon": "bdcom_epon.onu.firmware_version"},
    "onu.distance": {"gpon": "bdcom_gpon.onu.distance", "epon": "bdcom_epon.onu.distance"},
    "onu.rx_power": {"gpon": "bdcom_gpon.onu.rx_power", "epon": "bdcom_epon.onu.rx_power"},
    "onu.tx_power": {"gpon": "bdcom_gpon.onu.tx_power", "epon": "bdcom_epon.onu.tx_power"},
    "onu.status": {"gpon": None, "epon": "bdcom_epon.onu.status"},
    "onu.uptime": {"gpon": "bdcom_gpon.onu.uptime", "epon": None},
    "pon.onu_rx_power_at_olt": {"gpon": "bdcom_gpon.pon.onu_rx_power_at_olt", "epon": "bdcom_epon.pon.onu_rx_power_at_olt"},
    "pon.optical_tx_power": {"gpon": "bdcom_gpon.pon.optical_tx_power", "epon": "bdcom_epon.pon.optical_tx_power"},
    "pon.optical_temperature": {"gpon": "bdcom_gpon.pon.optical_temperature", "epon": "bdcom_epon.pon.optical_temperature"},
    "pon.optical_voltage": {"gpon": "bdcom_gpon.pon.optical_voltage", "epon": "bdcom_epon.pon.optical_voltage"},
    "pon.optical_bias": {"gpon": "bdcom_gpon.pon.optical_bias", "epon": "bdcom_epon.pon.optical_bias"},
    "pon.active_onus": {"gpon": "bdcom_gpon.pon.active_onus", "epon": None},
}

# The definition that identifies an ONU, per technology (never an index).
ONU_IDENTITY: dict[str, str] = {tech: name for tech, name in NORMALIZED["onu.identity"].items() if name}
