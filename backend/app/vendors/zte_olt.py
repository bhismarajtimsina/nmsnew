"""ZTE C300 / C600 OLT logic for Plan 16 that does not depend on an unverified OID: PON and ONT names and indexes, the
optical conversions, and offline-reason and phase-state normalization. Ported from the legacy switcher-core code
(`Modules/ZTE/ModuleAbstract.php`, `C300Series/OntOpticalInfo.php`, `C600Series/ModuleAbstract.php`,
`C600Series/OntOpticalInfo.php`) and its value maps (`configs/oids/zte/ZTE-C300_fw*.yml`, `ZTE-C600.yml`), read from
`origin/main` without merging it.

There is deliberately no ZTE polling profile yet: the repository has no ZTE MIB, so no ZTE OID can be re-derived offline
(K-25). Adding ZTE's MIBs or operator-captured fixtures unblocks it.

Where the legacy code is wrong or self-contradictory, this module picks the reading that cannot show a false value and
says so:
- The GPON ONU power formula treats raw values above 30000 as negative (a 16-bit wrap at 30000, not 32767). Raw
  30001-32767 therefore come out as -101 to -95 dBm. Legacy C300 nulls rx at or below -70 dBm and at or above 30 dBm;
  legacy C600 does not, and would show -101 dBm. The C300 window applies to both models here (rx and OLT rx).
- Legacy C600 turns an OLT rx sentinel (-1 or -80 dBm) into 0 dBm, which reads as a strong signal. Here it is no
  reading, for both models.
- The C300 16-port EPON index has two layouts in legacy: the encoder's (3-bit shelf, 5-bit slot) and the decoder's
  (2-bit shelf, 4-bit slot, "temporarily" changed for testing). An index the two would read differently is refused.
- The GPON phase-state codes are off by one between the models (C300 starts at 0, C600 at 1). Each model keeps its own.

Not verified against a device.
"""
from __future__ import annotations

import re
from dataclasses import dataclass
from typing import Mapping

from app.vendors.common import DownReason, lookup_reason

MODELS = ("c300", "c600")

# --- PON port and ONT names -----------------------------------------------------------------------------------------

# C300 writes `gpon-olt_1/2/3` and `gpon-onu_1/2/3:4`; C600 writes `gpon_olt-1/2/3` and `gpon_onu-1/2/3:4`.
_NAME = re.compile(r"^(gpon|epon)[-_](olt|onu)[-_]([0-9])/([0-9]{1,3})/([0-9]{1,3})(?::([0-9]{1,3}))?$")
MAX_ONTS_PER_PON = {"gpon": 128, "epon": 64}


@dataclass(frozen=True)
class PonPort:
    technology: str  # "gpon" | "epon"
    shelf: int
    slot: int
    port: int


@dataclass(frozen=True)
class Ont:
    pon: PonPort
    ont_id: int


def max_onts(technology: str, card_type: str | None = None) -> int:
    """ONTs one PON takes: legacy gives ETTO cards 128 whatever their technology."""
    if card_type and card_type.startswith("ETTO"):
        return 128
    return MAX_ONTS_PER_PON[technology]


def parse_name(name: str) -> PonPort | Ont | None:
    """`gpon-olt_1/2/3` or `gpon_olt-1/2/3` -> PonPort; `gpon-onu_1/2/3:4` -> Ont. An `olt` name with an ONT number,
    an `onu` name without one, ONT 0 or an ONT past the PON's size is not a name this OLT writes."""
    match = _NAME.match(name.strip())
    if not match:
        return None
    tech, kind, shelf, slot, port, ont = match.groups()
    pon = PonPort(tech, int(shelf), int(slot), int(port))
    if kind == "olt":
        return pon if ont is None else None
    if ont is None or not 1 <= int(ont) <= MAX_ONTS_PER_PON[tech]:
        return None
    return Ont(pon, int(ont))


def display_name(item: PonPort | Ont, model: str) -> str:
    pon = item.pon if isinstance(item, Ont) else item
    kind = "onu" if isinstance(item, Ont) else "olt"
    position = f"{pon.shelf}/{pon.slot}/{pon.port}" + (f":{item.ont_id}" if isinstance(item, Ont) else "")
    if model == "c300":
        return f"{pon.technology}-{kind}_{position}"
    if model == "c600":
        return f"{pon.technology}_{kind}-{position}"
    raise ValueError(f"unknown model {model!r}")


def legacy_numeric_id(item: PonPort | Ont) -> int:
    """The id legacy stores for a PON port or ONT: shelf 1, slot 2, port 3 -> 10203000; its ONT 4 -> 10203004."""
    pon = item.pon if isinstance(item, Ont) else item
    base = pon.shelf * 10_000_000 + pon.slot * 100_000 + pon.port * 1000
    return base + item.ont_id if isinstance(item, Ont) else base


# --- C300 ifIndex encoding ------------------------------------------------------------------------------------------

PORT_OFFSET = 1  # every legacy ZTE C-series model sets extra.port_offset: 1


