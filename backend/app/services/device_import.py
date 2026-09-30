"""Bulk import of devices from CSV rows, for the admin CLI.

Uses the same create path as the API, so every rule applies: usable management address, model family matches the device
type, polling starts off, only a safe discovery is queued. A dry run (the default) validates everything and writes nothing.

Columns: name, management_ip, device_type, vendor, family, group, access_profile   (vendor, family, group, access_profile optional)
"""
from __future__ import annotations

import csv
from dataclasses import dataclass, field
from typing import Any

import asyncpg

from app.core.config import settings
from app.core.netutil import validate_management_ip
from app.core.security import CurrentUser
from app.repositories import devices as device_repo
from app.repositories.device_groups import NotVisible
from app.services.discovery import queue_discovery

MAX_ROWS = 20_000
REQUIRED = ("name", "management_ip", "device_type")
SYSTEM = CurrentUser(id=device_repo.SYSTEM_USER_ID, username="cli", display_name="cli", email=None, role="system", role_id="", scope_mode="all")


@dataclass
class ImportReport:
    created: int = 0
    valid: int = 0
    errors: list[dict[str, Any]] = field(default_factory=list)
    discovery_jobs: int = 0
    applied: bool = False


def read_csv(path: str) -> list[dict[str, str]]:
    with open(path, newline="", encoding="utf-8-sig") as handle:
        reader = csv.DictReader(handle)
        missing = [c for c in REQUIRED if c not in (reader.fieldnames or [])]
        if missing:
            raise ValueError(f"missing column(s): {', '.join(missing)}")
        rows = list(reader)
    if len(rows) > MAX_ROWS:
        raise ValueError(f"too many rows ({len(rows)}); the limit is {MAX_ROWS}")
    return rows


async def import_devices(conn: asyncpg.Connection, rows: list[dict[str, str]], *, apply: bool) -> ImportReport:
    report = ImportReport(applied=apply)
    seen_ips: set[str] = set()
    prepared: list[dict[str, Any]] = []
    groups = {}
    for r in await conn.fetch("select id, name from device_groups"):
        groups.setdefault(r["name"], []).append(str(r["id"]))
    profiles = {r["name"]: str(r["id"]) for r in await conn.fetch("select id, name from device_access_profiles")}

    for number, row in enumerate(rows, start=2):  # row 1 is the header
        problems: list[str] = []
        name = (row.get("name") or "").strip()
        if not name:
            problems.append("name is empty")
        try:
            ip = validate_management_ip(row.get("management_ip") or "", settings.management_networks)
        except ValueError as exc:
            ip, _ = "", problems.append(f"management_ip: {exc}")
        if ip and ip in seen_ips:
            problems.append("management_ip appears twice in the file")
        seen_ips.add(ip)
        device_type = (row.get("device_type") or "").strip()
        group_name = (row.get("group") or "").strip()
        group_id = None
        if group_name:
            found = groups.get(group_name, [])
            if len(found) != 1:
                problems.append(f"group {group_name!r} " + ("does not exist" if not found else "is ambiguous (several groups share the name)"))
            else:
                group_id = found[0]
        profile_name = (row.get("access_profile") or "").strip()
        profile_id = None
        if profile_name:
            profile_id = profiles.get(profile_name)
            if profile_id is None:
                problems.append(f"access profile {profile_name!r} does not exist")
        if problems:
            report.errors.append({"row": number, "name": name, "problems": problems})
            continue
        prepared.append(dict(row=number, name=name, ip=ip, device_type=device_type, vendor=(row.get("vendor") or "").strip() or None,
                             family=(row.get("family") or "").strip() or None, group_id=group_id, profile_id=profile_id))

    if not apply:
        # Dry run: check each row against the real rules inside a transaction that is always rolled back.
        tx = conn.transaction()
        await tx.start()
        try:
            await _create_all(conn, prepared, report, queue=False)
        finally:
            await tx.rollback()
        report.created = 0
        return report

    if report.errors:
        raise ImportAborted(report)  # all or nothing: one bad row stops the whole import before anything is written
    async with conn.transaction():
        await _create_all(conn, prepared, report, queue=True)
        if report.errors:
            raise ImportAborted(report)
    return report


class ImportAborted(Exception):
    def __init__(self, report: "ImportReport") -> None:
        super().__init__("import aborted; nothing was created")
        self.report = report


async def _create_all(conn: asyncpg.Connection, prepared: list[dict[str, Any]], report: ImportReport, *, queue: bool) -> None:
    for item in prepared:
        try:
            device_id = await device_repo.create_device(
                conn, SYSTEM, name=item["name"], hostname=None, management_ip=item["ip"], device_type=item["device_type"],
                vendor_slug=item["vendor"], family_slug=item["family"], group_id=item["group_id"], access_profile_id=item["profile_id"],
            )
        except device_repo.Rejected as exc:
            report.errors.append({"row": item["row"], "name": item["name"], "problems": exc.reasons})
            continue
        except NotVisible as exc:  # cannot happen for the system identity, but never crash a whole import over one row
            report.errors.append({"row": item["row"], "name": item["name"], "problems": [str(exc)]})
            continue
        except asyncpg.CheckViolationError as exc:
            report.errors.append({"row": item["row"], "name": item["name"], "problems": [f"rejected by the database: {exc.constraint_name}"]})
            continue
        report.valid += 1
        report.created += 1
        if queue:
            job = await queue_discovery(conn, None, device_id, None)
            report.discovery_jobs += 1 if job["status"] == "queued" else 0
