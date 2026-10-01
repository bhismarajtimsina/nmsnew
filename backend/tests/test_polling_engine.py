import asyncio

import pytest

from app.core.config import settings
from app.polling.engine import poll_device
from tests.polling_helpers import COMMUNITY, SYS_NAME, ctx, make_access_profile, make_active_profile, make_pollable  # noqa: F401

RX_OK, RX_BAD = "1.3.6.1.4.1.9999.1.1", "1.3.6.1.4.1.9999.1.2"
IFDESCR = "1.3.6.1.2.1.2.2.1.2"
ENTRIES = [
    ("sys_name", SYS_NAME, "get", None, None, None),
    ("rx_ok", RX_OK, "get", None, None, (0.01, -60, 10)),
    ("rx_bad", RX_BAD, "get", None, None, (0.01, -60, 10)),
    ("if_descr", IFDESCR, "walk", 3, 4000, None),
]


async def results(db):
    return await db.fetch("select outcome, error, rows, truncated, profile_version from polling_results order by started_at")


async def test_a_poll_reads_bounds_the_walk_applies_scale_and_flags_out_of_range_values(ctx, db):
    await make_active_profile(db, entries=ENTRIES)
    device = await make_pollable(db, "10.50.0.1")
    # Scalars are answered at their .0 instance, as a real agent does (see engine.scalar_instance).
    ctx.transport.script_get("10.50.0.1", {SYS_NAME: "core-1", RX_OK + ".0": -1500, RX_BAD + ".0": 5000})
    ctx.transport.script_table("10.50.0.1", IFDESCR, [(f"{IFDESCR}.{i}", f"Gi0/{i}") for i in range(1, 8)])

    class Sink:
        got = None

        async def write(self, device_id, profile, readings):
            Sink.got = readings

    outcome = await poll_device(ctx, device, "switch_basic", Sink())
    assert outcome.status == "ok" and outcome.rows == 6 and outcome.truncated is True and outcome.profile_version == 1  # 7 rows, cap 3
    by_name = {r.name.split(".")[-1]: r for r in Sink.got}
    assert by_name["sys_name"].value == "core-1"
    assert by_name["rx_ok"].value == pytest.approx(-15.0) and by_name["rx_ok"].in_range is True
    assert by_name["rx_bad"].value == pytest.approx(50.0) and by_name["rx_bad"].in_range is False     # flagged, not trusted
    assert len(by_name["if_descr"].value) == 3                                                          # the row limit held
    row = (await results(db))[0]
    assert (row["outcome"], row["rows"], row["profile_version"]) == ("ok", 6, 1)
    state = await db.fetchrow("select consecutive_failures, last_success_at from device_poll_state where device_id = $1::uuid", device)
    assert state["consecutive_failures"] == 0 and state["last_success_at"] is not None


async def test_every_request_the_device_sees_carries_the_profiles_limits(ctx, db):
    await make_active_profile(db, entries=ENTRIES)
    device = await make_pollable(db, "10.50.0.1")
    await poll_device(ctx, device, "switch_basic")
    assert ctx.transport.walk_limits == [("10.50.0.1", IFDESCR, 3 + 1, 4000)]  # the cap plus one probe row
    (address, timeout, retries), = ctx.transport.get_limits
    assert (address, timeout, retries) == ("10.50.0.1", 2000, 1)


async def test_a_transport_that_over_delivers_is_truncated_and_the_poll_says_so(ctx, db):
    await make_active_profile(db, entries=[("if_descr", IFDESCR, "walk", 4, 4000, None)])
    device = await make_pollable(db, "10.50.0.1")
    ctx.transport.ignore_walk_limit = True
    ctx.transport.script_table("10.50.0.1", IFDESCR, [(f"{IFDESCR}.{i}", i) for i in range(500)])
    outcome = await poll_device(ctx, device, "switch_basic")
    assert outcome.status == "ok" and outcome.rows == 4 and outcome.truncated is True
    assert (await results(db))[0]["truncated"] is True


@pytest.mark.parametrize("setup,reason", [
    ({"owner": "legacy"}, "legacy system"),
    ({"enabled": False}, "switched off for this device"),
    ({"with_profile": False}, "no access profile"),
])
async def test_a_device_that_may_not_be_polled_is_never_contacted_and_the_reason_is_recorded(ctx, db, setup, reason):
    await make_active_profile(db)
    device = await make_pollable(db, "10.50.0.1", **setup)
    outcome = await poll_device(ctx, device, "switch_basic")
    assert outcome.status == "skipped" and reason in outcome.error
    assert ctx.transport.calls == []
    row = (await results(db))[0]
    assert row["outcome"] == "skipped" and reason in row["error"]


async def test_the_vendor_and_family_kill_switches_stop_polling_at_once(ctx, db):
    await make_active_profile(db)
    device = await make_pollable(db, "10.50.0.1")
    await db.execute("update vendor_model_families set polling_enabled = false, polling_disabled_reason = 'incident' where slug = 'bdcom-switch'")
    assert "vendor or model family" in (await poll_device(ctx, device, "switch_basic")).error
    await db.execute("update vendor_model_families set polling_enabled = true, polling_disabled_reason = null where slug = 'bdcom-switch'")
    await db.execute("update vendors set polling_enabled = false, polling_disabled_reason = 'incident' where slug = 'bdcom'")
    assert "vendor or model family" in (await poll_device(ctx, device, "switch_basic")).error
    assert ctx.transport.calls == []


