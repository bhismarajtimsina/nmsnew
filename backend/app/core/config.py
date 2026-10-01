from __future__ import annotations

import os
from dataclasses import dataclass


def _bool(name: str, default: bool) -> bool:
    raw = os.getenv(name)
    if raw is None:
        return default
    return raw.strip().lower() in {"1", "true", "yes", "on"}


def _csv(name: str, default: str) -> tuple[str, ...]:
    return tuple(part.strip() for part in os.getenv(name, default).split(",") if part.strip())


@dataclass
class Settings:
    app_name: str
    environment: str
    api_prefix: str
    log_level: str

    session_ttl_hours: int
    session_cookie_name: str

    postgres_host: str
    postgres_port: int
    postgres_db: str
    postgres_user: str
    postgres_password: str | None
    postgres_pool_min: int
    postgres_pool_max: int

    redis_url: str | None

    trusted_proxies: tuple[str, ...]

    login_max_attempts: int
    login_lockout_base_seconds: int
    login_lockout_max_seconds: int
    login_ip_max_attempts: int
    login_failure_window_seconds: int
    password_min_length: int

    encryption_keys: str | None
    encryption_active_key_id: str | None

    check_schema_on_start: bool

    # When set, a device's management address must fall inside one of these networks (comma-separated CIDRs).
    management_networks: tuple[str, ...]

    # Workers and polling. See docs/cybersathy-nms-migration/11-polling-engine.md and 12-worker-services.md.
    job_signing_key: str | None
    worker_max_deliveries: int
    worker_retry_base_ms: int
    worker_concurrency: int
    snmp_transport: str
    poll_min_interval_seconds: int
    poll_breaker_threshold: int
    poll_breaker_cooldown_seconds: int
    poll_vendor_concurrency: int
    poll_device_lock_ms: int
    poll_manual_per_minute: int
    scheduler_timezone: str
    scheduler_tick_seconds: int

    # SNMP trap receiver. See docs/cybersathy-nms-migration/21-snmp-trap-service.md.
    trap_listener_host: str
    trap_listener_port: int
    trap_check_community: bool
    trap_source_rate: float
    trap_source_burst: float
    trap_global_rate: float
    trap_global_burst: float
    trap_max_in_flight: int

    # Flapping suppression for events. See docs/cybersathy-nms-migration/20-events-alarms.md.
    event_flap_window_seconds: int
    # Alertmanager's base URL, for the sync_active_alerts job. Empty until Alertmanager joins this stack (Plan 31).
    alertmanager_url: str

    # ICMP pinger. See docs/cybersathy-nms-migration/12-worker-services.md and own-components.md §3.1.
    pinger_cycle_seconds: int
    pinger_count: int
    pinger_timeout_seconds: float
    pinger_misses_for_down: int
    pinger_privileged: bool
    pinger_metrics_port: int

    # Notification sender. See docs/cybersathy-nms-migration/29-notifications.md and 12-worker-services.md.
    notification_sender_idle_seconds: int
    notification_sender_max_concurrent: int
    notification_check_previous_message: bool

    @property
    def is_production(self) -> bool:
        return self.environment.lower() == "production"

    @property
    def postgres_dsn(self) -> str:
        if not self.postgres_password:
            raise RuntimeError("POSTGRES_PASSWORD is not set; refusing to connect without an explicit password")
        return (
            f"postgresql://{self.postgres_user}:{self.postgres_password}"
            f"@{self.postgres_host}:{self.postgres_port}/{self.postgres_db}"
        )

    @property
    def redis_dsn(self) -> str:
        if not self.redis_url:
            raise RuntimeError("REDIS_URL is not set")
        return self.redis_url


