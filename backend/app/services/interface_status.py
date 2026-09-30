"""Records an interface's polled status. Not a repository: this runs on a device's own poll schedule, on behalf of
no signed-in caller, so there is no scope to check - the same reason app/polling/engine.py, not
app/repositories/polling.py, writes device_poll_state and polling_results.

Nothing calls this yet: no OID profile decodes a poll result into an interface row (Plans 11 and 13-19 build that).
It is tested directly against the schema it writes to.
"""
from __future__ import annotations

import asyncpg


async def record_interface_status(conn: asyncpg.Connection, interface_id: str, admin_status: str, oper_status: str) -> bool:
    """Updates the interface's current status. Appends exactly one history row when either status actually
    changed, never one row per field. Returns whether anything changed."""
    async with conn.transaction():
        previous = await conn.fetchrow(
            "select device_id, admin_status, oper_status from interfaces where id = $1::uuid for update", interface_id
        )
        if previous is None:
            raise LookupError("interface")
        changed = previous["admin_status"] != admin_status or previous["oper_status"] != oper_status
        await conn.execute(
            "update interfaces set admin_status = $2, oper_status = $3, updated_at = now() where id = $1::uuid",
            interface_id, admin_status, oper_status,
        )
        if changed:
            await conn.execute(
                "insert into interface_status_history (interface_id, device_id, admin_status, oper_status) "
                "values ($1::uuid, $2::uuid, $3, $4)",
                interface_id, previous["device_id"], admin_status, oper_status,
            )
        return changed
