"""Response models (Plan 24): what each route returns, declared so the OpenAPI schema - and the frontend types
generated from it - describe real fields instead of an untyped object.

Every model forbids extra fields. FastAPI validates a route's return value against its response model, so a route
that starts returning a field its model does not declare fails loudly (a 500 in the API tests) instead of the field
being dropped silently from every response.
"""
from __future__ import annotations

from datetime import datetime
from typing import Any, Literal

from pydantic import BaseModel, ConfigDict, Field


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


class OverviewGroup(Strict):
    id: str
    name: str


class OverviewModel(Strict):
    id: str
    name: str
    vendor: str
    icon: str | None


class OverviewPing(Strict):
    status: Literal["unknown", "up", "down"]
    latency_ms: float | None
    last_checked_at: datetime | None


class InterfaceCounts(Strict):
    up: int
    down: int


class DeviceOverviewOut(Strict):
    id: str
    name: str
    management_ip: str
    polling_enabled: bool
    group: OverviewGroup | None
    model: OverviewModel | None
    ping: OverviewPing | None
    interfaces: InterfaceCounts


class DeviceOverviewPage(Strict):
    items: list[DeviceOverviewOut]
    total: int
    limit: int
    offset: int


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


# --- interfaces ---

class InterfaceOut(Strict):
    id: str
    device_id: str
    device_name: str
    parent_interface_id: str | None
    if_index: int
    name: str
    alias: str | None
    if_type: str | None
    admin_status: str
    oper_status: str
    speed_bps: int | None
    mac_address: str | None
    legacy_id: int | None
    protected: bool
    created_at: datetime
    updated_at: datetime


class InterfacePage(Strict):
    items: list[InterfaceOut]
    total: int
    limit: int
    offset: int


class InterfaceStatusChange(Strict):
    id: str
    changed_at: datetime
    admin_status: str
    oper_status: str


class InterfaceHistoryPage(Strict):
    items: list[InterfaceStatusChange]
    total: int
    limit: int
    offset: int


class InterfaceMarks(Strict):
    favorite: bool
    tags: list[str]


class FavoriteOut(Strict):
    favorite: bool


class TagsOut(Strict):
    tags: list[str]


# --- device access profiles (secrets are never returned, only whether each is set) ---

class AccessProfileOut(Strict):
    id: str
    name: str
    snmp_version: str
    timeout_ms: int
    retries: int
    snmp_v3_username: str | None
    snmp_v3_auth_protocol: str | None
    snmp_v3_priv_protocol: str | None
    has_community: bool
    has_write_community: bool
    has_auth_secret: bool
    has_priv_secret: bool
    cli_protocol: Literal["ssh", "telnet"] | None
    cli_port: int | None
    cli_username: str | None
    has_cli_password: bool
    has_cli_enable_password: bool
    legacy_id: int | None
    created_at: datetime
    updated_at: datetime
    devices_using: int


# --- registries ---

class ModelFamilyOut(Strict):
    id: str
    vendor_slug: str
    slug: str
    name: str
    device_type: str
    notes: str | None
    polling_enabled: bool
    polling_disabled_reason: str | None
    polling_toggled_at: datetime | None


class VendorOut(Strict):
    id: str
    slug: str
    name: str
    notes: str | None
    discovery_oids: list[str]
    discovery_timeout_ms: int
    discovery_retries: int
    polling_enabled: bool
    polling_disabled_reason: str | None
    polling_toggled_at: datetime | None
    families: int


class VendorDetail(VendorOut):
    model_families: list[ModelFamilyOut]


class CapabilityOut(Strict):
    code: str
    description: str
    risk: str
    enabled_by_default: bool


class FamilyCapabilityOut(CapabilityOut):
    status: str
    verified_by_fixture: bool
    fixture_ref: str | None
    note: str | None


class OidProfileSummary(Strict):
    id: str
    name: str
    version: int
    status: str
    description: str | None
    created_at: datetime
    activated_at: datetime | None
    vendor_slug: str | None
    family_slug: str | None
    entries: int


