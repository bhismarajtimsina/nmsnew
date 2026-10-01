"""Huawei OLT (SmartAX) logic for Plan 15 that does not depend on an unverified OID: PON and ONT index parsing, optical
value scaling, and down-reason normalization. Ported from the legacy switcher-core code
(`Modules/HuaweiOLT/HuaweiOLTAbstractModule.php`, `OntOpticalInfo.php`, `OntReasons.php`) and its value maps
(`configs/oids/huawei/smartax.yml`), read from `origin/main` without merging it.

There is deliberately no Huawei polling profile yet. The repository has no Huawei MIB, so no Huawei OID can be
re-derived offline the way the BDCOM ones are, and the safety policy does not let an unchecked vendor-private OID into
a profile. Adding Huawei's MIBs (HUAWEI-XPON-MIB and its imports) or operator-captured fixtures unblocks it.

Two legacy inconsistencies are kept visible rather than guessed away:
- EPON ONT rx/tx power: the legacy YAML comment says `(value - 10000) / 100`, the legacy code computes `value / 100`.
  The code is what production displays today, so it is the parity baseline here (`EPON_ONT_POWER_FORMULA_CONFIRMED`
  stays False until a fixture settles it).
- Down cause 18: the GPON `lastDownCause` map says Unknown, the EPON one DeactivatedByRing. Each table keeps its own.

Not verified against a device.
"""
from __future__ import annotations

import re
from dataclasses import dataclass

# --- PON port names and ONT indexes ---------------------------------------------------------------------------------

_PON_NAME = re.compile(r"^(EPON|GPON) ([0-9]{1,2})/([0-9]{1,2})/([0-9]{1,2})$")
_ONT_NAME = re.compile(r"^(EPON|GPON) ([0-9]{1,2})/([0-9]{1,2})/([0-9]{1,2}):([0-9]{1,3})$")
MAX_ONTS_PER_PON = {"gpon": 128, "epon": 64}


@dataclass(frozen=True)
class PonPort:
    technology: str  # "gpon" | "epon"
    frame: int
    slot: int
    port: int

    @property
    def name(self) -> str:
        return f"{self.technology.upper()} {self.frame}/{self.slot}/{self.port}"


@dataclass(frozen=True)
class Ont:
    pon: PonPort
    ont_id: int

    @property
    def name(self) -> str:
        return f"{self.pon.name}:{self.ont_id}"


def parse_pon_name(name: str) -> PonPort | None:
    """`GPON 0/1/7` -> PonPort. The ifName is how legacy recognises a PON port; anything else (ethernet ports, VLANIFs)
    is not one."""
    match = _PON_NAME.match(name.strip())
    if not match:
        return None
    tech, frame, slot, port = match.groups()
    return PonPort(tech.lower(), int(frame), int(slot), int(port))


def parse_ont_name(name: str) -> Ont | None:
    match = _ONT_NAME.match(name.strip())
    if not match:
        return None
    tech, frame, slot, port, ont = match.groups()
    return Ont(PonPort(tech.lower(), int(frame), int(slot), int(port)), int(ont))


def ont_from_oid(oid: str, column_root: str, pon_by_ifindex: dict[int, PonPort]) -> Ont | None:
    """Huawei ONT tables are indexed `<PON ifIndex>.<ONT id>` (legacy `findIfaceByOid`). The PON comes from the
    ifIndex -> ifName map read from the same OLT, so the board, slot and port are never guessed from the ifIndex number
    itself (its encoding differs between GPON and EPON boards). Returns None for a row that does not fit."""
    prefix = column_root + "."
    if not oid.startswith(prefix):
        return None
    parts = oid[len(prefix):].split(".")
    if len(parts) != 2 or not all(p.isdigit() for p in parts):
        return None
    pon = pon_by_ifindex.get(int(parts[0]))
    ont_id = int(parts[1])
    if pon is None or not 0 <= ont_id < MAX_ONTS_PER_PON[pon.technology]:
        return None
    return Ont(pon, ont_id)


def legacy_numeric_id(item: PonPort | Ont) -> int:
    """The id legacy stores for a PON port or ONT (`getIdByName`), kept so imported rows can be matched: GPON 0/1/7 ->
    200107, GPON 0/1/7:1 -> 200107001."""
    pon = item.pon if isinstance(item, Ont) else item
    base = 200000 + 10000 * pon.frame + 100 * pon.slot + pon.port
    return base * 1000 + item.ont_id if isinstance(item, Ont) else base


# --- Optical values -------------------------------------------------------------------------------------------------

EPON_ONT_POWER_FORMULA_CONFIRMED = False


