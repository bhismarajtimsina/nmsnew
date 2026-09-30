"""Row visibility for scoped data. The only place scope rules are written.

Every query that reads scoped tables (devices, interfaces, and later OLTs, ONUs, events, links) goes through a repository
that uses these fragments. Routers never write SQL against those tables: tests/test_scope_guard.py enforces that.

A caller sees a row when their role sees everything (`scope_mode = 'all'`), or when the row is inside what was assigned to
them: the device itself, its group or any ancestor group they were given, or (for interfaces) the interface itself.
Unknown or missing scope means no rows: the rules only ever add visibility, they never assume it.

Parameters: $1 = user id (uuid), $2 = scope_all (boolean). Callers append their own parameters from $3.
"""
from __future__ import annotations

GRANTED_GROUPS_CTE = """
with recursive granted(id) as (
    select device_group_id from user_device_group_scopes where user_id = $1::uuid
    union
    select g.id from device_groups g join granted on g.parent_id = granted.id
)
"""

DEVICE_VISIBLE = """(
    $2::boolean
    or d.id in (select device_id from user_device_scopes where user_id = $1::uuid)
    or d.group_id in (select id from granted)
)"""

INTERFACE_VISIBLE = """(
    $2::boolean
    or i.id in (select interface_id from user_interface_scopes where user_id = $1::uuid)
    or d.id in (select device_id from user_device_scopes where user_id = $1::uuid)
    or d.group_id in (select id from granted)
)"""
