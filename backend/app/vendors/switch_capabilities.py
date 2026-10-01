"""Which switch modules a model has, which the UI shows, and when FDB may be polled (Plan 18).

The per-model table is generated from the legacy model files (app/registry/switch_capability_data.py). The UI shows a
module only when the model has it; everything else is hidden rather than shown empty. FDB is the exception to "has
it, so show it": a full forwarding-database walk loads an access switch, so it stays off unless an operator turns it
on for a device with an explicit, bounded configuration (FdbPolicy). The one-MAC lookup from Plan 13
(app/services/mac_lookup.py) is a single bounded GET and needs no such policy.
"""
from __future__ import annotations

from dataclasses import dataclass, field

from app.registry.oid import MAX_ROWS, TIMEOUT_MS
from app.registry.switch_capability_data import SWITCH_MODELS

# Display order on the switch detail page.
SWITCH_CAPABILITIES = (
    "system", "link_status", "counters", "errors", "lldp", "vlan_summary", "sfp_optical", "rmon", "resources",
    "temperature", "fdb",
)

CAPABILITIES_BY_MODEL: dict[str, frozenset[str]] = {key: frozenset(caps) for key, _, _, caps, _ in SWITCH_MODELS}
ACTIONS_BY_MODEL: dict[str, frozenset[str]] = {key: frozenset(actions) for key, _, _, _, actions in SWITCH_MODELS}

MAX_FDB_ROWS = 4096
MAX_FDB_VLANS = 32


@dataclass(frozen=True)
class FdbPolicy:
    """Per device. Off by default; turning it on needs a row cap, a timeout and the VLANs to read, all bounded."""
    enabled: bool = False
    max_rows: int | None = None
    timeout_ms: int | None = None
    vlan_ids: tuple[int, ...] = field(default_factory=tuple)


DISABLED = FdbPolicy()


def model_capabilities(model_key: str) -> frozenset[str]:
    """Raises KeyError for a model with no entry: a configuration error, not an empty capability set."""
    return CAPABILITIES_BY_MODEL[model_key]


def fdb_problems(model_key: str, policy: FdbPolicy) -> list[str]:
    """Why this FDB configuration may not run. Empty means it may. A disabled policy never runs and is never a
    problem; an enabled one must be complete and inside every bound, and the model must support FDB at all."""
    if not policy.enabled:
        return []
    problems = []
    if "fdb" not in model_capabilities(model_key):
        problems.append("this model has no FDB support")
    if policy.max_rows is None or not 1 <= policy.max_rows <= min(MAX_FDB_ROWS, MAX_ROWS[1]):
        problems.append(f"max_rows must be between 1 and {MAX_FDB_ROWS}")
    if policy.timeout_ms is None or not TIMEOUT_MS[0] <= policy.timeout_ms <= TIMEOUT_MS[1]:
        problems.append(f"timeout_ms must be between {TIMEOUT_MS[0]} and {TIMEOUT_MS[1]}")
    if not policy.vlan_ids:
        problems.append("name the VLANs to read; a walk across every VLAN is not allowed")
    elif len(set(policy.vlan_ids)) > MAX_FDB_VLANS:
        problems.append(f"at most {MAX_FDB_VLANS} VLANs")
    elif not all(1 <= v <= 4094 for v in policy.vlan_ids):
        problems.append("VLAN ids must be 1 to 4094")
    return problems


def fdb_enabled(model_key: str, policy: FdbPolicy = DISABLED) -> bool:
    return policy.enabled and not fdb_problems(model_key, policy)


def visible_modules(model_key: str, policy: FdbPolicy = DISABLED) -> list[str]:
    """The modules the switch detail page shows, in display order: what the model has, with FDB only when its policy
    is enabled and valid."""
    caps = model_capabilities(model_key)
    return [c for c in SWITCH_CAPABILITIES if c in caps and (c != "fdb" or fdb_enabled(model_key, policy))]
