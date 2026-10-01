"""Maintenance windows (Plan 20, risk K-14): planned work on a device or a device group records its events as usual
but holds back their notifications, and releases any that are still open when the window ends.

Revision ID: 20261001_0020
Revises: 20261001_0019
Create Date: 2026-10-01

New capability. Legacy has no maintenance windows; operators silenced alerts in Alertmanager's own UI, which drops
the events entirely. See docs/cybersathy-nms-migration/20-events-alarms.md.
"""
from __future__ import annotations

from alembic import op
import sqlalchemy as sa
from sqlalchemy.dialects import postgresql

revision = "20261001_0020"
down_revision = "20261001_0019"
branch_labels = None
depends_on = None

BEFORE = "('poll_group','retention','cleanup_sessions','sync_active_alerts')"
AFTER = "('poll_group','retention','cleanup_sessions','sync_active_alerts','maintenance_release')"


def upgrade() -> None:
    op.create_table(
        "maintenance_windows",
        sa.Column("id", postgresql.UUID(as_uuid=True), server_default=sa.text("gen_random_uuid()"), primary_key=True),
        sa.Column("device_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("devices.id", ondelete="CASCADE"), nullable=True),
        sa.Column("device_group_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("device_groups.id", ondelete="CASCADE"), nullable=True),
        sa.Column("starts_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column("ends_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column("reason", sa.Text(), nullable=False),
        sa.Column("created_by_user_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("users.id", ondelete="SET NULL"), nullable=True),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.Column("canceled_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("canceled_by_user_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("users.id", ondelete="SET NULL"), nullable=True),
        sa.CheckConstraint("(device_id is null) <> (device_group_id is null)", name="ck_maintenance_windows_one_target"),
        sa.CheckConstraint("ends_at > starts_at", name="ck_maintenance_windows_order"),
        # A forgotten window must not silence a device for good.
        sa.CheckConstraint("ends_at - starts_at <= interval '7 days'", name="ck_maintenance_windows_max_length"),
        sa.CheckConstraint("length(reason) between 1 and 500", name="ck_maintenance_windows_reason"),
    )
    op.create_index("ix_maintenance_windows_device_id", "maintenance_windows", ["device_id"])
    op.create_index("ix_maintenance_windows_device_group_id", "maintenance_windows", ["device_group_id"])
    op.create_index("ix_maintenance_windows_active", "maintenance_windows", ["starts_at", "ends_at"],
                    postgresql_where=sa.text("canceled_at is null"))

    op.add_column("events", sa.Column("suppressed_by_maintenance", sa.Boolean(), nullable=False, server_default=sa.text("false")))

    op.drop_constraint("ck_schedule_jobs_type", "schedule_jobs", type_="check")
    op.create_check_constraint("ck_schedule_jobs_type", "schedule_jobs", f"job_type in {AFTER}")


def downgrade() -> None:
    op.execute("delete from schedule_jobs where job_type = 'maintenance_release'")
    op.drop_constraint("ck_schedule_jobs_type", "schedule_jobs", type_="check")
    op.create_check_constraint("ck_schedule_jobs_type", "schedule_jobs", f"job_type in {BEFORE}")
    op.drop_column("events", "suppressed_by_maintenance")
    op.drop_index("ix_maintenance_windows_active", table_name="maintenance_windows")
    op.drop_index("ix_maintenance_windows_device_group_id", table_name="maintenance_windows")
    op.drop_index("ix_maintenance_windows_device_id", table_name="maintenance_windows")
    op.drop_table("maintenance_windows")
