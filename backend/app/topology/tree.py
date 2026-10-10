"""Device trees from links (Plan 27), ported from legacy `LinkStorage::buildDownArray`, `getUplinkTree` and
`getCoreByDevice`. As in legacy, a link's source is the upstream side and its destination the downstream side.

Differences from legacy, each a bug there:
- A device is expanded once. Legacy has no visited set, so a ring (common in ISP access networks) repeats the ring's
  devices down to the depth limit; here a device met again is a leaf marked `repeat`.
- A device with no ping status is still in the tree. Legacy inner-joins the pinger table, so such a device vanishes
  with everything below it.
- Walking up, a device with several upstream links takes the first in a fixed order (the upstream device's name, then
  the link id) and says `multiple_parents`; legacy takes whichever chain the database returns longest.

Input is the caller's links as app/topology/links.present() shows them, so a device outside the caller's scope is a
per-link placeholder: it is shown, never expanded, and never walked through.

Two guards keep a hidden device from being traversed: each hidden end is its own placeholder per link (so it has no
further edges), and the walks also refuse to expand a node that is not visible. Either alone is enough; both are kept
on purpose, so a future change to one cannot open a path into another reseller's network. Mutation checks therefore
see removing either one as equivalent.
"""
from __future__ import annotations

from collections import defaultdict
from typing import Any

MAX_DEPTH = 15  # legacy LinkStorage::MAX_DEPTH


def _node_id(end: dict[str, Any], link_id: str, side: str) -> str:
    return end["device_id"] if end["visible"] else f"hidden:{link_id}:{side}"


def index(links: list[dict[str, Any]]) -> tuple[dict[str, list[dict[str, Any]]], dict[str, list[dict[str, Any]]], dict[str, dict[str, Any]]]:
    """Children and parents per node, and what each node shows."""
    down: dict[str, list[dict[str, Any]]] = defaultdict(list)
    up: dict[str, list[dict[str, Any]]] = defaultdict(list)
    nodes: dict[str, dict[str, Any]] = {}
    for link in links:
        src, dest = _node_id(link["src"], link["id"], "src"), _node_id(link["dest"], link["id"], "dest")
        for node, end in ((src, link["src"]), (dest, link["dest"])):
            nodes.setdefault(node, {"id": node, "name": end["name"], "ip": end["ip"], "visible": end["visible"], "state": end["state"]})
        down[src].append({"link": link, "child": dest})
        up[dest].append({"link": link, "parent": src})
    order = lambda e, key: ((nodes[e[key]]["name"] or "~"), e["link"]["id"])  # noqa: E731 - hidden ends sort last
    for edges in down.values():
        edges.sort(key=lambda e: order(e, "child"))
    for edges in up.values():
        edges.sort(key=lambda e: order(e, "parent"))
    return down, up, nodes


def down_tree(root: str, links: list[dict[str, Any]], max_depth: int = MAX_DEPTH) -> tuple[dict[str, Any], bool]:
    """The tree below `root`; returns (tree, truncated by depth)."""
    down, _, nodes = index(links)
    seen = {root}
    truncated = False

    def build(node: str, depth: int) -> list[dict[str, Any]]:
        nonlocal truncated
        children = []
        for edge in down.get(node, []):
            child, link = edge["child"], edge["link"]
            entry = {**nodes[child], "link_id": link["id"], "link_state": link["state"], "uplink_interface": link["dest"]["interface"],
                     "downlink_interface": link["src"]["interface"], "repeat": child in seen, "nodes": []}
            if not entry["repeat"] and nodes[child]["visible"]:
                if depth >= max_depth:
                    truncated = True
                else:
                    seen.add(child)
                    entry["nodes"] = build(child, depth + 1)
            children.append(entry)
        return children

    root_info = nodes.get(root, {"id": root, "name": None, "ip": None, "visible": True, "state": None})
    return {**root_info, "nodes": build(root, 1)}, truncated


def upward_chain(device: str, links: list[dict[str, Any]], max_depth: int = MAX_DEPTH) -> list[dict[str, Any]]:
    """From `device` up to the top: each step is the upstream device and the link to it. Stops at the top, at a hidden
    device, at a loop, or at the depth limit."""
    _, up, nodes = index(links)
    chain = []
    seen = {device}
    here = device
    for depth in range(1, max_depth + 1):
        parents = up.get(here, [])
        if not parents:
            break
        edge = parents[0]
        parent, link = edge["parent"], edge["link"]
        loop = parent in seen
        chain.append({**nodes[parent], "depth": depth, "link_id": link["id"], "link_state": link["state"],
                      "downlink_interface": link["src"]["interface"], "uplink_interface": link["dest"]["interface"],
                      "multiple_parents": len(parents) > 1, "loop": loop})
        if loop or not nodes[parent]["visible"]:
            break
        seen.add(parent)
        here = parent
    return chain


def top_of(device: str, links: list[dict[str, Any]]) -> str:
    """The highest device reachable upward that the caller may see (the device itself when it has no upstream)."""
    top = device
    for step in upward_chain(device, links):
        if step["visible"] and not step["loop"]:
            top = step["id"]
    return top