async def test_only_an_active_profile_that_fits_the_device_is_used(ctx, db):
    await make_active_profile(db, "draft_only", activate=False)
    await make_active_profile(db, "for_zte", vendor_slug="zte")
    device = await make_pollable(db, "10.50.0.1")           # a BDCOM switch
    for name in ("draft_only", "for_zte", "does_not_exist"):
        outcome = await poll_device(ctx, device, name)
        assert outcome.status == "skipped" and "no active profile" in outcome.error, name
    assert ctx.transport.calls == []


async def test_a_device_is_not_polled_more_often_than_the_minimum_interval(ctx, db, monkeypatch):
    monkeypatch.setattr(settings, "poll_min_interval_seconds", 30)
    await make_active_profile(db)
    device = await make_pollable(db, "10.50.0.1")
    ctx.transport.script_get("10.50.0.1", {SYS_NAME: "x"})
    assert (await poll_device(ctx, device, "switch_basic")).status == "ok"
    second = await poll_device(ctx, device, "switch_basic")
    assert second.status == "skipped" and "less than 30 seconds" in second.error
    assert len(ctx.transport.calls) == 1
    await db.execute("update device_poll_state set last_polled_at = now() - interval '31 seconds'")
    assert (await poll_device(ctx, device, "switch_basic")).status == "ok"


async def test_the_circuit_breaker_opens_after_repeated_timeouts_and_probes_after_the_cooldown(ctx, db, monkeypatch):
    monkeypatch.setattr(settings, "poll_breaker_threshold", 3)
    await make_active_profile(db)
    device = await make_pollable(db, "10.50.0.1")
    ctx.transport.timeouts.add("10.50.0.1")
    for _ in range(3):
        assert (await poll_device(ctx, device, "switch_basic")).status == "timeout"
    assert len(ctx.transport.calls) == 3
    blocked = await poll_device(ctx, device, "switch_basic")
    assert blocked.status == "skipped" and "circuit breaker open" in blocked.error
    assert len(ctx.transport.calls) == 3                    # a dead device is no longer asked
    state = await db.fetchrow("select consecutive_failures, breaker_open_until > now() as open from device_poll_state")
    assert state["consecutive_failures"] == 3 and state["open"] is True

    await db.execute("update device_poll_state set breaker_open_until = now() - interval '1 second'")   # the cool-down has passed
    assert (await poll_device(ctx, device, "switch_basic")).status == "timeout"                         # one probe
    reopened = await db.fetchrow("select consecutive_failures, breaker_open_until > now() as open from device_poll_state")
    assert reopened["open"] is True and reopened["consecutive_failures"] == 4                           # a failed probe closes the door again

    await db.execute("update device_poll_state set breaker_open_until = now() - interval '1 second'")
    ctx.transport.timeouts.clear()
    ctx.transport.script_get("10.50.0.1", {SYS_NAME: "back"})
    assert (await poll_device(ctx, device, "switch_basic")).status == "ok"
    healed = await db.fetchrow("select consecutive_failures, breaker_open_until from device_poll_state")
    assert healed["consecutive_failures"] == 0 and healed["breaker_open_until"] is None


async def test_the_first_timeout_ends_the_poll_so_a_dead_device_is_asked_once(ctx, db):
    await make_active_profile(db, entries=ENTRIES)
    device = await make_pollable(db, "10.50.0.1")
    ctx.transport.timeouts.add("10.50.0.1")
    outcome = await poll_device(ctx, device, "switch_basic")
    assert outcome.status == "timeout" and len(ctx.transport.calls) == 1 and ctx.transport.walk_limits == []


async def test_a_dead_device_does_not_hold_up_a_healthy_one(ctx, db):
    await make_active_profile(db)
    dead, alive = await make_pollable(db, "10.50.0.1"), await make_pollable(db, "10.50.0.2")
    ctx.transport.timeouts.add("10.50.0.1")
    ctx.transport.script_get("10.50.0.2", {SYS_NAME: "fine"})
    ctx.transport.delay = 0.05
    first, second = await asyncio.gather(poll_device(ctx, dead, "switch_basic"), poll_device(ctx, alive, "switch_basic"))
    assert (first.status, second.status) == ("timeout", "ok")


async def test_two_polls_of_one_device_never_overlap(ctx, db):
    await make_active_profile(db)
    device = await make_pollable(db, "10.50.0.1")
    ctx.transport.script_get("10.50.0.1", {SYS_NAME: "x"})
    ctx.transport.delay = 0.1
    a, b = await asyncio.gather(poll_device(ctx, device, "switch_basic"), poll_device(ctx, device, "switch_basic"))
    assert sorted([a.status, b.status]) == ["ok", "skipped"]
    assert "in progress" in (a.error or b.error) and len(ctx.transport.calls) == 1


