"""Plan 12: the pinger's packets-per-second cap and concurrent passes. The pacer runs on a fake clock; passes use a
fake `async_ping`, so nothing is sent to any address."""
import asyncio
import inspect
from types import SimpleNamespace

import pytest

from app.pinger import service
from app.pinger.pacing import PacketPacer
from tests.helpers import make_device


class FakeTime:
    def __init__(self):
        self.now = 0.0
        self.slept: list[float] = []

    def clock(self):
        return self.now

    async def sleep(self, seconds):
        self.slept.append(seconds)
        self.now += seconds


def pacer(rate, burst):
    t = FakeTime()
    return PacketPacer(rate, burst, clock=t.clock, sleep=t.sleep), t


async def test_a_burst_goes_at_once_and_the_rest_waits_for_the_rate():
    p, t = pacer(rate=10, burst=3)
    await p.acquire(3)
    assert t.now == 0.0
    await p.acquire(3)  # 3 packets at 10 a second
    assert t.now == pytest.approx(0.3)
    await p.acquire(1)
    assert t.now == pytest.approx(0.4)


async def test_a_part_full_bucket_waits_only_for_what_is_missing():
    p, t = pacer(rate=10, burst=3)
    await p.acquire(3)
    t.now = 0.1  # one packet back
    await p.acquire(3)
    assert t.slept == [pytest.approx(0.2)]


async def test_probes_waiting_at_the_same_time_still_share_one_cap():
    """Waits that overlap in time, as real sleeps do: without serving probes one at a time, three probes would each
    wait only for their own packets and all send after the first wait."""
    now = [0.0]

    async def overlapping(seconds):
        until = now[0] + seconds
        await asyncio.sleep(0)
        now[0] = max(now[0], until)

    p = PacketPacer(1, 2, clock=lambda: now[0], sleep=overlapping)
    await p.acquire(2)
    await asyncio.gather(*(p.acquire(2) for _ in range(3)))
    assert now[0] >= 6.0  # 6 packets at 1 a second


async def test_time_that_passed_between_probes_is_credited():
    p, t = pacer(rate=10, burst=3)
    await p.acquire(3)
    t.now = 10.0  # long idle: refilled, but never above the burst
    await p.acquire(3)
    assert t.slept == []
    await p.acquire(1)
    assert t.now == pytest.approx(10.1)


async def test_a_pass_never_beats_the_cap():
    p, t = pacer(rate=50, burst=6)
    for _ in range(100):
        await p.acquire(3)
    assert t.now >= (100 * 3 - 6) / 50 - 1e-9


async def test_a_wait_the_clock_does_not_register_still_ends_and_is_owed_by_the_next_probe():
    """The first version re-checked the bucket after sleeping; a rounding shortfall then asked for a sleep too small
    for the clock to register, forever."""
    now = [0.0]

    async def no_progress(seconds):
        pass  # a clock that registers nothing

    p = PacketPacer(10, 3, clock=lambda: now[0], sleep=no_progress)
    await p.acquire(3)
    await asyncio.wait_for(p.acquire(3), timeout=1)  # returns rather than spinning
    assert p._bucket.tokens == pytest.approx(-3)  # owed, so the next probe waits for it


async def test_probes_are_served_in_the_order_they_asked():
    p, t = pacer(rate=1, burst=2)
    await p.acquire(2)
    order = []

    async def ask(name):
        await p.acquire(2)
        order.append(name)

    await asyncio.gather(*(ask(n) for n in "abc"))
    assert order == ["a", "b", "c"] and t.now == pytest.approx(6.0)


@pytest.mark.parametrize("rate,burst", [(0, 5), (-1, 5), (10, 0.5)])
def test_a_cap_that_can_never_be_met_is_refused(rate, burst):
    with pytest.raises(ValueError):
        PacketPacer(rate, burst)


@pytest.mark.parametrize("packets", [0, 4])
async def test_a_probe_bigger_than_the_burst_or_empty_is_refused(packets):
    p, _ = pacer(rate=10, burst=3)
    with pytest.raises(ValueError):
        await p.acquire(packets)


async def _targets(db, n, prefix):
    ids = []
    for i in range(1, n + 1):
        device = await make_device(db, f"{prefix}{i}", ip=f"10.71.{len(prefix)}.{i}")
        await db.execute("update devices set polling_enabled = true, polling_owner = 'cybersathy' where id = $1::uuid", device)
        ids.append(device)
    return ids


async def test_a_pass_pings_at_most_the_concurrency_at_once_and_records_each_result_against_its_device(db, monkeypatch):
    await _targets(db, 10, "c")
    in_flight = peak = 0

    async def slow_ping(ip, **kwargs):
        nonlocal in_flight, peak
        in_flight += 1
        peak = max(peak, in_flight)
        await asyncio.sleep(0.01 * (11 - int(ip.rsplit(".", 1)[1])))  # finish in the reverse of the start order
        in_flight -= 1
        return SimpleNamespace(is_alive=int(ip.rsplit(".", 1)[1]) % 2 == 0, avg_rtt=1.0)

    monkeypatch.setattr(service, "async_ping", slow_ping)
    summary = await service.run_cycle(db, count=1, concurrency=3)
    assert peak == 3 and summary == {"targets": 10, "up": 5, "down": 5, "changed": 5}
    rows = await db.fetch("select host(d.management_ip) as ip, p.status from device_ping_status p join devices d on d.id = p.device_id "
                          "where d.name like 'c%'")
    assert {r["ip"]: r["status"] for r in rows} == {f"10.71.1.{i}": ("up" if i % 2 == 0 else "unknown") for i in range(1, 11)}


async def test_every_probe_reserves_its_packets_from_the_pacer_first(db, monkeypatch):
    await _targets(db, 4, "pp")
    events = []

    class RecordingPacer:
        async def acquire(self, packets):
            events.append(("acquire", packets))

    async def ping(ip, **kwargs):
        events.append(("ping", ip))
        return SimpleNamespace(is_alive=True, avg_rtt=1.0)

    monkeypatch.setattr(service, "async_ping", ping)
    await service.run_cycle(db, count=3, concurrency=1, pacer=RecordingPacer())
    assert [e[0] for e in events] == ["acquire", "ping"] * 4 and all(e[1] == 3 for e in events if e[0] == "acquire")


async def test_a_dead_host_stores_no_latency_even_when_icmp_reports_zero(db, monkeypatch):
    (device,) = await _targets(db, 1, "dead")
    monkeypatch.setattr(service, "async_ping", lambda ip, **kw: _reply(False, 0.0))
    await service.run_cycle(db, count=1)
    assert await db.fetchval("select latency_ms from device_ping_status where device_id = $1::uuid", device) is None


async def _reply(alive, rtt):
    return SimpleNamespace(is_alive=alive, avg_rtt=rtt)


async def test_a_concurrency_below_one_is_refused(db):
    with pytest.raises(ValueError):
        await service.run_cycle(db, concurrency=0)


def test_the_running_pinger_uses_its_concurrency_and_one_pacer():
    from app.pinger import __main__ as pinger_main

    source = inspect.getsource(pinger_main.run)
    assert "concurrency=settings.pinger_concurrency, pacer=pacer" in source
    assert source.index("PacketPacer(") < source.index("while not stop.is_set()")  # one pacer, not one per pass
