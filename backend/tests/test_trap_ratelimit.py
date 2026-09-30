"""The trap receiver's flood protection (Plan 21, risk K-15), driven by a fake clock - no socket, no database."""
import pytest

from app.traps.ratelimit import TrapRateLimiter


class Clock:
    def __init__(self) -> None:
        self.now = 1000.0

    def __call__(self) -> float:
        return self.now


def limiter(clock, **kw) -> TrapRateLimiter:
    args = dict(source_rate=1, source_burst=3, global_rate=100, global_burst=100, clock=clock)
    args.update(kw)
    return TrapRateLimiter(**args)


def test_a_source_gets_its_burst_then_is_refused():
    lim = limiter(Clock())
    assert [lim.allow("10.0.0.1") for _ in range(4)] == [None, None, None, "source"]


def test_a_refused_source_recovers_at_its_rate():
    clock = Clock()
    lim = limiter(clock)
    for _ in range(3):
        lim.allow("10.0.0.1")
    assert lim.allow("10.0.0.1") == "source"
    clock.now += 1.0  # source_rate=1/s: exactly one token back
    assert lim.allow("10.0.0.1") is None
    assert lim.allow("10.0.0.1") == "source"


def test_refill_never_exceeds_the_burst():
    clock = Clock()
    lim = limiter(clock)
    lim.allow("10.0.0.1")
    clock.now += 3600
    assert [lim.allow("10.0.0.1") for _ in range(4)] == [None, None, None, "source"]


def test_one_flooding_source_does_not_affect_another():
    lim = limiter(Clock())
    for _ in range(50):
        lim.allow("10.0.0.1")
    assert lim.allow("10.0.0.2") is None


def test_the_global_cap_applies_across_sources():
    lim = limiter(Clock(), global_burst=5, global_rate=1)
    results = [lim.allow(f"10.0.0.{i}") for i in range(1, 8)]
    assert results == [None] * 5 + ["global", "global"]


def test_a_per_source_refusal_does_not_spend_the_global_budget():
    """Otherwise one noisy device, refused by its own limit, would still drain the shared cap for everyone else."""
    lim = limiter(Clock(), source_burst=1, global_burst=3, global_rate=1)
    assert lim.allow("10.0.0.1") is None
    for _ in range(20):
        assert lim.allow("10.0.0.1") == "source"
    assert lim.allow("10.0.0.2") is None
    assert lim.allow("10.0.0.3") is None


def test_a_global_refusal_does_not_spend_the_sources_own_budget():
    clock = Clock()
    # The source refills so slowly that its own budget is exactly what it had: any token spent on a global refusal
    # would still be missing afterwards.
    lim = limiter(clock, source_rate=0.001, source_burst=2, global_burst=5, global_rate=5)
    for i in range(5):
        assert lim.allow(f"10.0.0.{10 + i}") is None  # spend the whole global budget elsewhere
    assert lim.allow("10.0.0.1") == "global"
    assert lim.allow("10.0.0.1") == "global"
    clock.now += 1.0  # global back to 5; 10.0.0.1 never spent anything, so it still has both of its 2
    assert lim.allow("10.0.0.1") is None
    assert lim.allow("10.0.0.1") is None


def test_idle_sources_are_forgotten_so_memory_stays_bounded():
    clock = Clock()
    lim = limiter(clock, max_sources=10)
    for i in range(10):
        lim.allow(f"10.0.1.{i}")
    clock.now += 10  # every bucket has refilled to its burst
    lim.allow("10.0.2.1")
    assert lim.tracked_sources == 1


def test_a_source_still_being_limited_is_not_forgotten():
    """Pruning must never hand a flooding source a fresh burst."""
    clock = Clock()
    lim = limiter(clock, max_sources=2)
    for _ in range(3):
        lim.allow("10.0.0.1")
    lim.allow("10.0.0.2")
    lim.allow("10.0.0.3")  # triggers a prune: 10.0.0.1 is empty, 10.0.0.2 is not full either
    assert lim.allow("10.0.0.1") == "source"


def test_the_sweep_is_throttled_when_nothing_can_be_pruned():
    """A spoofed flood of new addresses must not make every packet scan the whole table."""
    clock = Clock()
    lim = limiter(clock, max_sources=2, prune_interval=1.0)
    sweeps = []
    original = lim._prune
    lim._prune = lambda now: (sweeps.append(now), original(now))
    for i in range(50):
        lim.allow(f"10.0.3.{i}")
    assert len(sweeps) == 1
    clock.now += 1.0
    lim.allow("10.0.4.1")
    assert len(sweeps) == 2


@pytest.mark.parametrize("field", ["source_rate", "source_burst", "global_rate", "global_burst"])
def test_non_positive_limits_are_refused(field):
    with pytest.raises(ValueError):
        limiter(Clock(), **{field: 0})
