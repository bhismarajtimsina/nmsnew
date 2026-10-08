"""What drivers and the safety layer share (Plan 26/38), kept apart so neither imports the other's module at load."""
from __future__ import annotations

from dataclasses import dataclass
from typing import Any


@dataclass(frozen=True)
class Outcome:
    before: dict[str, Any] | None = None
    after: dict[str, Any] | None = None


class ActionFailed(Exception):
    """Raised by a driver: the action ran and the device refused or failed it, or the driver refused to start it."""
