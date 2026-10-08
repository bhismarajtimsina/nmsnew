"""Queued device actions and protected interfaces (Plan 38).

Revision ID: 20261008_0024
Revises: 20261005_0023
Create Date: 2026-10-08

An action's results are now written when it is confirmed (`queued`), claimed by a worker (`running`) and finished
(`succeeded`, `failed` or `refused`), so the API process never talks to a device and a requester can watch progress.

`interfaces.protected` marks a port that must not be shut down by an action, typically the uplink a switch is managed
through: disabling it would cut the NMS off from the device. Operators set it; it defaults to false.
"""
from __future__ import annotations

from alembic import op
import sqlalchemy as sa

revision = "20261008_0024"
down_revision = "20261005_0023"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.drop_constraint("ck_action_results_status", "action_results", type_="check")
    op.create_check_constraint("ck_action_results_status", "action_results",
                               "status in ('queued', 'running', 'succeeded', 'failed', 'refused')")
    op.drop_constraint("ck_action_results_failure_says_why", "action_results", type_="check")
    op.create_check_constraint("ck_action_results_failure_says_why", "action_results",
                               "status in ('queued', 'running', 'succeeded') or error is not null")
    op.create_check_constraint("ck_action_results_finished", "action_results",
                               "(status in ('queued', 'running')) = (finished_at is null)")
    op.add_column("interfaces", sa.Column("protected", sa.Boolean(), nullable=False, server_default=sa.text("false")))


def downgrade() -> None:
    op.drop_column("interfaces", "protected")
    op.execute("delete from action_results where status in ('queued', 'running')")
    op.drop_constraint("ck_action_results_finished", "action_results", type_="check")
    op.drop_constraint("ck_action_results_failure_says_why", "action_results", type_="check")
    op.create_check_constraint("ck_action_results_failure_says_why", "action_results", "status = 'succeeded' or error is not null")
    op.drop_constraint("ck_action_results_status", "action_results", type_="check")
    op.create_check_constraint("ck_action_results_status", "action_results", "status in ('succeeded', 'failed', 'refused')")