def decode_c300_index(index: str, technology_of: Mapping[tuple[int, int], str]) -> PonPort | Ont | None:
    """A C300 table index -> PON port or ONT. The top nibble of the 32-bit value says the layout:
    1 `<shelf-1:4><slot:8><port:8><0:8>` with the ONT as a second index component; 3 (8-port EPON)
    `<shelf-1:4><slot:5><port-1:3><ont:8><vport:8>`; 9 (16-port EPON) `<shelf-1:3><slot:5><port-1:4><ont:8><0:8>`.
    The technology comes from the card in that shelf and slot (`technology_of`, built from the in-service card list),
    never from the layout, because layout 1 also addresses EPON ports. Returns None for anything that does not fit."""
    parts = index.split(".")
    if not 1 <= len(parts) <= 2 or not all(p.isdigit() for p in parts):
        return None
    value = int(parts[0])
    layout = value >> 28  # anything past 32 bits has no layout below
    second = int(parts[1]) if len(parts) == 2 else None
    if layout == 1:
        if value & 0xFF:
            return None
        shelf, slot, port, ont = ((value >> 24) & 0xF) + PORT_OFFSET, (value >> 16) & 0xFF, (value >> 8) & 0xFF, second or 0
    elif layout in (3, 9) and second is None:
        if layout == 3:
            shelf, slot, port = ((value >> 24) & 0xF) + PORT_OFFSET, (value >> 19) & 0x1F, ((value >> 16) & 0x7) + 1
        else:
            if (value >> 24) & 0xF:  # shelf or slot bits the legacy encoder and decoder read differently
                return None
            shelf, slot, port = PORT_OFFSET, (value >> 20) & 0xF, ((value >> 16) & 0xF) + 1
        ont = (value >> 8) & 0xFF
    else:
        return None
    technology = technology_of.get((shelf, slot))
    if technology not in MAX_ONTS_PER_PON or port < 1:
        return None
    pon = PonPort(technology, shelf, slot, port)
    if ont == 0:
        return pon
    return Ont(pon, ont) if ont <= MAX_ONTS_PER_PON[technology] else None


def encode_c300_index(item: PonPort | Ont, layout: int) -> str:
    """The inverse of decode_c300_index, for building a single-ONT read and for the round-trip test."""
    pon = item.pon if isinstance(item, Ont) else item
    ont = item.ont_id if isinstance(item, Ont) else 0
    shelf = pon.shelf - PORT_OFFSET
    if layout == 1:
        value = (1 << 28) | (shelf << 24) | (pon.slot << 16) | (pon.port << 8)
        return f"{value}.{ont}" if ont else str(value)
    if layout == 3:
        return str((3 << 28) | (shelf << 24) | (pon.slot << 19) | ((pon.port - 1) << 16) | (ont << 8))
    if layout == 9:
        return str((9 << 28) | (shelf << 25) | (pon.slot << 20) | ((pon.port - 1) << 16) | (ont << 8))
    raise ValueError(f"unknown layout {layout}")


# --- C600 indexes ---------------------------------------------------------------------------------------------------

def ont_from_c600_oid(oid: str, column_root: str, pon_by_ifindex: Mapping[int, PonPort], trailing: int = 0) -> Ont | None:
    """C600 ONT tables are indexed `<PON ifIndex>.<ONT id>`, the optical rx/tx/temperature/voltage tables with one more
    component after that (legacy reads `.1`; `trailing=1`). The PON comes from the ifIndex -> ifName map read from the
    same OLT. A trailing component other than 1 is refused: legacy only ever reads `.1`, and keying another row by the
    same ONT would overwrite it."""
    prefix = column_root + "."
    if not oid.startswith(prefix):
        return None
    parts = oid[len(prefix):].split(".")
    if len(parts) != 2 + trailing or not all(p.isdigit() for p in parts):
        return None
    if trailing and parts[2:] != ["1"] * trailing:
        return None
    pon = pon_by_ifindex.get(int(parts[0]))
    ont_id = int(parts[1])
    if pon is None or not 1 <= ont_id <= MAX_ONTS_PER_PON[pon.technology]:
        return None
    return Ont(pon, ont_id)


# --- Optical values -------------------------------------------------------------------------------------------------

GPON_POWER_NO_READING = 65535
OLT_RX_SENTINELS = (-1.0,)  # legacy's other sentinel, -80, is already outside RX_WINDOW
RX_WINDOW = (-70.0, 30.0)  # exclusive: at or beyond either end is no reading
EPON_OPTICAL_UNITS_CONFIRMED = False  # legacy shows the EPON values unscaled; no MIB or fixture says their unit


def gpon_onu_power(raw: int | None) -> float | None:
    """GPON ONU rx/tx in dBm from the raw 16-bit value: 0.002 dB steps from -30 dBm, values above 30000 counted
    negative, 65535 no reading. A value outside 0..65535 is refused rather than wrapped into range."""
    if raw is None or isinstance(raw, bool):
        return None
    raw = int(raw)
    if not 0 <= raw < GPON_POWER_NO_READING:
        return None
    steps = raw - 65536 if raw > 30000 else raw
    return round(steps * 0.002 - 30, 3)


