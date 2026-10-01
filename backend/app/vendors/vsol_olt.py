"""V-Solution V1600 OLT logic for Plan 17 that does not depend on an unverified OID: interface names, the ONU index,
the EPON optical strings, and status and reason normalization. Ported from legacy switcher-core
(`Modules/VsolOlts/VsolOltsAbstractModule.php`, `OntOpticalInfo.php`, `GPONV1600/*`) and its value maps
(`configs/oids/vsolution/v1600d.yml`, `v1600g.yml`), read from `origin/main` without merging it.

There is no V-Solution polling profile: the repository has no V-Solution MIB (K-25).

Kept visible, not guessed away:
- The ONU tables are indexed `<port>.<onu>`, with no slot. Legacy reads every slot as 0; a name on another slot is
  refused here, since its rows could not be told apart from slot 0's.
- V1600G's status map has no code 1 (between Logging and SyncMib); an unlisted code is unknown, never online.
- Legacy V1600G value maps that look wrong are recorded for Plans 26 and 38, not ported: `ont.uni.status` is
  {0: Up, 1: Down} where V1600D has {0: Down, 1: Up}, and `ont.uni.adminStatus` lists link speeds.

Not verified against a device.
"""
from __future__ import annotations

import re
from dataclasses import dataclass

from app.vendors.common import DownReason, lookup_reason, parse_number

_PON = re.compile(r"^(EPON|GPON)([0-9]{1,3})/([0-9]{1,3})$")
_ONU = re.compile(r"^(EPON|GPON)([0-9]{1,3})/([0-9]{1,3}):([0-9]{1,3})$")
_ONU_SHORT = re.compile(r"^(EPON|GPON)([0-9]{1,3})ONU([0-9]{1,3})$")  # slot 0 implied
MAX_ONUS_PER_PON = {"gpon": 128, "epon": 64}


@dataclass(frozen=True)
class PonPort:
    technology: str
    slot: int
    port: int


@dataclass(frozen=True)
class Onu:
    pon: PonPort
    onu_id: int

    @property
    def snmp_index(self) -> str:
        return f"{self.pon.port}.{self.onu_id}"


def parse_if_name(raw: str) -> PonPort | Onu | None:
    """The first word of an ifName (legacy hex-decodes it and splits on spaces): `EPON0/3`, `EPON0/3:12`, `EPON3ONU12`
    and the GPON forms. Anything on a slot other than 0, ONU 0, or an ONU past the PON size is refused."""
    words = raw.strip().split(" ")
    name = words[0] if words else ""
    if m := _PON.match(name):
        tech, slot, port = m.group(1).lower(), int(m.group(2)), int(m.group(3))
        return PonPort(tech, slot, port) if slot == 0 else None
    if m := _ONU.match(name):
        tech, slot, port, onu = m.group(1).lower(), int(m.group(2)), int(m.group(3)), int(m.group(4))
    elif m := _ONU_SHORT.match(name):
        tech, slot, port, onu = m.group(1).lower(), 0, int(m.group(2)), int(m.group(3))
    else:
        return None
    if slot != 0 or not 1 <= onu <= MAX_ONUS_PER_PON[tech]:
        return None
    return Onu(PonPort(tech, slot, port), onu)


def onu_from_oid(oid: str, column_root: str, onus_by_index: dict[str, Onu]) -> Onu | None:
    """An ONU table row `<column>.<port>.<onu>` -> the ONU named in the OLT's own ifName table (`onus_by_index`, keyed
    by Onu.snmp_index). A row for an ONU the OLT does not name is refused."""
    prefix = column_root + "."
    if not oid.startswith(prefix):
        return None
    rest = oid[len(prefix):]
    parts = rest.split(".")
    if len(parts) != 2 or not all(p.isdigit() for p in parts):
        return None
    return onus_by_index.get(rest)


def legacy_numeric_id(item: PonPort | Onu) -> int:
    """EPON0/6 -> 10006000, EPON0/6:4 -> 10006004 (legacy's own examples)."""
    pon = item.pon if isinstance(item, Onu) else item
    base = 10_000_000 + pon.slot * 100_000 + pon.port * 1000
    return base + item.onu_id if isinstance(item, Onu) else base


# --- optical --------------------------------------------------------------------------------------------------------

_POWER = re.compile(r"^(.*?) mW \((.*?) dBm\)$")


def epon_power(raw: str | None) -> float | None:
    """V1600D reports power as `0.0123 mW (-19.10 dBm)`; the dBm figure is the reading. Any other shape is no
    reading."""
    if raw is None:
        return None
    match = _POWER.match(raw.strip())
    return parse_number(match.group(2)) if match else None


def _strip_unit(raw: str | None, unit: str) -> float | None:
    if raw is None or not raw.strip():
        return None
    return parse_number(raw.strip().removesuffix(unit))


def scale_optical(technology: str, field: str, raw: str | int | float | None) -> float | None:
    """fields: rx, tx (dBm), temp (degC, `45.2 C`), voltage (V, `3.3 V`). EPON power is the dBm in the V1600D string;
    GPON power is shown as read, like legacy."""
    if technology not in MAX_ONUS_PER_PON:
        raise ValueError(f"unknown technology {technology!r}")
    if field in ("rx", "tx"):
        return epon_power(raw if raw is None else str(raw)) if technology == "epon" else parse_number(raw)
    if field == "temp":
        return _strip_unit(None if raw is None else str(raw), "C")
    if field == "voltage":
        return _strip_unit(None if raw is None else str(raw), "V")
    raise ValueError(f"unknown optical field {field!r}")


def scale_distance(raw: int | str | None) -> int | None:
    """Metres; legacy treats 0 as no distance."""
    value = parse_number(raw)
    return None if value is None or int(value) == 0 else int(value)


# --- status and reasons ---------------------------------------------------------------------------------------------

STATUS_TABLES: dict[str, dict[int, tuple[str, str, str | None]]] = {
    # label, status, reason when offline
    "v1600d": {0: ("Offline", "offline", "unknown"), 1: ("Online", "online", None)},
    "v1600g": {
        0: ("Logging", "registering", None), 2: ("SyncMib", "registering", None), 3: ("Online", "online", None),
        4: ("PowerOff", "offline", "power_off"), 5: ("AuthFail", "offline", "auth_fail"),
        6: ("Offline", "offline", "unknown"), 7: ("Disabled", "offline", "admin_action"),
        8: ("ConfigFail", "offline", "unknown"),
    },
}

DOWN_REASON_TABLES: dict[str, dict[int, tuple[str, str]]] = {
    "v1600d.last_dereg_reason": {0: ("LOS", "los"), 1: ("PowerOff", "power_off")},
}


@dataclass(frozen=True)
class OnuStatus:
    code: int
    label: str
    status: str
    reason: str | None


def normalize_status(family: str, code: int | None) -> OnuStatus | None:
    if code is None:
        return None
    label, status, reason = STATUS_TABLES[family].get(code, (f"Code {code}", "unknown", "unknown"))
    return OnuStatus(code, label, status, reason)


def normalize_down_reason(table: str, code: int | None) -> DownReason | None:
    return lookup_reason(DOWN_REASON_TABLES, table, code)
