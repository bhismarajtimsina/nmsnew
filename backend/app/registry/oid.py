"""OID registry rules, mirrored in Python so import tooling can reject a bad profile before it reaches the database.

The database enforces the same rules with constraints and triggers (migration 0007). tests/test_oid_registry.py runs one
table of cases through both and fails if they ever disagree.
"""
from __future__ import annotations

import re
from dataclasses import dataclass

NUMERIC_OID = re.compile(r"^[0-9]+(\.[0-9]+)+$")
READ_ONLY_ACCESS = {"read-only", "not-accessible"}
WALKS = {"walk", "bulkwalk"}
STRATEGIES = {"get", "getnext", "walk", "bulkwalk"}
MAX_ROWS = (1, 5000)
TIMEOUT_MS = (200, 30000)


@dataclass(frozen=True)
class Definition:
    logical_name: str
    numeric_oid: str
    access: str = "read-only"
    safety_level: str = "safe"


@dataclass(frozen=True)
class Entry:
    definition: Definition
    walk_strategy: str = "get"
    max_rows: int | None = None
    timeout_ms: int | None = None


def definition_problems(d: Definition) -> list[str]:
    problems = []
    if not NUMERIC_OID.match(d.numeric_oid):
        problems.append("numeric OID is not dotted decimal")
    if d.access not in {"read-only", "read-write", "write-only", "read-create", "not-accessible"}:
        problems.append("unknown access")
    if d.safety_level not in {"safe", "bounded", "dangerous"}:
        problems.append("unknown safety level")
    if d.access not in READ_ONLY_ACCESS and d.safety_level != "dangerous":
        problems.append("a writable object must be marked dangerous")
    return problems


def entry_problems(e: Entry) -> list[str]:
    """Why this entry may not be part of a polling profile. Empty means it may."""
    problems = []
    if e.definition.access not in READ_ONLY_ACCESS or e.definition.safety_level == "dangerous":
        problems.append("writable or dangerous OID cannot be polled")
    if e.walk_strategy not in STRATEGIES:
        problems.append("unknown walk strategy")
    if e.walk_strategy in WALKS and (e.max_rows is None or e.timeout_ms is None):
        problems.append("a walk needs max_rows and timeout_ms")
    if e.max_rows is not None and not MAX_ROWS[0] <= e.max_rows <= MAX_ROWS[1]:
        problems.append("max_rows out of range")
    if e.timeout_ms is not None and not TIMEOUT_MS[0] <= e.timeout_ms <= TIMEOUT_MS[1]:
        problems.append("timeout_ms out of range")
    return problems


def profile_problems(entries: list[Entry]) -> list[str]:
    problems = [f"{e.definition.logical_name}: {p}" for e in entries for p in entry_problems(e)]
    names = [e.definition.logical_name for e in entries]
    problems += [f"{n}: listed twice" for n in sorted({n for n in names if names.count(n) > 1})]
    if not entries:
        problems.append("a profile needs at least one entry")
    return problems
