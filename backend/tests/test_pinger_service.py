"""Ping target selection, debounce persistence and the gauge - against a real database. Only ever pings loopback for
real: the "down" and error cases use a fake `async_ping`, exactly the "fake ICMP transport" own-components.md's own
verification section calls for, since a real send to any non-loopback address (even a reserved, unassigned one)
would still leave this sandbox attempting real network egress - icmplib's sockets use sendto(), which the test
harness's connect()-based network guard does not intercept, so nothing else catches that but discipline here."""
from types import SimpleNamespace

import pytest

from app.pinger import service
from tests.helpers import make_device


async def _enable(db, device_id: str) -> None:
    await db.execute("update devices set polling_enabled = true, polling_owner = 'cybersathy' where id = $1::uuid", device_id)


async def test_list_targets_is_enabled_and_owned_by_cybersathy_only(db):
    mine = await make_device(db, "mine", ip="10.70.0.1")
    await _enable(db, mine)
    await make_device(db, "legacy-owned", ip="10.70.0.2")  # default polling_owner is 'legacy'
    disabled = await make_device(db, "disabled", ip="10.70.0.3")
    await db.execute("update devices set polling_owner = 'cybersathy', polling_enabled = false where id = $1::uuid", disabled)

    targets = await service.list_targets(db)
    assert {t.device_id for t in targets} == {mine}


async def test_a_real_loopback_ping_is_recorded_as_up(db):
    device = await make_device(db, "self", ip="127.0.0.1")
    await _enable(db, device)
    alive = await service.check_one(db, service.Target(device, "self", "127.0.0.1"), count=1, timeout=1.0, misses_for_down=3, privileged=False)
    assert alive is True
    row = await db.fetchrow("select status, consecutive_misses, latency_ms from device_ping_status where device_id = $1::uuid", device)
    assert row["status"] == "up" and row["consecutive_misses"] == 0 and row["latency_ms"] is not None
    history = await db.fetchrow("select status from device_ping_history where device_id = $1::uuid", device)
    assert history["status"] == "up"  # unknown -> up is a real transition, worth recording


async def test_a_miss_below_the_threshold_does_not_record_history_or_flip_status(db, monkeypatch):
    device = await make_device(db, "flaky", ip="10.70.0.4")
    monkeypatch.setattr(service, "async_ping", _fake_ping(alive=False))
    target = service.Target(device, "flaky", "10.70.0.4")
    alive = await service.check_one(db, target, count=1, timeout=1.0, misses_for_down=3, privileged=False)
    assert alive is False
    row = await db.fetchrow("select status, consecutive_misses from device_ping_status where device_id = $1::uuid", device)
    assert row["status"] == "unknown" and row["consecutive_misses"] == 1
    assert await db.fetchval("select count(*) from device_ping_history where device_id = $1::uuid", device) == 0


async def test_down_after_the_configured_consecutive_misses_is_recorded_once(db, monkeypatch):
    device = await make_device(db, "downed", ip="10.70.0.5")
    monkeypatch.setattr(service, "async_ping", _fake_ping(alive=False))
    target = service.Target(device, "downed", "10.70.0.5")
    for _ in range(3):
        await service.check_one(db, target, count=1, timeout=1.0, misses_for_down=3, privileged=False)
    row = await db.fetchrow("select status from device_ping_status where device_id = $1::uuid", device)
    assert row["status"] == "down"
    assert await db.fetchval("select count(*) from device_ping_history where device_id = $1::uuid", device) == 1


async def test_a_ping_error_is_treated_as_a_miss_not_a_crash(db, monkeypatch):
    device = await make_device(db, "erroring", ip="10.70.0.6")

    async def _raise(*args, **kwargs):
        raise OSError("name resolution failed")

    monkeypatch.setattr(service, "async_ping", _raise)
    alive = await service.check_one(db, service.Target(device, "erroring", "10.70.0.6"), count=1, timeout=1.0, misses_for_down=3, privileged=False)
    assert alive is False
    row = await db.fetchrow("select consecutive_misses from device_ping_status where device_id = $1::uuid", device)
    assert row["consecutive_misses"] == 1


async def test_run_cycle_summarizes_up_and_down_targets(db, monkeypatch):
    up_device = await make_device(db, "up1", ip="127.0.0.1")
    down_device = await make_device(db, "down1", ip="10.70.0.7")
    await _enable(db, up_device)
    await _enable(db, down_device)

    real_ping = service.async_ping

    async def _selective(ip, **kwargs):
        if ip == "127.0.0.1":
            return await real_ping(ip, **kwargs)
        return SimpleNamespace(is_alive=False, avg_rtt=None)

    monkeypatch.setattr(service, "async_ping", _selective)
    summary = await service.run_cycle(db, count=1, timeout=1.0, misses_for_down=3, privileged=False)
    assert summary == {"targets": 2, "up": 1, "down": 1}


def _fake_ping(*, alive: bool, latency: float | None = None):
    async def _ping(ip, **kwargs):
        return SimpleNamespace(is_alive=alive, avg_rtt=latency)
    return _ping
