"""frontend/src/auth/menu.ts (Plan 25) names the permissions that show each sidebar entry. A code the API does not
define would hide that entry from everyone, so every code there must be in the permission catalogue."""
import re
from pathlib import Path

import pytest

from app.access.catalogue import all_permissions

_CANDIDATES = [Path(__file__).resolve().parents[2] / "frontend/src/auth/menu.ts", Path("/repo/frontend/src/auth/menu.ts")]
_menu = next((p for p in _CANDIDATES if p.is_file()), None)
if _menu is None:
    pytest.skip("frontend/ is not mounted in this test environment", allow_module_level=True)


def menu_codes() -> set[str]:
    rules = _menu.read_text().split("export const MENU_RULES", 1)[1].split("export const SUBMENUS", 1)[0]
    return set(re.findall(r"'([a-z_]+(?:\.[a-z_]+)+)'", rules))


def test_every_menu_permission_is_one_the_api_defines():
    codes = menu_codes()
    assert len(codes) > 30
    assert codes - {code for code, *_ in all_permissions()} == set()
