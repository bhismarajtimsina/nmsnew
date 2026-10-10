"""Static guards. They fail the build when a route forgets to say who may call it, or when scope logic leaks into a router."""
import re
from pathlib import Path

from fastapi.routing import APIRoute

from app.access.catalogue import all_permissions
from app.core.config import settings

APP_DIR = Path(__file__).resolve().parents[1] / "app"

PUBLIC = {
    ("POST", "/api/v1/auth/login"),
    ("GET", "/api/v1/auth/verify"),
    ("GET", "/api/v1/health"),
}
AUTHENTICATED_ONLY = {
    ("GET", "/api/v1/auth/session"),
    ("POST", "/api/v1/auth/logout"),
    ("GET", "/api/v1/auth/sessions"),
    ("DELETE", "/api/v1/auth/sessions/{session_id}"),
    ("POST", "/api/v1/auth/password"),
    ("POST", "/api/v1/auth/2fa/enroll"),
    ("POST", "/api/v1/auth/2fa/enable"),
    ("POST", "/api/v1/auth/2fa/disable"),
    ("GET", "/api/v1/access/me/scope"),
    # Any authenticated user may open the realtime socket; each channel subscription is then permission-checked.
    ("POST", "/api/v1/realtime/ticket"),
}


def _marks(dependant) -> set[str]:
    found = set()
    call = getattr(dependant, "call", None)
    if call is not None and hasattr(call, "_cs_protection"):
        found.add(call._cs_protection)
    for sub in dependant.dependencies:
        found |= _marks(sub)
    return found


def _required(dependant) -> list[str]:
    codes = []
    call = getattr(dependant, "call", None)
    if call is not None and hasattr(call, "_required"):
        codes += list(call._required)
    for sub in dependant.dependencies:
        codes += _required(sub)
    return codes


def api_routes():
    from app.main import app

    for route in app.routes:
        if isinstance(route, APIRoute) and route.path.startswith(settings.api_prefix):
            for method in route.methods - {"HEAD", "OPTIONS"}:
                yield method, route


def classify(route) -> str:
    marks = _marks(route.dependant)
    if "permission" in marks:
        return "permission"
    if "authenticated" in marks:
        return "authenticated"
    if "public" in marks:
        return "public"
    return "none"


def test_every_route_declares_how_it_is_protected():
    unprotected = [f"{m} {r.path}" for m, r in api_routes() if classify(r) == "none"]
    assert unprotected == [], f"routes with no protection dependency: {unprotected}"


def test_the_set_of_public_routes_is_exactly_the_reviewed_list():
    public = {(m, r.path) for m, r in api_routes() if classify(r) == "public"}
    assert public == PUBLIC, "a public route was added or removed; that needs a security review, then update this list"


def test_the_set_of_authenticated_only_routes_is_exactly_the_reviewed_list():
    only = {(m, r.path) for m, r in api_routes() if classify(r) == "authenticated"}
    assert only == AUTHENTICATED_ONLY, "an authenticated-only route needs a conscious decision that no permission is required"


def test_every_permission_a_route_requires_exists_in_the_catalogue():
    known = {code for code, *_ in all_permissions()}
    for method, route in api_routes():
        for code in _required(route.dependant):
            assert code in known, f"{method} {route.path} requires unknown permission {code}"


def test_routes_that_change_data_never_rely_on_authentication_alone():
    for method, route in api_routes():
        if method in {"POST", "PUT", "PATCH", "DELETE"} and classify(route) == "authenticated":
            assert (method, route.path) in AUTHENTICATED_ONLY, f"{method} {route.path}"


SCOPED_TABLES = r"(devices|interfaces|device_groups)"
SQL_ON_SCOPED = re.compile(rf"\b(from|join|update|into|delete\s+from)\s+{SCOPED_TABLES}\b", re.I)


def test_routers_contain_no_sql_against_scoped_tables():
    """Scope rules live in app/repositories. A router that queries devices or interfaces itself would bypass them."""
    offenders = []
    for path in sorted((APP_DIR / "api").glob("*.py")):
        for number, line in enumerate(path.read_text().splitlines(), 1):
            if SQL_ON_SCOPED.search(line):
                offenders.append(f"{path.name}:{number}: {line.strip()}")
    assert offenders == []


# Helpers that do not read or write scoped rows on the caller's behalf.
UNSCOPED_HELPERS = {"as_dict", "resolve_family", "_check_access_profile", "_descendants", "list_trap_profiles",
                    # NOC-wide dashboard widgets: they read users and audit_logs only, and the service refuses them to restricted roles.
                    "latest_system_actions", "last_user_activity",
                    # The scheduler's path-state job and the metrics export: every path, no caller, never answering a user.
                    "system_paths", "system_save_states", "system_fresh_states",
                    # The polling sink's LLDP writer: one device's poll results, no caller.
                    "system_replace_neighbours",
                    # Names of external LLDP neighbours: the id already names a device the caller was shown.
                    "external_names",
                    # Link utilisation: the metrics export (every link, no caller), the polling sink's counter writer, and
                    # rates for interface ids taken from rows the caller was already allowed to see.
                    "system_links", "system_store_samples", "interface_rates"}
SCOPED_ENTRY = ("get_device(", "get_group(", "get_interface(", "get_event(", "get_window(",
                # links.py: get_link applies DEVICE_VISIBLE; editable_link and _check_end go through get_link and get_device.
                "get_link(", "editable_link(", "_check_end(",
                # paths.py: get_path applies DEVICE_VISIBLE to both endpoints.
                "get_path(",
                # lldp.py: _owners applies DEVICE_VISIBLE.
                "_owners(")


def test_every_repository_function_touching_scoped_tables_applies_scope():
    """A function that runs SQL must either contain the visibility predicate or first call a scoped getter.
    Write functions take the caller and start with the getter, so a write can never reach a row the caller cannot see."""
    checked = 0
    for name, predicates in (("devices.py", ("DEVICE_VISIBLE",)), ("interfaces.py", ("INTERFACE_VISIBLE",)), ("device_groups.py", ("GROUP_VISIBLE",)), ("polling.py", ("DEVICE_VISIBLE",)), ("events.py", ("EVENT_VISIBLE",)), ("traps.py", ("TRAP_VISIBLE",)), ("maintenance.py", ("WINDOW_VISIBLE",)), ("dashboards.py", ("DEVICE_VISIBLE", "INTERFACE_VISIBLE", "EVENT_VISIBLE")), ("links.py", ("DEVICE_VISIBLE",)), ("console.py", ("DEVICE_VISIBLE",)), ("paths.py", ("DEVICE_VISIBLE",)), ("lldp.py", ("DEVICE_VISIBLE",))):
        source = (APP_DIR / "repositories" / name).read_text()
        for function in re.split(r"\nasync def |\ndef ", source)[1:]:
            header = function.splitlines()[0]
            fn_name = header.split("(")[0]
            if fn_name in UNSCOPED_HELPERS or not re.search(r"conn\.(fetch|fetchrow|fetchval|execute)", function):
                continue
            checked += 1
            scoped = any(p in function for p in predicates) or any(entry in function for entry in SCOPED_ENTRY)
            assert scoped, f"{name}: {fn_name} runs SQL without applying scope"
    assert checked >= 12  # a floor, so the guard cannot silently stop checking anything


def test_write_functions_take_the_caller():
    for name in ("devices.py", "device_groups.py"):
        source = (APP_DIR / "repositories" / name).read_text()
        for match in re.finditer(r"async def ((?:create|update|delete)_\w+)\(([^)]*)\)", source):
            assert "user" in match.group(2), f"{name}: {match.group(1)} does not take the caller"