def scale_optical(field: str, raw: int | None) -> float | None:
    """A raw Huawei ONT optical reading in display units, exactly as legacy `OntOpticalInfo` computes it (the same for
    GPON and EPON), with legacy's own "no reading" rules: anything over 100 (Huawei's 2147483647 "invalid" included)
    is no reading, and a receive power at or below -50 dBm is no reading.

    fields: rx, tx (dBm, value / 100), olt_rx (dBm, value / 100 - 100), temp (degC), voltage (V, value / 1000)."""
    if raw is None or isinstance(raw, bool):
        return None
    if field in ("rx", "tx"):
        value = round(raw / 100, 2)
    elif field == "olt_rx":
        value = round(raw / 100 - 100, 2)
    elif field == "temp":
        value = round(float(raw), 2)
    elif field == "voltage":
        value = round(raw / 1000, 2)
    else:
        raise ValueError(f"unknown optical field {field!r}")
    if value > 100:
        return None
    if field in ("rx", "olt_rx") and value <= -50:
        return None
    return value


def scale_distance(raw: int | None) -> int | None:
    """Legacy treats -1 as "no distance"."""
    return None if raw is None or raw == -1 else int(raw)


# --- Down reasons ---------------------------------------------------------------------------------------------------

# The common alarm reasons every vendor's down cause maps to (Plan 15). Kept short on purpose: an operator acts on
# these, not on the vendor's twenty variants.
COMMON_REASONS = (
    "los",             # fibre / optical signal lost
    "power_off",       # dying gasp: the ONT lost power
    "loki",            # loss of key synchronisation
    "auth_fail",       # registration / authentication refused
    "signal_failure",  # frame, PLOAM, acknowledge or signal failures on the link
    "admin_action",    # deactivated, reset or re-registered on purpose
    "rogue_ont",
    "deleted",
    "none",            # no error
    "unknown",
)

# Per table, exactly as the legacy value maps word them, then the common reason. -1 is "the query failed".
DOWN_CAUSE_TABLES: dict[str, dict[int, tuple[str, str]]] = {
    "gpon.last_down_cause": {
        1: ("LOS", "los"), 2: ("LOSi", "signal_failure"), 3: ("LOFi", "signal_failure"), 4: ("SFI", "signal_failure"),
        5: ("LOAI", "signal_failure"), 6: ("LOAMI", "signal_failure"), 7: ("DeactivateFail", "admin_action"),
        8: ("Deactivated", "admin_action"), 9: ("Reset", "admin_action"), 10: ("ReRegister", "admin_action"),
        11: ("PopUpFail", "signal_failure"), 13: ("PowerOff", "power_off"), 15: ("LOKI", "loki"),
        18: ("Unknown", "unknown"), -1: ("Unknown", "unknown"),
    },
    "epon.last_down_cause": {
        1: ("LOS", "los"), 2: ("LOSi", "signal_failure"), 3: ("LOFi", "signal_failure"), 4: ("SFI", "signal_failure"),
        5: ("LOAI", "signal_failure"), 6: ("LOAMI", "signal_failure"), 7: ("DeactivateFail", "admin_action"),
        8: ("Deactivated", "admin_action"), 9: ("Reset", "admin_action"), 10: ("ReRegister", "admin_action"),
        11: ("PopUpFail", "signal_failure"), 13: ("PowerOff", "power_off"), 15: ("LOKI", "loki"),
        18: ("DeactivatedByRing", "admin_action"), 30: ("OpticalShutDown", "admin_action"),
        31: ("Reset(over command)", "admin_action"), 32: ("Reset(over reset btn)", "admin_action"),
        33: ("Reset(by software)", "admin_action"), 34: ("Deactivated(broadcast attack)", "admin_action"),
        37: ("RogueOntDetectedByItself", "rogue_ont"), -1: ("Unknown", "unknown"),
    },
    # The registration history tables (regTable.downCause), the same codes for GPON and EPON.
    "registration_history.down_cause": {
        0: ("Deleted", "deleted"), 1: ("LinkedDown", "los"), 2: ("LOSi", "signal_failure"), 3: ("LOFi", "signal_failure"),
        4: ("SFI", "signal_failure"), 5: ("LOAI", "signal_failure"), 6: ("LOAMI", "signal_failure"),
        7: ("DisableFail", "admin_action"), 8: ("Deactivated", "admin_action"), 9: ("Reset", "admin_action"),
        10: ("ReRegister", "admin_action"), 11: ("PopUpFail", "signal_failure"), 12: ("AuthFail", "auth_fail"),
        13: ("PowerDown", "power_off"), 14: ("Reserved", "unknown"), 15: ("Loki", "loki"), 255: ("NoError", "none"),
        -1: ("Invalid", "unknown"),
    },
}


@dataclass(frozen=True)
class DownReason:
    code: int
    label: str   # Huawei's own word, as legacy shows it
    reason: str  # one of COMMON_REASONS


def normalize_down_cause(table: str, code: int | None) -> DownReason | None:
    """A raw down-cause code to Huawei's label and the common reason. An unlisted code is "unknown", never dropped, so a
    firmware that adds codes still raises an alarm with the raw code visible."""
    if code is None:
        return None
    label, reason = DOWN_CAUSE_TABLES[table].get(code, (f"Code {code}", "unknown"))
    return DownReason(code, label, reason)
