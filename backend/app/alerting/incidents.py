"""Groups open events by the thing that actually broke, ported from the real, production-validated
GetIncidentsAction.php (git 777e9dd88: confirmed on real data, 961 open events collapsed into 64 incidents).

A list of alarms is not a list of problems. Forty subscribers dark on one PON port is one fault and forty rows, and
with hundreds of optical alarms open the genuine outages cannot be seen at all. This groups by cause instead:

  device      the device is unreachable (a `pinger_host_down` event on it), so everything else on it is a symptom and
              is folded in rather than reported separately
  pon_port    several subscribers on the same PON port (an ONU-typed interface name of the form "<port>:<onu>")
  interface   several alarms on one port
  alarm       anything else, grouped by device and alarm name

No device is contacted: this only re-groups events already recorded from Alertmanager.
"""
from __future__ import annotations

from dataclasses import dataclass, field
from datetime import datetime

SEVERITY_ORDER = {"info": 1, "warning": 2, "critical": 3}
MAX_EVENT_IDS = 200


@dataclass
class OpenEvent:
    id: str
    name: str
    severity: str
    device_id: str | None
    labels: dict
    occurred_at: datetime


@dataclass
class Incident:
    key: str
    kind: str
    device_id: str | None
    device_ip: str | None
    where: str
    severity: str
    events: int = 0
    alarms: dict[str, int] = field(default_factory=dict)
    started_at: datetime | None = None
    latest_at: datetime | None = None
    event_ids: list[str] = field(default_factory=list)


def group(rows: list[OpenEvent]) -> list[Incident]:
    unreachable_devices = {r.device_id for r in rows if r.name == "pinger_host_down" and r.device_id}

    incidents: dict[str, Incident] = {}
    for row in rows:
        labels = row.labels or {}
        iface_name = str(labels.get("iface_name") or "")
        iface_type = str(labels.get("iface_type") or "")
        device_key = row.device_id or "none"

        if row.device_id in unreachable_devices:
            kind, where, key = "device", str(labels.get("ip") or device_key), f"device:{device_key}"
        elif iface_type == "ONU" and ":" in iface_name:
            port = iface_name.split(":", 1)[0].strip()
            kind, where, key = "pon_port", port, f"pon:{device_key}:{port}"
        elif iface_name:
            kind, where, key = "interface", iface_name, f"iface:{device_key}:{iface_name}"
        else:
            kind, where, key = "alarm", "", f"alarm:{device_key}:{row.name}"

        incident = incidents.get(key)
        if incident is None:
            incident = Incident(key=key, kind=kind, device_id=row.device_id, device_ip=labels.get("ip"), where=where,
                                severity=row.severity, started_at=row.occurred_at, latest_at=row.occurred_at)
            incidents[key] = incident

        incident.events += 1
        incident.alarms[row.name] = incident.alarms.get(row.name, 0) + 1
        if row.occurred_at < incident.started_at:
            incident.started_at = row.occurred_at
        if row.occurred_at > incident.latest_at:
            incident.latest_at = row.occurred_at
        if SEVERITY_ORDER.get(row.severity, 0) > SEVERITY_ORDER.get(incident.severity, 0):
            incident.severity = row.severity
        if len(incident.event_ids) < MAX_EVENT_IDS:
            incident.event_ids.append(row.id)

    return sorted(incidents.values(), key=lambda i: (SEVERITY_ORDER.get(i.severity, 0), i.events), reverse=True)
