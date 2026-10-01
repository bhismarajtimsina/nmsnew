"""Device changes are announced on the realtime channel `devices.changed` (Plan 25), so open device pages reload
through the scoped API. The notice carries no device data at all: realtime dispatch does not apply device scope."""
import json

from redis.exceptions import RedisError

from app.realtime.bus import REALTIME_CHANNEL
from tests.helpers import bearer, make_user


async def admin(app_client, db):
    await make_user(db, "boss", "ISP Admin")
    headers = await bearer(app_client, "boss")
    app_client.cookies.clear()
    return headers


def record_publishes(app_client, monkeypatch):
    sent: list[tuple[str, dict]] = []
    redis = app_client.app.state.redis
    real = redis.publish

    async def publish(channel, message):
        sent.append((channel, json.loads(message)))
        return await real(channel, message)

    monkeypatch.setattr(redis, "publish", publish)
    return sent


async def test_create_update_and_delete_each_announce_a_change_with_no_device_data(app_client, db, monkeypatch):
    headers = await admin(app_client, db)
    sent = record_publishes(app_client, monkeypatch)

    made = await app_client.post("/api/v1/devices", headers=headers, json={"name": "core-sw-1", "management_ip": "10.10.0.11", "device_type": "switch"})
    did = made.json()["device"]["id"]
    assert (await app_client.patch(f"/api/v1/devices/{did}", headers=headers, json={"name": "core-sw-2"})).status_code == 200
    assert (await app_client.delete(f"/api/v1/devices/{did}", headers=headers, params={"confirm": "core-sw-2"})).status_code == 200

    assert sent == [
        (REALTIME_CHANNEL, {"name": "devices.changed", "data": {"action": "created"}}),
        (REALTIME_CHANNEL, {"name": "devices.changed", "data": {"action": "updated"}}),
        (REALTIME_CHANNEL, {"name": "devices.changed", "data": {"action": "deleted"}}),
    ]
    for _, message in sent:
        assert did not in json.dumps(message) and "core-sw" not in json.dumps(message) and "10.10.0.11" not in json.dumps(message)


async def test_a_refused_change_announces_nothing(app_client, db, monkeypatch):
    headers = await admin(app_client, db)
    did = (await app_client.post("/api/v1/devices", headers=headers, json={"name": "sw", "management_ip": "10.10.0.12", "device_type": "switch"})).json()["device"]["id"]
    sent = record_publishes(app_client, monkeypatch)

    assert (await app_client.delete(f"/api/v1/devices/{did}", headers=headers, params={"confirm": "wrong"})).status_code == 422
    assert (await app_client.patch("/api/v1/devices/00000000-0000-0000-0000-000000000000", headers=headers, json={"name": "x"})).status_code == 404
    assert (await app_client.patch(f"/api/v1/devices/{did}", headers=headers, json={"device_type": "bridge"})).status_code == 422
    assert (await app_client.post("/api/v1/devices", headers=headers, json={"name": "", "management_ip": "10.10.0.13", "device_type": "switch"})).status_code == 422
    assert sent == []


async def test_a_redis_outage_does_not_fail_the_change(app_client, db, monkeypatch):
    headers = await admin(app_client, db)

    async def broken(*args, **kwargs):
        raise RedisError("down")

    monkeypatch.setattr(app_client.app.state.redis, "publish", broken)
    made = await app_client.post("/api/v1/devices", headers=headers, json={"name": "sw", "management_ip": "10.10.0.14", "device_type": "switch"})
    assert made.status_code == 201
    did = made.json()["device"]["id"]
    assert (await app_client.patch(f"/api/v1/devices/{did}", headers=headers, json={"name": "sw2"})).status_code == 200
    assert (await app_client.delete(f"/api/v1/devices/{did}", headers=headers, params={"confirm": "sw2"})).status_code == 200
    assert await db.fetchval("select count(*) from devices") == 0


def test_the_frontend_listens_on_the_exact_channel_the_backend_publishes():
    """A wildcard would also be refused for scoped users (app/realtime/permissions.py), so it must be the exact name."""
    import re
    from pathlib import Path

    from app.realtime.bus import DEVICES_CHANGED

    candidates = [Path(__file__).resolve().parents[2] / "frontend/src/views/devices/deviceList.ts", Path("/repo/frontend/src/views/devices/deviceList.ts")]
    source = next((p for p in candidates if p.is_file()), None)
    if source is None:
        import pytest

        pytest.skip("frontend/ is not mounted in this test environment")
    match = re.search(r"export const DEVICES_CHANGED = '([^']+)';", source.read_text())
    assert match and match.group(1) == DEVICES_CHANGED == "devices.changed"


async def test_group_changes_announce_the_same_data_free_notice_and_refusals_announce_nothing(app_client, db, monkeypatch):
    """Group names show on the device list, so a group change reloads it too."""
    headers = await admin(app_client, db)
    sent = record_publishes(app_client, monkeypatch)

    made = await app_client.post("/api/v1/device-groups", headers=headers, json={"name": "Core", "description": "core ring"})
    gid = made.json()["id"]
    assert (await app_client.post("/api/v1/device-groups", headers=headers, json={"name": "Core"})).status_code == 409
    assert (await app_client.patch(f"/api/v1/device-groups/{gid}", headers=headers, json={"name": "Core ring"})).status_code == 200
    assert (await app_client.patch(f"/api/v1/device-groups/{gid}", headers=headers, json={"parent_id": gid})).status_code == 409
    assert (await app_client.delete("/api/v1/device-groups/00000000-0000-0000-0000-000000000000", headers=headers)).status_code == 404
    assert (await app_client.delete(f"/api/v1/device-groups/{gid}", headers=headers)).status_code == 200

    assert [message for _, message in sent] == [
        {"name": "devices.changed", "data": {"action": "group_created"}},
        {"name": "devices.changed", "data": {"action": "group_updated"}},
        {"name": "devices.changed", "data": {"action": "group_deleted"}},
    ]
    assert gid not in json.dumps(sent) and "Core" not in json.dumps(sent)
