"""Keep legacy action OIDs out of polling (Plan 17, and every vendor after it).

Legacy switcher-core's OID files carry no ACCESS clause: a reboot trigger and an optical reading look the same. The
registry refuses writable OIDs by their MIB access (app/registry/oid.py), so an entry imported from a legacy file with
no MIB behind it would default to read-only, and an action could reach a polling profile. This classifier marks such
entries dangerous by their legacy name, which keeps them out of every profile until a MIB says otherwise.

The rule is deliberately broad: a name with an action word as any of its parts (split on dots, underscores and
camelCase), or a legacy `#SNMPSET` comment. Calibrated against all 910 distinct names in the legacy OID files: it
flags 67, and every reason or status enum (lastDownCause, LastOfflineReason, ...) stays unflagged. Over-flagging a
readable config state only delays it until its MIB is added; under-flagging could reboot an ONT.
"""
from __future__ import annotations

import re

from app.registry.oid import Definition

ACTION_WORDS = frozenset({
    "action", "actions", "ctrl", "control", "set", "save", "reboot", "reset", "delete", "restore", "clear", "enable",
    "disable",
})
_TOKEN = re.compile(r"[A-Z]+(?![a-z])|[A-Z]?[a-z]+|[0-9]+")


def name_tokens(name: str) -> list[str]:
    """`ont.gpon.controlReRegister` -> ont, gpon, control, re, register; `DevCtrlSystemReboot` -> dev, ctrl, ..."""
    return [t.lower() for t in _TOKEN.findall(name)]


def is_legacy_action(name: str, comment: str = "") -> bool:
    return bool(ACTION_WORDS.intersection(name_tokens(name))) or "SNMPSET" in comment


def legacy_definition(name: str, numeric_oid: str, comment: str = "") -> Definition:
    """The registry definition for an entry known only from a legacy OID file. An action is read-write and dangerous,
    so `entry_problems` refuses it in any profile."""
    oid = numeric_oid.lstrip(".")
    if is_legacy_action(name, comment):
        return Definition(name, oid, access="read-write", safety_level="dangerous")
    return Definition(name, oid)
