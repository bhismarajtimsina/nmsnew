from __future__ import annotations

from typing import Any

import asyncpg

from app.core.security import CurrentUser
from app.repositories.scope import DEVICE_VISIBLE, GRANTED_GROUPS_CTE

# The identity the admin CLI acts as. It has no row in `users`, so anything it creates records no creator.
SYSTEM_USER_ID = "00000000-0000-0000-0000-000000000000"

DEVICE_COLUMNS = """
    d.id, d.name, d.hostname, host(d.management_ip) as management_ip, d.device_type, d.vendor, d.status,
    d.group_id, d.model_id, d.polling_enabled, d.polling_owner, d.legacy_id, d.created_at, d.updated_at
"""


async def list_devices(
    conn: asyncpg.Connection,
    user: CurrentUser,
    *,
    limit: int,
    offset: int,
    device_type: str | None = None,
    status: str | None = None,
    search: str | None = None,
) -> tuple[list[asyncpg.Record], int]:
    where = f"where {DEVICE_VISIBLE} and ($3::text is null or d.device_type = $3) and ($4::text is null or d.status = $4) " \
            f"and ($5::text is null or d.name ilike '%' || $5 || '%' or d.management_ip::text like $5 || '%')"
    args = (user.id, user.scope_all, device_type, status, search)
    rows = await conn.fetch(
        f"{GRANTED_GROUPS_CTE} select {DEVICE_COLUMNS} from devices d {where} order by d.name, d.id limit $6 offset $7",
        *args, limit, offset,
    )
    total = await conn.fetchval(f"{GRANTED_GROUPS_CTE} select count(*) from devices d {where}", *args)
    return rows, total


OVERVIEW_SORT = {"name": "d.name, d.id", "ip": "d.management_ip, d.id"}


async def device_overview(
    conn: asyncpg.Connection, user: CurrentUser, *, limit: int, offset: int, sort: str = "name", search: str | None = None,
    device_id: str | None = None,
) -> tuple[list[dict[str, Any]], int]:
    """The device list page (Plan 25, legacy `/dev-dashboard/devices`): each visible device with its group, model,
    last ping result and interface counts, devices the pinger last saw down first.

    An interface counts as up only when its operational status is `up`; every other status counts as down, as the
    legacy count does (anything not Up/Online). A device never pinged has `ping` null and sorts with the up ones,
    again as legacy does: only a device whose last ping failed goes to the top."""
    order = OVERVIEW_SORT[sort]
    where = f"where {DEVICE_VISIBLE} and ($3::text is null or d.name ilike '%' || $3 || '%' or host(d.management_ip) like $3 || '%') " \
            f"and ($4::uuid is null or d.id = $4::uuid)"
    rows = await conn.fetch(
        f"""{GRANTED_GROUPS_CTE}
        select d.id, d.name, host(d.management_ip) as management_ip, d.polling_enabled,
               g.id as group_id, g.name as group_name,
               m.id as model_id, m.model_name, m.vendor as model_vendor, m.icon as model_icon,
               p.status as ping_status, p.latency_ms, p.last_checked_at,
               coalesce(i.up, 0) as interfaces_up, coalesce(i.down, 0) as interfaces_down
        from devices d
        left join device_groups g on g.id = d.group_id
        left join device_models m on m.id = d.model_id
        left join device_ping_status p on p.device_id = d.id
        left join lateral (
            select count(*) filter (where oper_status = 'up') as up, count(*) filter (where oper_status <> 'up') as down
            from interfaces where device_id = d.id
        ) i on true
        {where}
        order by (p.status = 'down') is true desc, {order}
        limit $5 offset $6""",
        user.id, user.scope_all, search, device_id, limit, offset,
    )
    total = await conn.fetchval(f"{GRANTED_GROUPS_CTE} select count(*) from devices d {where}", user.id, user.scope_all, search, device_id)
    return [_overview_item(r) for r in rows], total


async def device_overview_one(conn: asyncpg.Connection, user: CurrentUser, device_id: str) -> dict[str, Any] | None:
    """One device in the overview shape, for its detail page; None when it does not exist or is out of scope."""
    items, _ = await device_overview(conn, user, limit=1, offset=0, device_id=device_id)
    return items[0] if items else None


def _overview_item(r: asyncpg.Record) -> dict[str, Any]:
    return {
        "id": str(r["id"]),
        "name": r["name"],
        "management_ip": r["management_ip"],
        "polling_enabled": r["polling_enabled"],
        "group": {"id": str(r["group_id"]), "name": r["group_name"]} if r["group_id"] else None,
        "model": {"id": str(r["model_id"]), "name": r["model_name"], "vendor": r["model_vendor"], "icon": r["model_icon"]}
        if r["model_id"] else None,
        "ping": {"status": r["ping_status"], "latency_ms": r["latency_ms"], "last_checked_at": r["last_checked_at"]}
        if r["ping_status"] is not None else None,
        "interfaces": {"up": r["interfaces_up"], "down": r["interfaces_down"]},
    }


