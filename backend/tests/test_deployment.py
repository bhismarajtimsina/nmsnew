"""Static checks against the files docker-compose.cybersathy.yml and the CLI use to start real workers.

The scheduler tests instantiate Scheduler directly, which proves the scheduler works but not that a deployed worker
container ever calls it. This is the test that would have caught the worker container never running the scheduler kind.
"""
import os
import re
from pathlib import Path

import pytest

from app.workers.runner import KINDS

# The test container only mounts backend/, not the repository root, so the compose file is not always reachable here.
# tools/test-backend.sh and CI mount or check out the full repository; skip only when it is genuinely absent.
_CANDIDATES = [Path(__file__).resolve().parents[2] / "docker-compose.cybersathy.yml", Path("/repo/docker-compose.cybersathy.yml")]
COMPOSE = next((p for p in _CANDIDATES if p.is_file()), None)
MAIN = Path(__file__).resolve().parents[1] / "app" / "workers" / "__main__.py"


def _worker_kinds(text: str, pattern: str) -> set[str]:
    match = re.search(pattern, text)
    assert match, f"could not find the worker kinds argument using {pattern!r}"
    return {k.strip() for k in match.group(1).split(",") if k.strip()}


def test_the_deployed_worker_runs_every_kind_the_stack_needs():
    if COMPOSE is None:
        pytest.skip("docker-compose.cybersathy.yml is not mounted in this test environment")
    kinds = _worker_kinds(COMPOSE.read_text(), r'"--kinds",\s*"([a-z,]+)"')
    assert kinds == KINDS, "the compose worker command must list every kind in app.workers.runner.KINDS, including scheduler"


def test_the_cli_default_also_runs_every_kind():
    kinds = _worker_kinds(MAIN.read_text(), r'WORKER_KINDS",\s*"([a-z,]+)"')
    assert kinds == KINDS
