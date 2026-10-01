"""Response models (Plan 24): what each route returns, declared so the OpenAPI schema - and the frontend types
generated from it - describe real fields instead of an untyped object.

Every model forbids extra fields. FastAPI validates a route's return value against its response model, so a route
that starts returning a field its model does not declare fails loudly (a 500 in the API tests) instead of the field
being dropped silently from every response.
"""
from __future__ import annotations

from datetime import datetime
from typing import Any, Literal

from pydantic import BaseModel, ConfigDict


class Strict(BaseModel):
    model_config = ConfigDict(extra="forbid")


class StatusOut(Strict):
    status: str


class TwoFactorEnabled(Strict):
    status: Literal["enabled"]
    recovery_codes: list[str]


class PasswordReset(Strict):
    status: Literal["password_reset"]
    generated_password: str | None  # only when the server generated one; shown once


# --- devices ---

class DeviceOut(Strict):
    id: str
    name: str
    hostname: str | None
    management_ip: str
    device_type: str
    vendor: str | None
    status: str
    group_id: str | None
    model_id: str | None
    polling_enabled: bool
    polling_owner: str
    legacy_id: int | None
    created_at: datetime
    updated_at: datetime


class DevicePage(Strict):
    items: list[DeviceOut]
    total: int
    limit: int
    offset: int


class DiscoveryQueued(Strict):
    job_id: str | None
    status: str
    reason: str | None


class DeviceCreated(Strict):
    device: DeviceOut
    discovery: DiscoveryQueued


class DiscoveryJobOut(Strict):
    id: str
    status: str
    oids: list[str]
    timeout_ms: int
    retries: int
    requested_at: datetime
    started_at: datetime | None
    finished_at: datetime | None
    result: Any
    error: str | None


# --- device groups ---

class DeviceGroupOut(Strict):
    id: str
    parent_id: str | None
    name: str
    description: str | None
    legacy_id: int | None
    created_at: datetime
    devices: int


# --- events ---

class EventOut(Strict):
    id: str
    occurred_at: datetime
    name: str
    dedup_key: str | None
    labels: dict[str, Any]
    description: str | None
    severity: Literal["info", "warning", "critical"]
    device_id: str | None
    device_name: str | None
    device_ip: str | None
    resolved_at: datetime | None
    resolved_by_user_id: str | None
    flap_count: int
    last_reopened_at: datetime | None


class EventPage(Strict):
    items: list[EventOut]
    total: int
    limit: int
    offset: int


class IncidentOut(Strict):
    key: str
    kind: str
    device_id: str | None
    device_ip: str | None
    where: str
    severity: str
    events: int
    alarms: dict[str, int]
    started_at: str
    latest_at: str
    event_ids: list[str]


class IncidentList(Strict):
    incidents: list[IncidentOut]
    open_events: int


class AlarmRuleOut(Strict):
    id: str
    group_name: str
    alert_name: str
    expression: str
    for_duration: str
    severity: str
    audience: str | None
    isp_focus: str
    reseller_focus: str
    annotation_summary: str
    annotation_description: str
    enabled: bool
    internal: bool


# --- maintenance windows ---

class MaintenanceWindowOut(Strict):
    id: str
    device_id: str | None
    device_group_id: str | None
    starts_at: datetime
    ends_at: datetime
    reason: str
    created_by_user_id: str | None
    created_at: datetime
    canceled_at: datetime | None
    canceled_by_user_id: str | None
    active: bool


class MaintenanceWindowPage(Strict):
    items: list[MaintenanceWindowOut]
    limit: int
    offset: int


class MaintenanceCanceled(Strict):
    status: Literal["canceled"]
    released_events: int