def load_settings() -> Settings:
    """Read the environment now. Called once at import; tests may call it again after changing the environment."""
    return Settings(
        app_name=os.getenv("APP_NAME", "CyberSathy-NMS"),
        environment=os.getenv("ENVIRONMENT", "development"),
        api_prefix=os.getenv("API_PREFIX", "/api/v1"),
        log_level=os.getenv("LOG_LEVEL", "INFO"),
        session_ttl_hours=int(os.getenv("SESSION_TTL_HOURS", "24")),
        session_cookie_name="cs_session",
        postgres_host=os.getenv("POSTGRES_HOST", "cybersathy-postgres"),
        postgres_port=int(os.getenv("POSTGRES_PORT", "5432")),
        postgres_db=os.getenv("POSTGRES_DB", "cybersathy_nms"),
        postgres_user=os.getenv("POSTGRES_USER", "cybersathy"),
        # Deliberately no default: a missing password must stop the process, not fall back to a known value.
        postgres_password=os.getenv("POSTGRES_PASSWORD") or None,
        postgres_pool_min=int(os.getenv("POSTGRES_POOL_MIN", "1")),
        postgres_pool_max=int(os.getenv("POSTGRES_POOL_MAX", "10")),
        redis_url=os.getenv("REDIS_URL") or None,
        # Proxies whose X-Forwarded-For is believed. A client connecting directly is never trusted to set it.
        trusted_proxies=_csv("FORWARDED_ALLOW_IPS", "127.0.0.1"),
        login_max_attempts=int(os.getenv("LOGIN_MAX_ATTEMPTS", "5")),
        login_lockout_base_seconds=int(os.getenv("LOGIN_LOCKOUT_BASE_SECONDS", "30")),
        login_lockout_max_seconds=int(os.getenv("LOGIN_LOCKOUT_MAX_SECONDS", "900")),
        login_ip_max_attempts=int(os.getenv("LOGIN_IP_MAX_ATTEMPTS", "25")),
        login_failure_window_seconds=int(os.getenv("LOGIN_FAILURE_WINDOW_SECONDS", "900")),
        password_min_length=int(os.getenv("PASSWORD_MIN_LENGTH", "12")),
        # "key_id:base64key" pairs. The active key encrypts; every listed key can decrypt (rotation).
        encryption_keys=os.getenv("ENCRYPTION_KEYS") or None,
        encryption_active_key_id=os.getenv("ENCRYPTION_ACTIVE_KEY_ID") or None,
        check_schema_on_start=_bool("CHECK_SCHEMA_ON_START", True),
        management_networks=_csv("MANAGEMENT_NETWORKS", ""),
        job_signing_key=os.getenv("JOB_SIGNING_KEY") or None,
        worker_max_deliveries=int(os.getenv("WORKER_MAX_DELIVERIES", "3")),
        worker_retry_base_ms=int(os.getenv("WORKER_RETRY_BASE_MS", "30000")),
        worker_concurrency=int(os.getenv("WORKER_CONCURRENCY", "8")),
        # "disabled" means the workers never open an SNMP session. No real transport exists in this build.
        snmp_transport=os.getenv("SNMP_TRANSPORT", "disabled"),
        poll_min_interval_seconds=int(os.getenv("POLL_MIN_INTERVAL_SECONDS", "30")),
        poll_breaker_threshold=int(os.getenv("POLL_BREAKER_THRESHOLD", "5")),
        poll_breaker_cooldown_seconds=int(os.getenv("POLL_BREAKER_COOLDOWN_SECONDS", "300")),
        poll_vendor_concurrency=int(os.getenv("POLL_VENDOR_CONCURRENCY", "4")),
        poll_device_lock_ms=int(os.getenv("POLL_DEVICE_LOCK_MS", "60000")),
        poll_manual_per_minute=int(os.getenv("POLL_MANUAL_PER_MINUTE", "6")),
        scheduler_timezone=os.getenv("SCHEDULER_TIMEZONE", "UTC"),
        scheduler_tick_seconds=int(os.getenv("SCHEDULER_TICK_SECONDS", "10")),
        trap_listener_host=os.getenv("TRAP_LISTENER_HOST", "0.0.0.0"),
        # 1162, not the standard 162: that port is privileged and is the legacy listener's own until cutover hands
        # it over (D-19).
        trap_listener_port=int(os.getenv("TRAP_LISTENER_PORT", "1162")),
        # TRAP_SERVICE_CHECK_COMMUNITY in the real .env - off in production, matched here exactly.
        trap_check_community=_bool("TRAP_CHECK_COMMUNITY", False),
        # Flood protection (risk K-15). Traps per second, refilled continuously, with a burst allowance on top: an OLT
        # reporting a PON-wide LOS legitimately sends one trap per ONU at once, so the per-source burst is generous.
        # Not yet tuned against real trap volumes - revisit once the receiver runs in observe mode.
        trap_source_rate=float(os.getenv("TRAP_SOURCE_RATE", "50")),
        trap_source_burst=float(os.getenv("TRAP_SOURCE_BURST", "500")),
        trap_global_rate=float(os.getenv("TRAP_GLOBAL_RATE", "1000")),
        trap_global_burst=float(os.getenv("TRAP_GLOBAL_BURST", "5000")),
        # Matches legacy's 500-packet queue (.trap-listener.yml, script_handler.queue_size).
        trap_max_in_flight=int(os.getenv("TRAP_MAX_IN_FLIGHT", "500")),
        # An alarm that fires again within this many seconds of Alertmanager resolving it reopens the same event.
        # 0 turns flapping suppression off (legacy behavior: a new event per firing).
        event_flap_window_seconds=int(os.getenv("EVENT_FLAP_WINDOW_SECONDS", "900")),
        alertmanager_url=os.getenv("ALERTMANAGER_URL", ""),
        pinger_cycle_seconds=int(os.getenv("PINGER_CYCLE_SECONDS", "30")),
        pinger_count=int(os.getenv("PINGER_COUNT", "3")),
        pinger_timeout_seconds=float(os.getenv("PINGER_TIMEOUT_SECONDS", "1.0")),
        pinger_misses_for_down=int(os.getenv("PINGER_MISSES_FOR_DOWN", "3")),
        # Unprivileged ICMP (Linux datagram sockets) needs no capability at all, unlike the legacy Go binary's raw
        # sockets. Set true only where the kernel's ping_group_range disallows it.
        pinger_privileged=_bool("PINGER_PRIVILEGED", False),
        pinger_metrics_port=int(os.getenv("PINGER_METRICS_PORT", "9101")),
        # 3 seconds and 20, matching NotificationSenderService.php's sleep(3) and COUNT_PROCS exactly.
        notification_sender_idle_seconds=int(os.getenv("NOTIFICATION_SENDER_IDLE_SECONDS", "3")),
        notification_sender_max_concurrent=int(os.getenv("NOTIFICATION_SENDER_MAX_CONCURRENT", "20")),
        # NOTIFICATIONS_CHECK_PREVIOUS_MESSAGE in the real .env - off in production.
        notification_check_previous_message=_bool("NOTIFICATION_CHECK_PREVIOUS_MESSAGE", False),
    )


settings = load_settings()
