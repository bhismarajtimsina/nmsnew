"""Workers and polling: poll state and history, worker heartbeats, dead letters, discovery publishing state.

Revision ID: 20260928_0009
Revises: 20260928_0008
Create Date: 2026-09-28
"""

from __future__ import annotations

from alembic import op
import sqlalchemy as sa
from sqlalchemy.dialects import postgresql

revision = "20260928_0009"
down_revision = "20260928_0008"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.add_column("discovery_jobs", sa.Column("published_at", sa.DateTime(timezone=True), nullable=True))
    op.add_column("discovery_jobs", sa.Column("publish_attempts", sa.Integer(), nullable=False, server_default=sa.text("0")))
    op.create_check_constraint("ck_discovery_jobs_publish_attempts", "discovery_jobs", "publish_attempts between 0 and 10")

    op.create_table(
        "device_poll_state",
        sa.Column("device_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("devices.id", ondelete="CASCADE"), primary_key=True),
        sa.Column("consecutive_failures", sa.Integer(), nullable=False, server_default=sa.text("0")),
        sa.Column("breaker_open_until", sa.DateTime(timezone=True), nullable=True),
        sa.Column("last_polled_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("last_success_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("last_failure_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("last_error", sa.Text(), nullable=True),
        sa.CheckConstraint("consecutive_failures >= 0", name="ck_device_poll_state_failures"),
    )

    op.create_table(
        "polling_results",
        sa.Column("id", postgresql.UUID(as_uuid=True), server_default=sa.text("gen_random_uuid()"), nullable=False),
        sa.Column("device_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("devices.id", ondelete="CASCADE"), nullable=False),
        sa.Column("profile_name", sa.String(length=120), nullable=False),
        sa.Column("profile_version", sa.Integer(), nullable=True),
        sa.Column("started_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.Column("duration_ms", sa.Integer(), nullable=False, server_default=sa.text("0")),
        sa.Column("rows", sa.Integer(), nullable=False, server_default=sa.text("0")),
        sa.Column("truncated", sa.Boolean(), nullable=False, server_default=sa.text("false")),
        sa.Column("outcome", sa.String(length=10), nullable=False),
        sa.Column("error", sa.Text(), nullable=True),
        sa.PrimaryKeyConstraint("id", "started_at"),
        sa.CheckConstraint("outcome in ('ok','timeout','error','skipped')", name="ck_polling_results_outcome"),
        sa.CheckConstraint("outcome = 'ok' or error is not null", name="ck_polling_results_failure_says_why"),
    )
    op.create_index("ix_polling_results_device_started", "polling_results", ["device_id", "started_at"])
    op.execute("select create_hypertable('polling_results', 'started_at', chunk_time_interval => interval '1 day')")
    op.execute("alter table polling_results set (timescaledb.compress, timescaledb.compress_segmentby = 'device_id', timescaledb.compress_orderby = 'started_at desc')")
    op.execute("select add_compression_policy('polling_results', interval '2 days')")
    op.execute("select add_retention_policy('polling_results', interval '14 days')")

    op.create_table(
        "worker_heartbeats",
        sa.Column("worker_id", sa.String(length=160), primary_key=True),
        sa.Column("kind", sa.String(length=40), nullable=False),
        sa.Column("started_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.Column("last_seen", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.Column("status", sa.String(length=60), nullable=False),
        sa.Column("info", postgresql.JSONB(astext_type=sa.Text()), nullable=False, server_default=sa.text("'{}'::jsonb")),
    )

    op.create_table(
        "dead_letter_jobs",
        sa.Column("id", postgresql.UUID(as_uuid=True), server_default=sa.text("gen_random_uuid()"), primary_key=True),
        sa.Column("stream", sa.String(length=80), nullable=False),
        sa.Column("job_id", sa.String(length=80), nullable=True),
        sa.Column("message_id", sa.String(length=40), nullable=True),
        sa.Column("fields", postgresql.JSONB(astext_type=sa.Text()), nullable=False, server_default=sa.text("'{}'::jsonb")),
        sa.Column("reason", sa.String(length=200), nullable=False),
        sa.Column("deliveries", sa.Integer(), nullable=False, server_default=sa.text("0")),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.Column("resolved_at", sa.DateTime(timezone=True), nullable=True),
    )
    op.create_index("ix_dead_letter_jobs_created_at", "dead_letter_jobs", ["created_at"])


def downgrade() -> None:
    op.drop_index("ix_dead_letter_jobs_created_at", table_name="dead_letter_jobs")
    op.drop_table("dead_letter_jobs")
    op.drop_table("worker_heartbeats")
    op.execute("select remove_retention_policy('polling_results', if_exists => true)")
    op.execute("select remove_compression_policy('polling_results', if_exists => true)")
    op.drop_index("ix_polling_results_device_started", table_name="polling_results")
    op.drop_table("polling_results")
    op.drop_table("device_poll_state")
    op.drop_constraint("ck_discovery_jobs_publish_attempts", "discovery_jobs", type_="check")
    op.drop_column("discovery_jobs", "publish_attempts")
    op.drop_column("discovery_jobs", "published_at")