class OidProfileEntryOut(Strict):
    logical_name: str
    numeric_oid: str
    module: str
    access: str
    safety_level: str
    unit: str | None
    mib_object: str | None
    source_note: str
    walk_strategy: str
    max_rows: int | None
    timeout_ms: int | None
    position: int
    transform: str | None
    transform_kind: str | None
    factor: float | None
    valid_min: float | None
    valid_max: float | None


class OidProfileDetail(Strict):
    id: str
    name: str
    version: int
    status: str
    description: str | None
    created_at: datetime
    activated_at: datetime | None
    vendor_slug: str | None
    family_slug: str | None
    entries: list[OidProfileEntryOut]


class DeviceModelOut(Strict):
    id: str
    vendor: str
    family_slug: str | None
    legacy_key: str | None
    model_name: str
    device_type: str
    sysobjectid_matcher: str | None
    sysdescr_pattern: str | None
    priority: int
    source_note: str | None
    legacy_id: int | None
    created_at: datetime


class MibFileOut(Strict):
    id: str
    filename: str
    vendor_slug: str | None
    object_count: int
    resolved_count: int
    imported_at: datetime


class MibObjectOut(Strict):
    id: str
    name: str
    kind: str
    numeric_oid: str | None
    parent_name: str | None
    sub_id: int | None
    filename: str


class MibMatch(Strict):
    filename: str
    numeric_oid: str


class MibCheckOut(Strict):
    name: str
    declared_oid: str
    status: str
    mib_matches: list[MibMatch]


class TrapProfileOut(Strict):
    id: str
    vendor: str
    name: str
    oid: str
    description: str
    is_interface: bool
    modules: list[str]


class TrapHistoryOut(Strict):
    id: str
    received_at: datetime
    source_ip: str
    device_id: str | None
    device_name: str | None
    version: str
    trap_oid: str
    trap_name: str | None
    vendor: str | None
    varbinds: dict[str, Any]


class TrapHistoryPage(Strict):
    items: list[TrapHistoryOut]
    total: int
    limit: int
    offset: int


# --- polling and workers ---

class PollQueued(Strict):
    job_id: str
    status: Literal["queued"]
    profile: str


class PollResultOut(Strict):
    id: str
    profile_name: str
    profile_version: int | None
    outcome: str
    rows: int
    truncated: bool
    duration_ms: int
    error: str | None
    started_at: datetime


class PollStateOut(Strict):
    consecutive_failures: int
    breaker_open: bool
    breaker_open_until: datetime | None
    last_error: str | None
    last_polled_at: datetime | None
    last_success_at: datetime | None
    last_failure_at: datetime | None


class PollHistory(Strict):
    state: PollStateOut | None
    results: list[PollResultOut]


class WorkerOut(Strict):
    worker_id: str
    kind: str
    status: str
    started_at: datetime
    last_seen: datetime
    healthy: bool
    info: dict[str, Any]


class DeadLetterOut(Strict):
    id: str
    stream: str
    message_id: str | None
    job_id: str | None
    reason: str
    deliveries: int
    fields: dict[str, Any]
    created_at: datetime
    resolved_at: datetime | None


# --- schedule ---

class ScheduleJobOut(Strict):
    key: str
    job_type: str
    params: dict[str, Any]
    crontab: str
    timezone: str
    enabled: bool
    editable: bool
    description: str | None
    misfire_policy: str
    misfire_grace_seconds: int
    catch_up_max: int
    overlap_policy: str
    max_runtime_seconds: int
    next_run_at: datetime | None
    last_run_at: datetime | None
    upcoming: list[datetime]


class ScheduleRunOut(Strict):
    id: str
    scheduled_for: datetime
    started_at: datetime
    finished_at: datetime | None
    status: str
    output: str | None
    error: str | None


# --- users and resellers ---

class ResellerOut(Strict):
    id: str
    name: str
    description: str | None
    is_active: bool
    users: int


class ResellerCreated(Strict):
    id: str
    name: str


class ResellerUsers(Strict):
    users: int


# --- realtime and health ---

class RealtimeTicket(Strict):
    ticket: str
    expires_in: int


class HealthOut(Strict):
    status: str


class ReadyOut(Strict):
    ready: bool
    checks: dict[str, str]