async def test_one_vendor_cannot_take_every_slot(ctx, db, monkeypatch):
    monkeypatch.setattr(settings, "poll_vendor_concurrency", 1)
    await make_active_profile(db)
    first, second = await make_pollable(db, "10.50.0.1"), await make_pollable(db, "10.50.0.2")
    ctx.transport.delay = 0.1
    outcomes = await asyncio.gather(poll_device(ctx, first, "switch_basic"), poll_device(ctx, second, "switch_basic"))
    assert sorted(o.status for o in outcomes) == ["ok", "skipped"]
    assert any("too many concurrent polls for vendor bdcom" in (o.error or "") for o in outcomes)


async def test_credentials_reach_the_transport_but_never_the_stored_error(ctx, db):
    await make_active_profile(db)
    device = await make_pollable(db, "10.50.0.1")
    ctx.transport.errors["10.50.0.1"] = f"authentication failed for community {COMMUNITY} on 10.50.0.1"
    outcome = await poll_device(ctx, device, "switch_basic")
    assert ctx.transport.seen_communities == [COMMUNITY]           # decrypted in memory, used for the poll
    assert outcome.status == "error" and COMMUNITY not in outcome.error and "[redacted]" in outcome.error
    stored = await db.fetchval("select string_agg(coalesce(error, ''), ' ') from polling_results")
    state = await db.fetchval("select last_error from device_poll_state")
    assert COMMUNITY not in stored and COMMUNITY not in state


async def test_a_poll_without_credential_encryption_fails_safely_without_contacting_the_device(ctx, db):
    await make_active_profile(db)
    device = await make_pollable(db, "10.50.0.1")
    ctx.enc = None
    outcome = await poll_device(ctx, device, "switch_basic")
    assert outcome.status == "error" and "encryption" in outcome.error and ctx.transport.calls == []


async def test_an_unknown_device_is_reported_not_crashed(ctx, db):
    outcome = await poll_device(ctx, "00000000-0000-0000-0000-000000000000", "switch_basic")
    assert outcome.status == "skipped" and outcome.error == "device not found"


async def test_the_sink_only_receives_readings_from_a_successful_poll(ctx, db):
    await make_active_profile(db)
    device = await make_pollable(db, "10.50.0.1")
    ctx.transport.timeouts.add("10.50.0.1")
    calls = []

    class Sink:
        async def write(self, *args):
            calls.append(args)

    await poll_device(ctx, device, "switch_basic", Sink())
    assert calls == []


async def test_a_cut_table_is_counted_in_the_truncation_metric_per_profile(ctx, db):
    from app.polling.engine import POLL_TRUNCATIONS

    await make_active_profile(db, name="router_arp", entries=[("arp", "1.3.6.1.2.1.4.22.1.2", "walk", 5, 4000, None)])
    device = await make_pollable(db, "10.50.0.9")
    ctx.transport.script_table("10.50.0.9", "1.3.6.1.2.1.4.22.1.2", [(f"1.3.6.1.2.1.4.22.1.2.{i}", i) for i in range(20)])
    before = POLL_TRUNCATIONS.labels(profile="router_arp")._value.get()
    outcome = await poll_device(ctx, device, "router_arp")
    assert outcome.status == "ok" and outcome.rows == 5 and outcome.truncated is True
    assert outcome.truncated_columns == ["1.3.6.1.2.1.4.22.1.2"]
    assert POLL_TRUNCATIONS.labels(profile="router_arp")._value.get() == before + 1
    assert (await results(db))[0]["truncated"] is True


async def test_a_table_within_its_cap_is_not_counted(ctx, db):
    from app.polling.engine import POLL_TRUNCATIONS

    await make_active_profile(db, name="router_arp", entries=[("arp", "1.3.6.1.2.1.4.22.1.2", "walk", 5, 4000, None)])
    device = await make_pollable(db, "10.50.0.10")
    ctx.transport.script_table("10.50.0.10", "1.3.6.1.2.1.4.22.1.2", [(f"1.3.6.1.2.1.4.22.1.2.{i}", i) for i in range(5)])
    before = POLL_TRUNCATIONS.labels(profile="router_arp")._value.get()
    outcome = await poll_device(ctx, device, "router_arp")
    assert outcome.truncated is False and outcome.truncated_columns == []
    assert POLL_TRUNCATIONS.labels(profile="router_arp")._value.get() == before


async def test_a_getnext_entry_reads_one_row_without_a_probe_and_is_not_truncation(ctx, db):
    await make_active_profile(db, name="first_row", entries=[("first", IFDESCR, "getnext", None, 4000, None)])
    device = await make_pollable(db, "10.50.0.11")
    ctx.transport.script_table("10.50.0.11", IFDESCR, [(f"{IFDESCR}.{i}", i) for i in range(1, 9)])
    outcome = await poll_device(ctx, device, "first_row")
    assert outcome.status == "ok" and outcome.rows == 1 and outcome.truncated is False
    assert ctx.transport.walk_limits == [("10.50.0.11", IFDESCR, 1, 4000)]
