"""MikroTik RouterOS profile (Plan 19), from MikroTik's own MIKROTIK-MIB (revision 2026-07-07), supplied by the operator
and kept in MIKROTIK_MIBS/. Every numeric OID is re-derived from that file by tests/test_mikrotik_profile.py, with its
ACCESS checked read-only and its shape (scalar GET or bounded column walk) checked against the MIB.

Seeded as a DRAFT, like every vendor-private profile: the MIB says what each object means, not what a given RouterOS
release answers, so activation waits for a fixture and an operator's hardware sign-off.

Left out on purpose: mtxrSystemReboot and mtxrUSBPowerReset (read-write actions), wireless, hotspot, GPS, LTE and
scripts (not network-operations data), and per-interface driver statistics (a second copy of the interface counters
the standard profile already reads).

Units follow the MIB's textual conventions and are applied by `scale`: Voltage, Temperature and Power are tenths
(DISPLAY-HINT d-1); the optical table uses GDiv100, GDiv1000 and IDiv1000.
"""
from __future__ import annotations

from dataclasses import dataclass

MIB_DIRECTORY = "MIKROTIK_MIBS"
MIB_FILE = "MIKROTIK-MIB.mib"
PROFILE_NAME = "mikrotik_routeros"
VENDOR_SLUG = "mikrotik"
FAMILY_SLUG = "mikrotik-routeros"

QUEUE_ROWS = 2048      # simple queues (Plan 19's simple_queues bound)
NEIGHBOUR_ROWS = 256
OPTICAL_ROWS = 128     # SFP/SFP+ cages on one router
WALK_TIMEOUT_MS = 8000
GET_TIMEOUT_MS = 3000

_ROOT = "1.3.6.1.4.1.14988.1.1"  # mtXRouterOs


@dataclass(frozen=True)
class MikrotikDefinition:
    logical_name: str
    mib_object: str
    numeric_oid: str
    strategy: str
    max_rows: int | None = None
    timeout_ms: int | None = None
    unit: str | None = None
    divisor: int = 1   # from the object's textual convention


def _get(name: str, obj: str, oid: str, unit: str | None = None, divisor: int = 1) -> MikrotikDefinition:
    return MikrotikDefinition(name, obj, f"{_ROOT}.{oid}", "get", unit=unit, divisor=divisor)


def _walk(name: str, obj: str, oid: str, rows: int, unit: str | None = None, divisor: int = 1) -> MikrotikDefinition:
    return MikrotikDefinition(name, obj, f"{_ROOT}.{oid}", "walk", rows, WALK_TIMEOUT_MS, unit, divisor)


DEFINITIONS: list[MikrotikDefinition] = [
    # System identity (mtxrSystem 7)
    _get("mikrotik.system.serial_number", "mtxrSerialNumber", "7.3"),
    _get("mikrotik.system.firmware_version", "mtxrFirmwareVersion", "7.4"),
    _get("mikrotik.system.board_name", "mtxrBoardName", "7.9"),
    _get("mikrotik.license.software_id", "mtxrLicSoftwareId", "4.1"),
    _get("mikrotik.license.version", "mtxrLicVersion", "4.4"),
    # Health (mtxrHealth 3): tenths per the Voltage/Temperature/Power conventions
    _get("mikrotik.health.voltage", "mtxrHlVoltage", "3.8", "V", 10),
    _get("mikrotik.health.temperature", "mtxrHlTemperature", "3.10", "°C", 10),
    _get("mikrotik.health.cpu_temperature", "mtxrHlCpuTemperature", "3.6", "°C", 10),
    _get("mikrotik.health.board_temperature", "mtxrHlBoardTemperature", "3.7", "°C", 10),
    _get("mikrotik.health.processor_temperature", "mtxrHlProcessorTemperature", "3.11", "°C", 10),
    _get("mikrotik.health.power", "mtxrHlPower", "3.12", "W", 10),
    _get("mikrotik.health.processor_frequency", "mtxrHlProcessorFrequency", "3.14", "MHz"),
    _get("mikrotik.health.power_supply_ok", "mtxrHlPowerSupplyState", "3.15"),
    _get("mikrotik.health.backup_power_supply_ok", "mtxrHlBackupPowerSupplyState", "3.16"),
    # DHCP (mtxrDHCP 6)
    _get("mikrotik.dhcp.lease_count", "mtxrDHCPLeaseCount", "6.1", "leases"),
    # Simple queues (mtxrQueueSimpleTable, mtxrQueues 2.1)
    _walk("mikrotik.queue.name", "mtxrQueueSimpleName", "2.1.1.2", QUEUE_ROWS),
    _walk("mikrotik.queue.bytes_in", "mtxrQueueSimpleBytesIn", "2.1.1.8", QUEUE_ROWS, "bytes"),
    _walk("mikrotik.queue.bytes_out", "mtxrQueueSimpleBytesOut", "2.1.1.9", QUEUE_ROWS, "bytes"),
    _walk("mikrotik.queue.dropped_in", "mtxrQueueSimpleDroppedIn", "2.1.1.14", QUEUE_ROWS, "packets"),
    _walk("mikrotik.queue.dropped_out", "mtxrQueueSimpleDroppedOut", "2.1.1.15", QUEUE_ROWS, "packets"),
    # Neighbours (MNDP/CDP/LLDP discovery, mtxrNeighbor 11.1)
    _walk("mikrotik.neighbor.ip_address", "mtxrNeighborIpAddress", "11.1.1.2", NEIGHBOUR_ROWS),
    _walk("mikrotik.neighbor.mac_address", "mtxrNeighborMacAddress", "11.1.1.3", NEIGHBOUR_ROWS),
    _walk("mikrotik.neighbor.identity", "mtxrNeighborIdentity", "11.1.1.6", NEIGHBOUR_ROWS),
    _walk("mikrotik.neighbor.platform", "mtxrNeighborPlatform", "11.1.1.5", NEIGHBOUR_ROWS),
    _walk("mikrotik.neighbor.interface", "mtxrNeighborInterfaceID", "11.1.1.8", NEIGHBOUR_ROWS),
    # SFP optics (mtxrOpticalTable)
    _walk("mikrotik.optical.name", "mtxrOpticalName", "19.1.1.2", OPTICAL_ROWS),
    _walk("mikrotik.optical.rx_loss", "mtxrOpticalRxLoss", "19.1.1.3", OPTICAL_ROWS),
    _walk("mikrotik.optical.temperature", "mtxrOpticalTemperature", "19.1.1.6", OPTICAL_ROWS, "°C"),
    _walk("mikrotik.optical.supply_voltage", "mtxrOpticalSupplyVoltage", "19.1.1.7", OPTICAL_ROWS, "V", 1000),
    _walk("mikrotik.optical.tx_bias", "mtxrOpticalTxBiasCurrent", "19.1.1.8", OPTICAL_ROWS, "mA"),
    _walk("mikrotik.optical.tx_power", "mtxrOpticalTxPower", "19.1.1.9", OPTICAL_ROWS, "dBm", 1000),
    _walk("mikrotik.optical.rx_power", "mtxrOpticalRxPower", "19.1.1.10", OPTICAL_ROWS, "dBm", 1000),
]

BY_NAME = {d.logical_name: d for d in DEFINITIONS}


def scale(logical_name: str, raw: int | float | None) -> float | int | None:
    """A raw reading in the unit the MIB's textual convention gives (tenths, hundredths or thousandths divided out)."""
    if raw is None or isinstance(raw, bool):
        return None
    divisor = BY_NAME[logical_name].divisor
    return raw if divisor == 1 else round(raw / divisor, 3)
