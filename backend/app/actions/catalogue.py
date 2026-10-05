"""Which actions are dangerous, who may run them, on what, and how many at once (Plan 26).

Every action here needs its own permission AND the global `dangerous_actions.execute` gate, a confirmation from a dry
run, and a target inside the caller's scope. The caps are per request; a bulk request above its cap is refused, never
trimmed.
"""
from __future__ import annotations

from dataclasses import dataclass, field

TARGET_KINDS = ("device", "interface", "onu")
GATE = "dangerous_actions.execute"


@dataclass(frozen=True)
class ActionSpec:
    key: str
    title: str
    permission: str
    target_kind: str
    max_targets: int
    params: dict[str, tuple[str, ...] | None] = field(default_factory=dict)  # name -> allowed values (None: any short text)


ACTIONS: dict[str, ActionSpec] = {a.key: a for a in [
    ActionSpec("switch.reboot", "Reboot switch", "switches.reboot", "device", 1),
    ActionSpec("switch.save_config", "Save switch configuration", "switches.save_config", "device", 10),
    ActionSpec("switch.port.set_admin_state", "Enable or disable a port", "switches.port.set_admin_state", "interface", 1,
               {"state": ("up", "down")}),
    ActionSpec("switch.counters.clear", "Clear port counters", "switches.counters.clear", "interface", 48),
    ActionSpec("onu.reboot", "Reboot ONU", "olts.onu.reboot", "onu", 64),
    ActionSpec("onu.reset", "Reset ONU to factory settings", "olts.onu.reset", "onu", 16),
    ActionSpec("onu.deregister", "Delete (deregister) ONU", "olts.onu.deregister", "onu", 16),
    ActionSpec("onu.disable", "Disable ONU", "olts.onu.disable", "onu", 16),
    ActionSpec("macro.execute", "Run a macro", "macros.execute", "device", 20, {"macro_id": None}),
]}

# The hardest ceiling, whatever an entry above says; the database enforces the same number.
MAX_TARGETS_HARD = 500
