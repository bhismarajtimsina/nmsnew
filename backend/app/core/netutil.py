from __future__ import annotations

import ipaddress

from fastapi import Request


def client_ip(request: Request) -> str | None:
    """The caller's address. Behind Nginx this is the forwarded address, applied by ProxyHeadersMiddleware, and only
    when the connection came from a configured trusted proxy."""
    return request.client.host if request.client else None


def ip_allowed(ip: str | None, allowed: list[str]) -> bool:
    """True when `ip` falls inside any allowed address or network. An empty list allows nothing (fail closed)."""
    if not ip:
        return False
    try:
        address = ipaddress.ip_address(ip)
    except ValueError:
        return False
    for item in allowed:
        try:
            if address in ipaddress.ip_network(item, strict=False):
                return True
        except ValueError:
            continue
    return False


def validate_management_ip(value: str, allowed_networks: tuple[str, ...] = ()) -> str:
    """Normalize a device's management address, or raise ValueError saying why it is not acceptable.

    Devices are contacted by background workers, so an address that points back at this host, at a multicast group or at
    a link-local range is refused. When MANAGEMENT_NETWORKS is set the address must also be inside one of those networks.
    """
    try:
        address = ipaddress.ip_address(value.strip())
    except ValueError as exc:
        raise ValueError("not a valid IP address") from exc
    if address.is_unspecified or address.is_loopback or address.is_multicast or address.is_link_local or address.is_reserved:
        raise ValueError("address is not a usable management address (unspecified, loopback, multicast, link-local or reserved)")
    if address == ipaddress.ip_address("255.255.255.255"):
        raise ValueError("broadcast address")
    if allowed_networks:
        nets = []
        for item in allowed_networks:
            try:
                nets.append(ipaddress.ip_network(item, strict=False))
            except ValueError:
                continue
        if not any(address in net for net in nets):
            raise ValueError("address is outside the configured management networks")
    return str(address)