# --- Dashboards (Plan 23) ---

class DashboardWidgetOut(Strict):
    key: str
    title: str
    state: Literal["live", "pending"]
    endpoint: str | None
    pending_reason: str | None


class DashboardOut(Strict):
    key: str
    title: str
    noc_wide: bool
    widgets: list[DashboardWidgetOut]


class DashboardList(Strict):
    default: str | None
    dashboards: list[DashboardOut]


class DeviceRef(Strict):
    id: str
    name: str
    ip: str | None


class DeviceStatusWidget(Strict):
    up: int
    down: int
    never_checked: int
    total: int


class CountBySeverity(Strict):
    severity: str
    count: int


class CountByName(Strict):
    name: str
    count: int


class EventCountsWidget(Strict):
    items: list[CountBySeverity]


class EventNamesWidget(Strict):
    items: list[CountByName]


class PortDown(Strict):
    id: str
    name: str
    alias: str | None
    oper_status: str | None
    device: DeviceRef
    down_since: datetime | None


class PortsDownWidget(Strict):
    items: list[PortDown]
    total: int
    limit: int


class FailingPoll(Strict):
    device: DeviceRef
    profile: str
    failed_runs: int
    last_failure: datetime


class PollerHealthWidget(Strict):
    window_hours: int
    ok: int
    timeout: int
    error: int
    skipped: int
    truncated: int
    failing: list[FailingPoll]


class DeviceCallErrors(Strict):
    device: DeviceRef
    errors: int
    not_responding: int


class ErrorCallingWidget(Strict):
    errors: int
    not_responding: int
    limit: int
    devices: list[DeviceCallErrors]


class SystemStatWidget(Strict):
    devices: int
    interfaces: int
    device_groups: int
    users: int | None
    roles: int | None


class SystemAction(Strict):
    id: str
    occurred_at: datetime
    action: str
    resource_type: str | None
    resource_id: str | None
    actor: str | None


class SystemActionsWidget(Strict):
    items: list[SystemAction]


class UserActivity(Strict):
    id: str
    username: str
    display_name: str
    role: str
    last_login_at: datetime | None
    last_activity_at: datetime | None


class UserActivityWidget(Strict):
    items: list[UserActivity]


# --- Dangerous actions (Plan 26) ---

class ActionSpecOut(Strict):
    key: str
    title: str
    target_kind: Literal["device", "interface", "onu"]
    max_targets: int
    params: dict[str, list[str] | None]
    available: bool


class ActionList(Strict):
    items: list[ActionSpecOut]


class ActionPrepareIn(Strict):
    targets: list[dict[str, str]] = Field(min_length=1, max_length=500)
    params: dict[str, str] = Field(default_factory=dict)
    stop_on_failure: bool = False  # bulk: stop at the first target that does not succeed; part of what is confirmed


class ActionExecuteIn(ActionPrepareIn):
    token: str = Field(min_length=20, max_length=200)


class ActionSummary(Strict):
    action: str
    title: str
    targets: list[dict[str, str | None]]
    count: int
    params: dict[str, str]
    stop_on_failure: bool


class ActionPrepared(Strict):
    token: str
    expires_at: datetime
    summary: ActionSummary


class ActionTargetResult(Strict):
    target: dict[str, str]
    status: Literal["queued", "running", "succeeded", "failed", "refused", "skipped"]
    error: str | None


class ActionExecuted(Strict):
    action: str
    confirmation_id: str
    results: list[ActionTargetResult]


# --- Macros and ONU-registration templates (Plan 38) ---

class MacroParameter(Strict):
    key: str
    type: Literal["select_predefined", "select_from_variable", "input_string"]
    variants: list[str] | None = None
    source: str | None = None
    pattern: str | None = None


class MacroIn(Strict):
    name: str = Field(min_length=1, max_length=200)
    description: str = Field(default="", max_length=2000)
    template: str = Field(min_length=1, max_length=20000)
    parameters: list[MacroParameter] = Field(default_factory=list, max_length=40)
    display_output: Literal["none", "all", "last"] = "none"
    model_keys: list[str] = Field(default_factory=list, max_length=200)


