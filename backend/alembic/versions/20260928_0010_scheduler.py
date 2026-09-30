"""Scheduler: typed jobs on a cron schedule, and their run history.

Revision ID: 20260928_0010
Revises: 20260928_0009
Create Date: 2026-09-28

A job has a *type* from a fixed list and validated parameters. There is no free-form command field, so nothing stored here
can run an arbitrary program.
"""

from __future__ import annotations

from alembic import op
import sqlalchemy as sa
from sqlalchemy.dialects import postgresql

revision = "20260928_0010"
down_revision = "20260928_0009"
branch_labels = None
depends_on = None

JOB_TYPES = "('poll_group','retention','cleanup_sessions')"


def upgrade() -> None:
    op.create_table(
        "schedule_jobs",
        sa.Column("id", postgresql.UUID(as_uuid=True), server_default=sa.text("gen_random_uuid()"), primary_key=True),
        sa.Column("key", sa.String(length=80), nullable=False, unique=True),
        sa.Column("job_type", sa.String(length=30), nullable=False),
        sa.Column("params", postgresql.JSONB(astext_type=sa.Text()), nullable=False, server_default=sa.text("'{}'::jsonb")),
        sa.Column("crontab", sa.String(length=120), nullable=False),
        sa.Column("enabled", sa.Boolean(), nullable=False, server_default=sa.text("false")),
        sa.Column("editable", sa.Boolean(), nullable=False, server_default=sa.text("true")),
        sa.Column("misfire_policy", sa.String(length=10), nullable=False, server_default=sa.text("'skip'")),
        sa.Column("misfire_grace_seconds", sa.Integer(), nullable=False, server_default=sa.text("300")),
        sa.Column("catch_up_max", sa.Integer(), nullable=False, server_default=sa.text("3")),
        sa.Column("overlap_policy", sa.String(length=10), nullable=False, server_default=sa.text("'forbid'")),
        sa.Column("max_runtime_seconds", sa.Integer(), nullable=False, server_default=sa.text("600")),
        sa.Column("description", sa.Text(), nullable=True),
        sa.Column("last_run_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("next_run_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.Column("updated_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.CheckConstraint("key ~ '^[a-z0-9_]+$'", name="ck_schedule_jobs_key"),
        sa.CheckConstraint(f"job_type in {JOB_TYPES}", name="ck_schedule_jobs_type"),
        sa.CheckConstraint("misfire_policy in ('skip','run_once','catch_up')", name="ck_schedule_jobs_misfire"),
        sa.CheckConstraint("overlap_policy in ('forbid','allow')", name="ck_schedule_jobs_overlap"),
        sa.CheckConstraint("misfire_grace_seconds between 30 and 86400", name="ck_schedule_jobs_grace"),
        sa.CheckConstraint("catch_up_max between 1 and 10", name="ck_schedule_jobs_catch_up"),
        sa.CheckConstraint("max_runtime_seconds between 10 and 3600", name="ck_schedule_jobs_runtime"),
        sa.CheckConstraint("length(crontab) between 1 and 120", name="ck_schedule_jobs_crontab"),
    )
    op.create_index("ix_schedule_jobs_due", "schedule_jobs", ["next_run_at"], postgresql_where=sa.text("enabled"))

    op.create_table(
        "schedule_runs",
        sa.Column("id", postgresql.UUID(as_uuid=True), server_default=sa.text("gen_random_uuid()"), primary_key=True),
        sa.Column("job_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("schedule_jobs.id", ondelete="CASCADE"), nullable=False),
        sa.Column("scheduled_for", sa.DateTime(timezone=True), nullable=False),
        sa.Column("started_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.Column("finished_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("status", sa.String(length=10), nullable=False),
        sa.Column("output", sa.Text(), nullable=True),
        sa.Column("error", sa.Text(), nullable=True),
        sa.CheckConstraint("status in ('running','ok','error','skipped','misfired')", name="ck_schedule_runs_status"),
    )
    op.create_index("ix_schedule_runs_job_started", "schedule_runs", ["job_id", "started_at"])
    op.create_index("ix_schedule_runs_started", "schedule_runs", ["started_at"])


def downgrade() -> None:
    op.drop_index("ix_schedule_runs_started", table_name="schedule_runs")
    op.drop_index("ix_schedule_runs_job_started", table_name="schedule_runs")
    op.drop_table("schedule_runs")
    op.drop_index("ix_schedule_jobs_due", table_name="schedule_jobs")
    op.drop_table("schedule_jobs")
