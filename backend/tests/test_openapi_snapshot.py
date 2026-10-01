"""The frontend's TypeScript types are generated from frontend/src/api/openapi.json (Plan 24). This fails when that
committed copy no longer matches the API, so the client and the server cannot drift apart unnoticed. Fix: run
`python -m app.cli openapi --write`, then `npm run gen:api` in frontend/."""
from pathlib import Path

import pytest

from app import cli

_CANDIDATES = [Path(__file__).resolve().parents[2] / "frontend/src/api/openapi.json", Path("/repo/frontend/src/api/openapi.json")]
_snapshot = next((p for p in _CANDIDATES if p.is_file()), None)


def test_the_committed_openapi_schema_matches_the_api():
    if _snapshot is None:
        pytest.skip("frontend/ is not mounted in this test environment")
    assert _snapshot.read_text(encoding="utf-8") == cli.openapi_text(), (
        "frontend/src/api/openapi.json is out of date: run `python -m app.cli openapi --write` and `npm run gen:api`"
    )


def test_internal_only_routes_stay_out_of_the_published_schema():
    """The Alertmanager webhook is service-token only (D-28) and not part of the frontend's API."""
    assert "/api/v1/webhooks/alertmanager" not in cli.openapi_text()