class MacroUpdate(MacroIn):
    version: int = Field(ge=1)


class MacroOut(Strict):
    id: str
    kind: Literal["macro", "onu_registration"]
    name: str
    description: str
    template: str
    parameters: list[MacroParameter]
    display_output: Literal["none", "all", "last"]
    model_keys: list[str]
    version: int
    created_at: datetime
    updated_at: datetime


class MacroList(Strict):
    items: list[MacroOut]


class MacroPreviewIn(Strict):
    params: dict[str, str] = Field(default_factory=dict)
    variables: dict[str, Any] = Field(default_factory=dict)


class MacroDraftPreviewIn(MacroPreviewIn):
    template: str = Field(min_length=1, max_length=20000)
    parameters: list[MacroParameter] = Field(default_factory=list, max_length=40)


class MacroPreviewOut(Strict):
    commands: list[str]
    aborted: str | None


class ProtectionOut(Strict):
    protected: bool


# --- Diagnostics (Plan 38) ---

class PingRequest(Strict):
    device_id: str = Field(pattern=r"^[0-9a-fA-F-]{36}$")
    count: int = Field(default=4, ge=1, le=5)


class PingSummary(Strict):
    sent: int
    received: int
    loss_percent: float
    min_ms: float | None
    avg_ms: float | None
    max_ms: float | None


class DiagnosticOut(Strict):
    request_id: str
    device_id: str
    status: Literal["queued", "succeeded", "failed", "refused"]
    result: PingSummary | None = None
    error: str | None = None


# --- Console (Plan 38) ---

class ConsoleRequest(Strict):
    device_id: str = Field(pattern=r"^[0-9a-fA-F-]{36}$")
    auto_auth: bool = False


class ConsoleTicket(Strict):
    session_id: str
    ticket: str
    expires_at: datetime
    gateway_path: str


class ConsoleSessionOut(Strict):
    id: str
    user_id: str | None
    username: str | None
    device_id: str | None
    device_name: str
    auto_auth: bool
    status: Literal["pending", "open", "closed", "expired"]
    created_at: datetime
    opened_at: datetime | None
    closed_at: datetime | None
    close_reason: str | None


class ConsoleSessionList(Strict):
    items: list[ConsoleSessionOut]


class ConsoleChunk(Strict):
    seq: int
    direction: Literal["in", "out"]
    data: str
    at: datetime


class ConsoleHistory(Strict):
    session: ConsoleSessionOut
    chunks: list[ConsoleChunk]


# --- Topology links (Plan 27) ---

class LinkEnd(Strict):
    device_id: str | None
    name: str | None
    ip: str | None
    interface_id: str | None
    interface: str | None
    visible: bool
    state: Literal["up", "down", "unknown"] | None


class LinkOut(Strict):
    id: str
    source: Literal["manual", "fdb", "lldp"]
    description: str | None
    src: LinkEnd
    dest: LinkEnd
    state: Literal["up", "down", "unknown"]
    created_at: datetime
    updated_at: datetime


class LinkList(Strict):
    items: list[LinkOut]
    truncated: bool


_UUID = r"^[0-9a-fA-F-]{36}$"


class LinkCreate(Strict):
    src_device_id: str = Field(pattern=_UUID)
    src_interface_id: str | None = Field(default=None, pattern=_UUID)
    dest_device_id: str = Field(pattern=_UUID)
    dest_interface_id: str | None = Field(default=None, pattern=_UUID)
    description: str | None = Field(default=None, max_length=255)


class LinkUpdate(Strict):
    src_interface_id: str | None = Field(default=None, pattern=_UUID)
    dest_interface_id: str | None = Field(default=None, pattern=_UUID)
    description: str | None = Field(default=None, max_length=255)


class GraphNode(Strict):
    id: str
    name: str | None
    ip: str | None
    visible: bool


class GraphEdge(Strict):
    id: str
    from_: str = Field(alias="from")
    to: str
    state: Literal["up", "down", "unknown"]
    from_interface: str | None
    to_interface: str | None


class TopologyGraph(Strict):
    nodes: list[GraphNode]
    edges: list[GraphEdge]
    truncated: bool