def scale_optical(technology: str, field: str, raw: int | float | None) -> float | None:
    """A raw ZTE ONT optical reading in display units. fields: rx, tx (dBm), olt_rx (dBm, value / 1000; the same table
    for GPON and EPON, as legacy reads it), temp (degC; GPON value / 256), voltage (V; GPON value * 0.02). EPON rx, tx,
    temp and voltage are shown as read, like legacy (EPON_OPTICAL_UNITS_CONFIRMED). rx and olt_rx at or beyond
    RX_WINDOW are no reading."""
    if technology not in MAX_ONTS_PER_PON:
        raise ValueError(f"unknown technology {technology!r}")
    if raw is None or isinstance(raw, bool):
        return None
    if field == "olt_rx":
        value = round(raw / 1000, 3)
        if value in OLT_RX_SENTINELS:
            return None
    elif field not in ("rx", "tx", "temp", "voltage"):
        raise ValueError(f"unknown optical field {field!r}")
    elif technology == "epon":
        value = round(float(raw), 3)
    elif field in ("rx", "tx"):
        value = gpon_onu_power(raw)
        if value is None:
            return None
    elif field == "temp":
        value = round(raw / 256, 3)
    else:
        value = round(raw * 0.02, 3)
    if field in ("rx", "olt_rx") and not RX_WINDOW[0] < value < RX_WINDOW[1]:
        return None
    return value


# --- Offline reasons and phase states -------------------------------------------------------------------------------

_GPON_REASON = {
    1: "unknown", 2: "los", 3: "signal_failure", 4: "signal_failure", 5: "signal_failure", 6: "signal_failure",
    7: "signal_failure", 8: "auth_fail", 9: "power_off", 10: "admin_action", 11: "admin_action", 12: "admin_action",
    13: "admin_action",
}
_C300_GPON_LABELS = {1: "unknown", 2: "LOS", 3: "LOSi", 4: "lofi", 5: "sfi", 6: "loai", 7: "loami", 8: "authFail",
                     9: "PowerOff", 10: "deactiveSucc", 11: "deactiveFail", 12: "reboot", 13: "shutdown"}
_C600_GPON_LABELS = {1: "unknown", 2: "LOS", 3: "LOSi", 4: "LOFi", 5: "sfi", 6: "loai", 7: "loami", 8: "AuthFail",
                     9: "PowerOff", 10: "deactiveSucc", 11: "deactiveFail", 12: "Reboot", 13: "Shutdown"}

# Per table, exactly as the legacy value maps word them, then the common reason.
OFFLINE_REASON_TABLES: dict[str, dict[int, tuple[str, str]]] = {
    "c300.gpon.last_offline_reason": {c: (label, _GPON_REASON[c]) for c, label in _C300_GPON_LABELS.items()},
    "c600.gpon.last_offline_reason": {c: (label, _GPON_REASON[c]) for c, label in _C600_GPON_LABELS.items()},
    # "bugsell" is legacy's word with no definition anywhere in the repository; it stays visible but unknown.
    "c300.epon.last_offline_reason": {
        1: ("unknown", "unknown"), 14: ("bugsell", "unknown"), 21: ("LOS", "los"), 22: ("PowerOff", "power_off"),
        23: ("timestampDrift", "signal_failure"),
    },
}


def normalize_offline_reason(table: str, code: int | None) -> DownReason | None:
    return lookup_reason(OFFLINE_REASON_TABLES, table, code)


PHASE_STATUSES = ("online", "offline", "registering", "unknown")

# label, status, reason (None while online or registering)
PHASE_STATE_TABLES: dict[str, dict[int, tuple[str, str, str | None]]] = {
    "c300": {
        0: ("logging", "registering", None), 1: ("LOS", "offline", "los"), 2: ("syncMib", "registering", None),
        3: ("Online", "online", None), 4: ("PowerOff", "offline", "power_off"), 5: ("authFailed", "offline", "auth_fail"),
        6: ("Offline", "offline", "unknown"),
    },
    "c600": {
        1: ("Logging", "registering", None), 2: ("LOS", "offline", "los"), 3: ("SyncMib", "registering", None),
        4: ("Online", "online", None), 5: ("PowerOff", "offline", "power_off"), 6: ("AuthFailed", "offline", "auth_fail"),
        7: ("Offline", "offline", "unknown"),
    },
}


@dataclass(frozen=True)
class PhaseState:
    code: int
    label: str
    status: str         # one of PHASE_STATUSES
    reason: str | None  # one of COMMON_REASONS when offline


def normalize_phase_state(model: str, code: int | None) -> PhaseState | None:
    """A GPON ONT phase-state code for this model. An unlisted code is status and reason "unknown" with the raw code
    visible, never read as online."""
    if code is None:
        return None
    label, status, reason = PHASE_STATE_TABLES[model].get(code, (f"Code {code}", "unknown", "unknown"))
    return PhaseState(code, label, status, reason)
