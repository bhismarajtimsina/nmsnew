"""Stop-on-first-failure for bulk device actions (Plan 38).

Revision ID: 20261009_0025
Revises: 20261008_0024
Create Date: 2026-10-09

A bulk request can ask to stop at the first target that does not succeed. The choice is part of what the user
confirmed, so it is stored on the confirmation and a token presented with the other choice is rejected. Targets left
unrun when a request stops are recorded as `skipped`, with the reason, rather than silently dropped.
"""
from __future__ import annotations

from alembic import op
import sqlalchemy as sa

revision = "20261009_0025"
down_revision = "20261008_0024"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.add_column("action_confirmations",
                  sa.Column("stop_on_failure", sa.Boolean(), nullable=False, server_default=sa.text("false")))
    op.drop_constraint("ck_action_results_status", "action_results", type_="check")
    op.create_check_constraint("ck_action_results_status", "action_results",
                               "status in ('queued', 'running', 'succeeded', 'failed', 'refused', 'skipped')")


def downgrade() -> None:
    op.execute("update action_results set status = 'refused' where status = 'skipped'")
    op.drop_constraint("ck_action_results_status", "action_results", type_="check")
    op.create_check_constraint("ck_action_results_status", "action_results",
                               "status in ('queued', 'running', 'succeeded', 'failed', 'refused')")
    op.drop_column("action_confirmations", "stop_on_failure")