async def get_device(conn: asyncpg.Connection, user: CurrentUser, device_id: str) -> asyncpg.Record | None:
    return await conn.fetchrow(
        f"{GRANTED_GROUPS_CTE} select {DEVICE_COLUMNS} from devices d where d.id = $3::uuid and {DEVICE_VISIBLE}",
        user.id, user.scope_all, device_id,
    )


async def action_target(conn: asyncpg.Connection, user: CurrentUser, device_id: str) -> asyncpg.Record | None:
    """What a device action needs to reach a device it is allowed to touch: address, access profile and the registry
    vendor (drivers that write vendor-private objects refuse any other vendor). Scoped like
    every other device read; None when the device is not visible."""
    return await conn.fetchrow(
        f"{GRANTED_GROUPS_CTE} select d.id, d.name, host(d.management_ip) as management_ip, d.access_profile_id, "
        f"v.slug as vendor_slug from devices d left join vendors v on v.id = d.vendor_id where d.id = $3::uuid and {DEVICE_VISIBLE}",
        user.id, user.scope_all, device_id,
    )


def as_dict(row: asyncpg.Record) -> dict[str, Any]:
    return {key: (str(value) if key in {"id", "group_id", "model_id"} and value is not None else value) for key, value in dict(row).items()}


class Rejected(ValueError):
    """The request is understood but cannot be done. Carries every reason, so a caller fixes them in one go."""

    def __init__(self, reasons: list[str]) -> None:
        super().__init__("; ".join(reasons))
        self.reasons = reasons


async def resolve_family(conn: asyncpg.Connection, vendor_slug: str | None, family_slug: str | None) -> tuple[str | None, str | None, str | None]:
    """(vendor_id, family_id, device_type) for a registry family; (None, None, None) when none was named."""
    if vendor_slug is None and family_slug is None:
        return None, None, None
    if not (vendor_slug and family_slug):
        raise Rejected(["give both vendor and model family, or neither"])
    row = await conn.fetchrow(
        "select v.id as vendor_id, f.id as family_id, f.device_type from vendor_model_families f join vendors v on v.id = f.vendor_id where v.slug = $1 and f.slug = $2",
        vendor_slug, family_slug,
    )
    if row is None:
        raise Rejected([f"unknown model family {vendor_slug}/{family_slug}"])
    return str(row["vendor_id"]), str(row["family_id"]), row["device_type"]


async def _check_access_profile(conn: asyncpg.Connection, profile_id: str | None) -> None:
    if profile_id is not None and not await conn.fetchval("select exists(select 1 from device_access_profiles where id = $1::uuid)", profile_id):
        raise Rejected(["unknown access profile"])


async def create_device(
    conn: asyncpg.Connection, user: CurrentUser, *, name: str, hostname: str | None, management_ip: str, device_type: str,
    vendor_slug: str | None, family_slug: str | None, group_id: str | None, access_profile_id: str | None,
) -> str:
    """Create a device. It starts with polling OFF and owned by this system; turning polling on is a separate, checked step."""
    from app.repositories.device_groups import NotVisible, get_group

    vendor_id, family_id, family_type = await resolve_family(conn, vendor_slug, family_slug)
    if family_type is not None and family_type != device_type:
        # A BDCOM switch registered as an OLT would run the wrong pollers on it (risk K-09).
        raise Rejected([f"device type {device_type!r} does not match the model family, which is a {family_type}"])
    if group_id is not None:
        if await get_group(conn, user, group_id) is None:
            raise NotVisible("group")
    elif not user.scope_all:
        raise Rejected(["a restricted user must place the device in a group they were given"])
    await _check_access_profile(conn, access_profile_id)
    if await conn.fetchval("select exists(select 1 from devices where management_ip = $1::inet)", management_ip):
        raise Rejected(["a device with this management address already exists"])
    return str(await conn.fetchval(
        """
        insert into devices (name, hostname, management_ip, device_type, vendor, vendor_id, family_id, group_id, access_profile_id,
                             polling_enabled, polling_owner, created_by)
        values ($1, $2, $3::inet, $4, $5, $6::uuid, $7::uuid, $8::uuid, $9::uuid, false, 'cybersathy', $10::uuid)
        returning id
        """,
        name, hostname, management_ip, device_type, vendor_slug, vendor_id, family_id, group_id, access_profile_id,
        None if user.id == SYSTEM_USER_ID else user.id,
    ))


