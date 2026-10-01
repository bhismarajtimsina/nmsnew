import pytest

from app.polling.fake import FakeTransport
from app.polling.transport import (
    MAX_GET_OIDS,
    MAX_ROWS_HARD,
    BoundedTransport,
    Credentials,
    DisabledTransport,
    Target,
    TransportDisabled,
    UnboundedRequest,
)

TARGET = Target("10.0.0.1", Credentials("v2c", community="hunter2-community"))


def test_secrets_never_appear_in_a_repr_or_a_log_line():
    text = repr(TARGET) + repr(TARGET.credentials) + str(TARGET.credentials)
    assert "hunter2" not in text and "community" not in text.replace("community=", "")
    assert TARGET.credentials.secrets() == ["hunter2-community"]


async def test_the_disabled_transport_refuses_everything():
    transport = DisabledTransport()
    with pytest.raises(TransportDisabled):
        await transport.get(TARGET, ["1.3.6.1.2.1.1.5.0"], timeout_ms=1000, retries=0)
    with pytest.raises(TransportDisabled):
        await transport.walk(TARGET, "1.3.6.1.2.1.2.2", max_rows=10, timeout_ms=1000, retries=0)


async def test_a_walk_must_have_a_row_limit_and_a_timeout():
    fake = FakeTransport()
    bounded = BoundedTransport(fake)
    for rows, timeout in ((None, 1000), (1000, None), (None, None), (0, 1000), (MAX_ROWS_HARD + 1, 1000), (-5, 1000)):
        with pytest.raises(UnboundedRequest):
            await bounded.walk(TARGET, "1.3.6.1.2.1.2.2", max_rows=rows, timeout_ms=timeout, retries=0)
    assert fake.calls == []            # nothing was sent for any of them


async def test_a_get_is_limited_in_size_and_must_ask_for_something():
    fake = FakeTransport()
    bounded = BoundedTransport(fake)
    with pytest.raises(UnboundedRequest):
        await bounded.get(TARGET, [], timeout_ms=1000, retries=0)
    with pytest.raises(UnboundedRequest):
        await bounded.get(TARGET, [f"1.3.6.1.2.1.1.{i}.0" for i in range(MAX_GET_OIDS + 1)], timeout_ms=1000, retries=0)
    assert fake.calls == []


async def test_a_transport_that_ignores_the_limit_is_cut_short_and_flagged():
    fake = FakeTransport()
    fake.ignore_walk_limit = True
    fake.script_table("10.0.0.1", "1.3.6.1.2.1.2.2", [(f"1.3.6.1.2.1.2.2.{i}", i) for i in range(50)])
    bounded = BoundedTransport(fake)
    rows = await bounded.walk(TARGET, "1.3.6.1.2.1.2.2", max_rows=10, timeout_ms=1000, retries=0)
    assert len(rows) == 10 and bounded.truncated is True and bounded.rows_returned == 10


async def test_timeouts_and_retries_are_clamped_into_a_safe_range():
    fake = FakeTransport()
    bounded = BoundedTransport(fake)
    await bounded.get(TARGET, ["1.3.6.1.2.1.1.5.0"], timeout_ms=999_999, retries=99)
    await bounded.get(TARGET, ["1.3.6.1.2.1.1.5.0"], timeout_ms=1, retries=-4)
    assert fake.get_limits == [("10.0.0.1", 30_000, 3), ("10.0.0.1", 200, 0)]
    assert [r.timeout_ms for r in bounded.requests] == [30_000, 200]


async def test_a_well_behaved_transport_that_stops_at_the_limit_still_shows_the_table_was_cut():
    """Plan 19: a table larger than max_rows is truncated and flagged. One probe row past the limit is how a cut table
    is told apart from one that fits exactly."""
    fake = FakeTransport()
    fake.script_table("10.0.0.1", "1.3.6.1.2.1.4.22", [(f"1.3.6.1.2.1.4.22.{i}", i) for i in range(50)])
    bounded = BoundedTransport(fake)
    rows = await bounded.walk(TARGET, "1.3.6.1.2.1.4.22", max_rows=10, timeout_ms=1000, retries=0)
    assert len(rows) == 10 and bounded.truncated is True and bounded.truncated_roots == ["1.3.6.1.2.1.4.22"]
    assert fake.walk_limits[0][2] == 11


async def test_a_table_that_fits_exactly_is_not_flagged():
    fake = FakeTransport()
    fake.script_table("10.0.0.1", "1.3.6.1.2.1.4.22", [(f"1.3.6.1.2.1.4.22.{i}", i) for i in range(10)])
    bounded = BoundedTransport(fake)
    rows = await bounded.walk(TARGET, "1.3.6.1.2.1.4.22", max_rows=10, timeout_ms=1000, retries=0)
    assert len(rows) == 10 and bounded.truncated is False and bounded.truncated_roots == []


async def test_a_read_that_wants_only_the_first_row_asks_for_no_probe_and_is_not_flagged():
    fake = FakeTransport()
    fake.script_table("10.0.0.1", "1.3.6.1.2.1.1", [(f"1.3.6.1.2.1.1.{i}", i) for i in range(5)])
    bounded = BoundedTransport(fake)
    rows = await bounded.walk(TARGET, "1.3.6.1.2.1.1", max_rows=1, timeout_ms=1000, retries=0, probe=False)
    assert len(rows) == 1 and bounded.truncated is False and fake.walk_limits[0][2] == 1
