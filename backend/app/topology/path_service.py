"""Turns path and hop rows into the calculator's input and the API's output, refreshes stored states for the scheduler,
and renders the path metrics legacy exported (Plan 27)."""
from __future__ import annotations

import json
from collections import defaultdict
from typing import Any

import asyncpg

from app.topology import links as link_view
from app.topology.paths import NUMERIC, Endpoint, Hop, group_state, path_state
from app.topology.state import end_state, link_state


def hop_from_row(row: asyncpg.Record) -> Hop:
    ends = [end_state(ping=row[f"{e}_ping"], interface_bound=row[f"{e}_interface_id"] is not None,
                      oper_status=row[f"{e}_oper"], admin_status=row[f"{e}_admin"]) for e in ("src", "dest")]
    return Hop(position=row["position"], link_id=str(row["id"]), link_state=link_state(*ends),
               endpoints=tuple(Endpoint(str(row[f"{e}_device_id"]), row[f"{e}_ping"], row[f"{e}_latency"]) for e in ("src", "dest")))


def hops_by_path(rows: list[asyncpg.Record]) -> dict[str, list[Hop]]:
    grouped: dict[str, list[Hop]] = defaultdict(list)
    for row in rows:
        grouped[str(row["path_id"])].append(hop_from_row(row))
    return grouped


def present(row: asyncpg.Record) -> dict[str, Any]:
    return {"id": str(row["id"]), "name": row["name"], "group_key": row["group_key"], "priority": row["priority"],
            "endpoint_a": {"device_id": str(row["endpoint_a_id"]), "name": row["endpoint_a_name"]},
            "endpoint_b": {"device_id": str(row["endpoint_b_id"]), "name": row["endpoint_b_name"]},
            "enabled": row["enabled"], "description": row["description"], "segments": row["segments"],
            "state": row["stored_state"] or "unknown", "last_change": row["last_change"], "state_updated_at": row["state_updated_at"],
            "created_at": row["created_at"], "updated_at": row["updated_at"]}


def present_detail(row: asyncpg.Record, hop_rows: list[asyncpg.Record], degraded_ms: int) -> dict[str, Any]:
    """A path with its state computed now, from the current link and ping data, and each hop through the link masking:
    a hop's state is given, its hidden ends are not."""
    hops = [hop_from_row(r) for r in hop_rows]
    live_state, detail = path_state(hops, degraded_ms)
    by_link = {h["link_id"]: h for h in detail["hops"]}
    shown = []
    for r in hop_rows:
        hop = by_link[str(r["id"])]
        shown.append({"position": r["position"], "state": hop["state"], "reason": hop["reason"], "link": link_view.present(r)})
    return {**present(row), "live_state": live_state, "degraded_threshold_ms": degraded_ms, "hops": shown}


async def refresh_states(conn: asyncpg.Connection, degraded_ms: int) -> tuple[int, int]:
    """Recompute every enabled path and store it. Returns (paths, changed)."""
    from app.repositories import paths as repo

    paths, hop_rows = await repo.system_paths(conn)
    hops = hops_by_path(list(hop_rows))
    states = {str(p["id"]): path_state(hops.get(str(p["id"]), []), degraded_ms) for p in paths}
    changed = await repo.system_save_states(conn, states)
    return len(paths), changed


def _escape(value: Any) -> str:
    return str(value).replace("\\", "\\\\").replace("\n", "\\n").replace('"', '\\"')


def _line(name: str, labels: dict[str, Any], value: float) -> str:
    inner = ",".join(f'{k}="{_escape(v)}"' for k, v in labels.items())
    return f"{name}{{{inner}}} {value:g}"


def render_metrics(rows: list[asyncpg.Record]) -> str:
    """legacy's names, labels and values: path_state (1 up, 0.5 degraded, 0 down, -1 unknown) and path_segments_down per
    path; path_group_protected, path_group_up_count and path_group_total per redundant group (a group of one adds
    nothing, as in legacy)."""
    if not rows:
        return ""
    out = ["# HELP path_state Transport path state (1=up, 0.5=degraded, 0=down, -1=unknown)", "# TYPE path_state gauge"]
    down_lines = ["# HELP path_segments_down Number of down segments on a transport path", "# TYPE path_segments_down gauge"]
    groups: dict[str, list[tuple[str, str, int, str]]] = defaultdict(list)
    for r in rows:
        group = r["group_key"] or "none"
        out.append(_line("path_state", {"path_id": r["id"], "path_name": r["name"], "group_key": group,
                                        "endpoint_a_name": r["endpoint_a_name"], "endpoint_b_name": r["endpoint_b_name"]},
                         NUMERIC.get(r["state"], -1.0)))
        detail = r["detail"] if isinstance(r["detail"], dict) else json.loads(r["detail"] or "{}")
        down = sum(1 for h in detail.get("hops", []) if h.get("state") == "down")
        down_lines.append(_line("path_segments_down", {"path_id": r["id"], "path_name": r["name"], "group_key": group}, down))
        if r["group_key"]:
            groups[r["group_key"]].append((str(r["id"]), r["name"], 0, r["state"]))
    out += down_lines
    group_lines: dict[str, list[str]] = {"path_group_protected": [], "path_group_up_count": [], "path_group_total": []}
    for key, members in sorted(groups.items()):
        g = group_state(members)  # type: ignore[arg-type]
        if not g["redundant"]:
            continue
        group_lines["path_group_protected"].append(_line("path_group_protected", {"group_key": key}, 1 if g["protected"] else 0))
        group_lines["path_group_up_count"].append(_line("path_group_up_count", {"group_key": key}, g["usable"]))
        group_lines["path_group_total"].append(_line("path_group_total", {"group_key": key}, g["total"]))
    helps = {"path_group_protected": "1 when every path in the redundancy group is usable",
             "path_group_up_count": "Number of usable paths in the redundancy group",
             "path_group_total": "Total number of paths in the redundancy group"}
    for name, lines in group_lines.items():
        if lines:
            out += [f"# HELP {name} {helps[name]}", f"# TYPE {name} gauge", *lines]
    return "\n".join(out) + "\n"
