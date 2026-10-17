"""What keeps each table from growing forever (Plan 40): a read-only report, and the classification behind it.

Every table in the schema is either history (rows pile up over time, so something must remove old ones) or
reference data (configuration, inventory and current state, kept while in use). tests/test_retention.py fails when a
table is in neither list, so a new table cannot arrive without someone deciding which it is, and when a hypertable
has no retention or compression policy (Plan 40's own check).

The report reads the policies as they are in the database, never as this file assumes them:
- a TimescaleDB retention or compression policy on a hypertable;
- an enabled `retention` scheduled job (app/scheduling/jobs.py) naming the table;
- the `cleanup_sessions` job for user sessions.

A history table with none of these says "none". The report deletes nothing. Choosing a period for the tables that
have none (console transcripts, action records, finished maintenance windows, worker heartbeats) is the owner's
decision: Plan 40 warns against setting retention before capacity is understood.
"""
from __future__ import annotations

from dataclasses import dataclass

import asyncpg

# Table -> the column that says how old a row is.
HISTORY: dict[str, str] = {
    "action_confirmations": "created_at",
    "action_results": "started_at",
    "audit_logs": "occurred_at",
    "console_history": "at",
    "console_sessions": "created_at",
    "dead_letter_jobs": "created_at",
    "device_ping_history": "changed_at",
    "discovery_jobs": "requested_at",
    "events": "occurred_at",
    "interface_counter_samples": "sampled_at",
    "interface_status_history": "changed_at",
    "login_attempts": "occurred_at",
    "maintenance_windows": "ends_at",
    "notifications": "created_at",
    "polling_results": "started_at",
    "schedule_runs": "started_at",
    "trap_history": "received_at",
    "user_sessions": "created_at",
    "worker_heartbeats": "last_seen",
}

REFERENCE: frozenset[str] = frozenset({
    "alarm_rules", "alembic_version", "api_tokens", "capabilities", "device_access_profiles", "device_groups",
    "device_models", "device_ping_status", "device_poll_state", "devices", "family_capabilities", "interface_marks",
    "interface_tags", "interfaces", "link_external_names", "links", "lldp_neighbours", "macros", "mib_files",
    "mib_objects", "notification_contacts", "notification_event_config", "notification_event_ignored_devices",
    "oid_definitions", "oid_profile_entries", "oid_profiles", "oid_transform_rules", "path_segments", "path_states",
    "paths", "permissions", "reseller_users", "resellers", "role_permissions", "roles", "schedule_jobs",
    "system_settings", "totp_recovery_codes", "trap_profiles", "user_device_group_scopes", "user_device_scopes",
    "user_interface_scopes", "users", "vendor_model_families", "vendors",
})


@dataclass(frozen=True)
class TableReport:
    table: str
    hypertable: bool
    rows: int  # approximate for a hypertable
    oldest: str | None
    retention: str
    compression: str | None  # hypertables only


async def report(conn: asyncpg.Connection) -> list[TableReport]:
    hypertables = {r["hypertable_name"] for r in await conn.fetch(
        "select hypertable_name from timescaledb_information.hypertables where hypertable_schema = 'public'")}
    policies: dict[tuple[str, str], str] = {}
    for r in await conn.fetch(
        "select hypertable_name, proc_name, coalesce(config->>'drop_after', config->>'compress_after') as after "
        "from timescaledb_information.jobs where proc_name in ('policy_retention', 'policy_compression')"):
        policies[(r["hypertable_name"], r["proc_name"])] = r["after"]
    jobs = {r["target"]: r for r in await conn.fetch(
        "select key as name, enabled, params->>'target' as target, params->>'days' as days from schedule_jobs where job_type = 'retention'")}
    sessions_job = await conn.fetchrow(
        "select key as name, enabled, params->>'older_than_days' as days from schedule_jobs where job_type = 'cleanup_sessions' order by key limit 1")

    out = []
    for table, column in sorted(HISTORY.items()):
        is_hyper = table in hypertables
        if is_hyper:
            rows = await conn.fetchval("select approximate_row_count($1::regclass)", table)
        else:
            rows = await conn.fetchval(f"select count(*) from {table}")  # table names come from HISTORY, never input
        oldest = await conn.fetchval(f"select min({column})::text from {table}")
        if (table, "policy_retention") in policies:
            retention = f"{policies[(table, 'policy_retention')]} (TimescaleDB)"
        elif table in jobs:
            job = jobs[table]
            retention = f"{job['days']} days (job {job['name']}{'' if job['enabled'] else ', disabled'})"
        elif table == "user_sessions" and sessions_job is not None:
            retention = f"{sessions_job['days'] or 7} days after expiry (job {sessions_job['name']}{'' if sessions_job['enabled'] else ', disabled'})"
        else:
            retention = "none"
        compression = policies.get((table, "policy_compression"), "none") if is_hyper else None
        out.append(TableReport(table, is_hyper, int(rows or 0), oldest, retention, compression))
    return out


def render(rows: list[TableReport]) -> str:
    header = ("table", "rows", "oldest", "retention", "compression")
    lines = [(r.table + (" *" if r.hypertable else ""), str(r.rows), r.oldest or "-", r.retention, r.compression or "-") for r in rows]
    widths = [max(len(x) for x in col) for col in zip(header, *lines)]
    fmt = "  ".join(f"{{:<{w}}}" for w in widths)
    body = [fmt.format(*header), fmt.format(*("-" * w for w in widths)), *(fmt.format(*line) for line in lines)]
    missing = [r.table for r in rows if r.retention == "none"]
    body.append("")
    body.append("* hypertable; its row count is approximate")
    if missing:
        body.append(f"No retention: {', '.join(missing)}. Choosing a period for these is the owner's decision (Plan 40).")
    return "\n".join(body)
