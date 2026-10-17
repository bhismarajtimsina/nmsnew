"""On-demand MAC lookup on one switch (Plan 13): where a MAC address is learned, from one bounded GET
(app/services/mac_lookup.py), never an FDB walk.

The same shape as the on-demand ping (app/diagnostics/ping.py):
- The API checks `search.mac`, the device's scope, the MAC and the VLAN list and two rate limits. It writes a
  short-lived request record to Redis and queues a signed job. It refuses at once while the SNMP transport is
  disabled (D-17), so no job is queued that could only fail.
- A worker of kind `diagnostics` checks the requester again with fresh data. It applies the same guards as polling:
  the device must be owned by this system, have an access profile, and not have its vendor switched off or its circuit
  breaker open. Then it sends the one GET and writes the result to the same record.
- Results are not stored in the database: the record expires after RESULT_TTL_SECONDS, and the audit log keeps the
  summary.

A miss means "not learned in these VLANs", never "not on this switch" (see app/services/mac_lookup.py). A hit names a
bridge port, which is not always an ifIndex. Not verified against a device.
"""
from __future__ import annotations

import json
import uuid
from dataclasses import asdict, dataclass
from typing import Any

from redis.asyncio import Redis

MAC_STREAM = "maclookup.jobs"
RESULT_TTL_SECONDS = 600
PERMISSION = "search.mac"


def record_key(request_id: str) -> str:
    return f"cs:maclookup:{request_id}"


@dataclass
class Record:
    user_id: str
    device_id: str
    mac: str
    vlans: list[int]
    status: str = "queued"        # queued -> succeeded | failed | refused
    result: dict[str, Any] | None = None
    error: str | None = None


def normalise(mac: str) -> str:
    """aa:bb:cc:dd:ee:ff from any form app/services/mac_lookup.parse_mac accepts. Raises ValueError."""
    from app.services.mac_lookup import parse_mac

    return ":".join(f"{o:02x}" for o in parse_mac(mac))


async def save(redis: Redis, request_id: str, record: Record) -> None:
    await redis.set(record_key(request_id), json.dumps(asdict(record)), ex=RESULT_TTL_SECONDS)


async def load(redis: Redis, request_id: str) -> Record | None:
    raw = await redis.get(record_key(request_id))
    if raw is None:
        return None
    try:
        return Record(**json.loads(raw))
    except (TypeError, ValueError):
        return None


def new_request_id() -> str:
    return str(uuid.uuid4())