async def update_device(conn: asyncpg.Connection, user: CurrentUser, device_id: str, changes: dict[str, Any]) -> None:
    from app.repositories import vendors as vendor_repo
    from app.repositories.device_groups import NotVisible, get_group

    current = await get_device(conn, user, device_id)
    if current is None:
        raise NotVisible("device")
    if "group_id" in changes:
        if changes["group_id"] is None:
            if not user.scope_all:
                raise Rejected(["a restricted user cannot take a device out of its group"])
        elif await get_group(conn, user, changes["group_id"]) is None:
            raise NotVisible("group")
    if "access_profile_id" in changes:
        await _check_access_profile(conn, changes["access_profile_id"])
    if "management_ip" in changes and await conn.fetchval(
        "select exists(select 1 from devices where management_ip = $1::inet and id <> $2::uuid)", changes["management_ip"], device_id
    ):
        raise Rejected(["a device with this management address already exists"])

    family_change = "vendor_slug" in changes or "family_slug" in changes
    if family_change:
        vendor_id, family_id, family_type = await resolve_family(conn, changes.get("vendor_slug"), changes.get("family_slug"))
        wanted_type = changes.get("device_type", current["device_type"])
        if family_type is not None and family_type != wanted_type:
            raise Rejected([f"device type {wanted_type!r} does not match the model family, which is a {family_type}"])
    elif "device_type" in changes:
        family_type = await conn.fetchval(
            "select f.device_type from vendor_model_families f join devices d on d.family_id = f.id where d.id = $1::uuid", device_id)
        if family_type is not None and family_type != changes["device_type"]:
            raise Rejected([f"device type {changes['device_type']!r} does not match the model family, which is a {family_type}"])

    if changes.get("polling_enabled") is True:
        reasons = []
        row = await conn.fetchrow(
            "select d.family_id, d.access_profile_id, d.polling_owner, v.slug as vendor_slug, f.slug as family_slug "
            "from devices d left join vendor_model_families f on f.id = d.family_id left join vendors v on v.id = f.vendor_id where d.id = $1::uuid",
            device_id,
        )
        family_id_after = family_id if family_change else row["family_id"]
        profile_after = changes["access_profile_id"] if "access_profile_id" in changes else row["access_profile_id"]
        if family_id_after is None:
            reasons.append("choose the model family first")
        if profile_after is None:
            reasons.append("choose an access profile first")
        if row["polling_owner"] != "cybersathy":
            reasons.append("this device is still polled by the legacy system")
        if family_id_after is not None:
            fam = await conn.fetchrow(
                "select v.slug as vendor_slug, f.slug as family_slug from vendor_model_families f join vendors v on v.id = f.vendor_id where f.id = $1::uuid",
                str(family_id_after))
            if not await vendor_repo.polling_allowed(conn, fam["vendor_slug"], fam["family_slug"]):
                reasons.append("polling is switched off for this vendor or model family")
        if reasons:
            raise Rejected(reasons)

    if family_change:
        await conn.execute("update devices set vendor = $2, vendor_id = $3::uuid, family_id = $4::uuid where id = $1::uuid",
                           device_id, changes.get("vendor_slug"), vendor_id, family_id)
    for column, cast in (("name", ""), ("hostname", ""), ("device_type", ""), ("polling_enabled", ""), ("group_id", "::uuid"),
                         ("access_profile_id", "::uuid"), ("management_ip", "::inet")):
        if column in changes:
            await conn.execute(f"update devices set {column} = $2{cast}, updated_at = now() where id = $1::uuid", device_id, changes[column])


async def delete_device(conn: asyncpg.Connection, user: CurrentUser, device_id: str) -> None:
    from app.repositories.device_groups import NotVisible

    if await get_device(conn, user, device_id) is None:
        raise NotVisible("device")
    await conn.execute("delete from devices where id = $1::uuid", device_id)


async def recent_discovery(conn: asyncpg.Connection, user: CurrentUser, device_id: str, limit: int = 10) -> list[dict[str, Any]] | None:
    """Discovery jobs of a device the caller may see; None when the device is not visible."""
    if await get_device(conn, user, device_id) is None:
        return None
    rows = await conn.fetch(
        "select id, status, oids, timeout_ms, retries, requested_at, started_at, finished_at, result, error "
        "from discovery_jobs where device_id = $1::uuid order by requested_at desc limit $2", device_id, limit)
    return [{**dict(r), "id": str(r["id"]), "oids": list(r["oids"])} for r in rows]
