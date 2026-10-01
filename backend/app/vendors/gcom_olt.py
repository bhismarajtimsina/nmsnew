"""GCOM EL5610 OLT logic for Plan 17 that does not depend on an unverified OID: the ONU index, optical values and
status. Ported from legacy switcher-core (`Modules/GCOM/GCOMAbstractModule.php`, `OntOpticalInfo.php`,
`OntReasons.php`) and its value map (`configs/oids/gcom/el5610.yml`), read from `origin/main` without merging it.

There is no GCOM polling profile: the repository has no GCOM MIB (K-25).

Kept visible, not guessed away:
- Legacy's numeric id (1000000 + 10000 * slot + 1000 * port + onu) is not unique: slot 0 port 10 and slot 1 port 0
  get the same id, and its own decoder reads 1012005 back as 0/12:5. It is kept only for matching imported rows; the
  identity here is the (slot, port, onu) triple.
- The optical values are strings legacy casts to float with no scaling; no MIB or fixture says their unit
  (GCOM_OPTICAL_UNITS_CONFIRMED).

Not verified against a device.
"""
from __future__ import annotations

from dataclasses import dataclass

from app.vendors.common import parse_number

MAX_ONUS_PER_PON = 64  # EPON
GCOM_OPTICAL_UNITS_CONFIRMED = False


@dataclass(frozen=True)
class Onu:
    slot: int
    port: int
    onu_id: int

    @property
    def name(self) -> str:
        return f"{self.slot}/{self.port}:{self.onu_id}"


def onu_from_oid(oid: str, column_root: str) -> Onu | None:
    """ONU tables are indexed `<slot>.<port>.<onu>` (legacy getOnuXidByOid). A row that does not fit is refused."""
    prefix = column_root + "."
    if not oid.startswith(prefix):
        return None
    parts = oid[len(prefix):].split(".")
    if len(parts) != 3 or not all(p.isdigit() for p in parts):
        return None
    slot, port, onu = (int(p) for p in parts)
    if port < 1 or not 1 <= onu <= MAX_ONUS_PER_PON:
        return None
    return Onu(slot, port, onu)


def legacy_numeric_id(onu: Onu) -> int:
    return 1_000_000 + 10_000 * onu.slot + 1000 * onu.port + onu.onu_id


def scale_optical(field: str, raw: str | float | None) -> float | None:
    """rx, tx, temp, voltage: the string as a number, like legacy. Empty or unparsable is no reading (legacy's
    `(float)` would show 0)."""
    if field not in ("rx", "tx", "temp", "voltage"):
        raise ValueError(f"unknown optical field {field!r}")
    return parse_number(raw)


def scale_distance(raw: str | int | None) -> int | None:
    value = parse_number(raw)
    return None if value is None else int(value)


STATUS = {0: ("Down", "offline"), 1: ("Up", "online")}


def normalize_status(code: int | None) -> tuple[str, str] | None:
    """(label, status). GCOM reports no down reason over SNMP; an offline ONU's reason is unknown."""
    if code is None:
        return None
    return STATUS.get(code, (f"Code {code}", "unknown"))


def last_registration(raw: str | None) -> str | None:
    """Legacy shows `-` as "never"."""
    if raw is None or raw.strip() in ("", "-"):
        return None
    return raw.strip()
