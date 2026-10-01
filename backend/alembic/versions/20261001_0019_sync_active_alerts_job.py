"""Allow the sync_active_alerts job type (Plan 20's port of legacy `wca sync-active-alerts`).

Revision ID: 20261001_0019
Revises: 20261001_0018
Create Date: 2026-10-01
"""
from __future__ import annotations

from alembic import op

revision = "20261001_0019"
down_revision = "20261001_0018"
branch_labels = None
depends_on = None

BEFORE = "('poll_group','retention','cleanup_sessions')"
AFTER = "('poll_group','retention','cleanup_sessions','sync_active_alerts')"


def upgrade() -> None:
    op.drop_constraint("ck_schedule_jobs_type", "schedule_jobs", type_="check")
    op.create_check_constraint("ck_schedule_jobs_type", "schedule_jobs", f"job_type in {AFTER}")


def downgrade() -> None:
    op.execute("delete from schedule_jobs where job_type = 'sync_active_alerts'")
    op.drop_constraint("ck_schedule_jobs_type", "schedule_jobs", type_="check")
    op.create_check_constraint("ck_schedule_jobs_type", "schedule_jobs", f"job_type in {BEFORE}")
