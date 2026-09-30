"""Pinger: current up/down status and history, ported from the real legacy shape (`c_pinger_statuses`,
`c_pinger_down_logs`, `components/Pinger/migrations/01_init/up.sql`).

Revision ID: 20260929_0016
Revises: 20260929_0015
Create Date: 2026-09-29

The legacy Go pinger's own up/down verdict never becomes a `c_events` row directly - confirmed by reading the real
pipeline: `components/Pinger/Controllers/Controller.php` only updates `c_pinger_statuses` and sets a Prometheus gauge
(`pinger_host_status`). The actual alarm is Prometheus scraping that gauge and Alertmanager firing the
`pinger_host_down` rule (`pinger_host_status <= 0` for 1m), which is already ported byte-for-byte in
app/registry/alarm_rule_data.py and reaches this system through the same `/webhooks/alertmanager` endpoint Plan 20
built for every other Alertmanager rule. This migration's tables carry only status and history - no alarm logic
belongs here, matching the legacy shape exactly.

`device_ping_history` is a TimescaleDB hypertable on `changed_at` (D-01): only a real state transition is recorded,
never every individual check, the same design `interface_status_history` already uses.
"""

from __future__ import annotations

from alembic import op
import sqlalchemy as sa
from sqlalchemy.dialects import postgresql

revision = "20260929_0016"
down_revision = "20260929_0015"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.create_table(
        "device_ping_status",
        sa.Column("device_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("devices.id", ondelete="CASCADE"), primary_key=True),
        sa.Column("status", sa.String(length=10), nullable=False, server_default=sa.text("'unknown'")),
        sa.Column("consecutive_misses", sa.Integer(), nullable=False, server_default=sa.text("0")),
        sa.Column("latency_ms", sa.Float(), nullable=True),
        sa.Column("last_checked_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("last_changed_at", sa.DateTime(timezone=True), nullable=True),
        sa.CheckConstraint("status in ('unknown', 'up', 'down')", name="ck_device_ping_status_status"),
        sa.CheckConstraint("consecutive_misses >= 0", name="ck_device_ping_status_misses"),
    )

    op.create_table(
        "device_ping_history",
        sa.Column("id", postgresql.UUID(as_uuid=True), server_default=sa.text("gen_random_uuid()"), nullable=False),
        sa.Column("changed_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.Column("device_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("devices.id", ondelete="CASCADE"), nullable=False),
        sa.Column("status", sa.String(length=10), nullable=False),
        sa.Column("latency_ms", sa.Float(), nullable=True),
        sa.PrimaryKeyConstraint("id", "changed_at"),
        sa.CheckConstraint("status in ('unknown', 'up', 'down')", name="ck_device_ping_history_status"),
    )
    op.create_index("ix_device_ping_history_device_id", "device_ping_history", ["device_id", "changed_at"])
    op.execute("select create_hypertable('device_ping_history', 'changed_at', chunk_time_interval => interval '7 days')")
    op.execute(
        "alter table device_ping_history set (timescaledb.compress, "
        "timescaledb.compress_segmentby = 'device_id', timescaledb.compress_orderby = 'changed_at desc')"
    )
    op.execute("select add_compression_policy('device_ping_history', interval '30 days')")
    op.execute("select add_retention_policy('device_ping_history', interval '1 year')")


def downgrade() -> None:
    # device_ping_history was created (and made a hypertable) by this same migration's upgrade(), so downgrading it
    # just drops it: dropping a hypertable cascades to its chunks and to the compression/retention policies on it.
    op.execute("drop table device_ping_history")
    op.drop_table("device_ping_status")
