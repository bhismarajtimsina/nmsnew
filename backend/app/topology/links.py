"""What the API returns for a link (Plan 27): both ends with their state, the far end masked when the caller may not
see it, and the link's state from app/topology/state.py."""
from __future__ import annotations

from typing import Any

import asyncpg

from app.topology.state import end_state, link_state

HIDDEN = {"device_id": None, "name": None, "ip": None, "interface_id": None, "interface": None, "visible": False, "state": None}


def _end(row: asyncpg.Record, end: str) -> tuple[dict[str, Any], str]:
    state = end_state(ping=row[f"{end}_ping"], interface_bound=row[f"{end}_interface_id"] is not None,
                      oper_status=row[f"{end}_oper"], admin_status=row[f"{end}_admin"])
    if not row[f"{end}_visible"]:
        return dict(HIDDEN), state
    return {"device_id": str(row[f"{end}_device_id"]), "name": row[f"{end}_name"], "ip": row[f"{end}_ip"],
            "interface_id": str(row[f"{end}_interface_id"]) if row[f"{end}_interface_id"] else None,
            "interface": row[f"{end}_interface"], "visible": True, "state": state}, state


def present(row: asyncpg.Record) -> dict[str, Any]:
    """A link as the caller may see it. The link's own state uses both ends, so a reseller learns whether their uplink
    is up, never what is at the hidden end or that end's own state."""
    src, src_state = _end(row, "src")
    dest, dest_state = _end(row, "dest")
    return {"id": str(row["id"]), "source": row["source"], "description": row["description"], "src": src, "dest": dest,
            "state": link_state(src_state, dest_state), "created_at": row["created_at"], "updated_at": row["updated_at"]}


def graph(links: list[dict[str, Any]]) -> dict[str, Any]:
    """Nodes and edges for the topology view. A hidden end becomes its own placeholder node per link, so the graph does
    not even reveal that two links reach the same device outside the caller's scope."""
    nodes: dict[str, dict[str, Any]] = {}
    edges = []
    for link in links:
        ends = []
        for side in ("src", "dest"):
            end = link[side]
            node_id = end["device_id"] if end["visible"] else f"hidden:{link['id']}:{side}"
            # A hidden end's name and address were already masked by present(); the graph adds nothing back.
            nodes.setdefault(node_id, {"id": node_id, "name": end["name"], "ip": end["ip"], "visible": end["visible"]})
            ends.append(node_id)
        edges.append({"id": link["id"], "from": ends[0], "to": ends[1], "state": link["state"],
                      "from_interface": link["src"]["interface"], "to_interface": link["dest"]["interface"]})
    return {"nodes": sorted(nodes.values(), key=lambda n: (not n["visible"], n["name"] or "", n["id"])), "edges": edges}
