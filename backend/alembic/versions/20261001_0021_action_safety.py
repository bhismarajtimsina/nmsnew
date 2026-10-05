"""Dangerous-action safety (Plan 26): single-use confirmation tokens and one result record per target.

Revision ID: 20261001_0021
Revises: 20261001_0020
Create Date: 2026-10-01

A confirmation is created by a dry run that lists every target, and is bound to the user, the action, the exact
target list and a digest of the parameters. It is consumed once, before expiry, by the same user for the same action,
targets and parameters. Only the token's hash is stored. The database caps the lifetime and the batch size so a bug in
the service cannot widen either. Legacy had no confirmation step: an action ran on the first request.
"""
from __future__ import annotations

from alembic import op
import sqlalchemy as sa
from sqlalchemy.dialects import postgresql

revision = "20261001_0021"
down_revision = "20261001_0020"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.create_table(
        "action_confirmations",
        sa.Column("id", postgresql.UUID(as_uuid=True), server_default=sa.text("gen_random_uuid()"), primary_key=True),
        sa.Column("token_hash", sa.String(64), nullable=False, unique=True),
        sa.Column("user_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("users.id", ondelete="CASCADE"), nullable=False),
        sa.Column("action", sa.String(80), nullable=False),
        sa.Column("targets", postgresql.JSONB(), nullable=False),
        sa.Column("target_count", sa.Integer(), nullable=False),
        sa.Column("params_digest", sa.String(64), nullable=False),
        sa.Column("summary", postgresql.JSONB(), nullable=False),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.Column("expires_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column("used_at", sa.DateTime(timezone=True), nullable=True),
        sa.CheckConstraint("expires_at > created_at and expires_at - created_at <= interval '10 minutes'",
                           name="ck_action_confirmations_lifetime"),
        sa.CheckConstraint("target_count between 1 and 500 and jsonb_array_length(targets) = target_count",
                           name="ck_action_confirmations_targets"),
    )
    op.create_index("ix_action_confirmations_user_id", "action_confirmations", ["user_id"])

    op.create_table(
        "action_results",
        sa.Column("id", postgresql.UUID(as_uuid=True), server_default=sa.text("gen_random_uuid()"), primary_key=True),
        sa.Column("confirmation_id", postgresql.UUID(as_uuid=True),
                  sa.ForeignKey("action_confirmations.id", ondelete="CASCADE"), nullable=False),
        sa.Column("user_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("users.id", ondelete="SET NULL"), nullable=True),
        sa.Column("action", sa.String(80), nullable=False),
        sa.Column("target", postgresql.JSONB(), nullable=False),
        sa.Column("status", sa.String(16), nullable=False),
        sa.Column("error", sa.Text(), nullable=True),
        sa.Column("before", postgresql.JSONB(), nullable=True),
        sa.Column("after", postgresql.JSONB(), nullable=True),
        sa.Column("started_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.Column("finished_at", sa.DateTime(timezone=True), nullable=True),
        sa.CheckConstraint("status in ('succeeded', 'failed', 'refused')", name="ck_action_results_status"),
        sa.CheckConstraint("status = 'succeeded' or error is not null", name="ck_action_results_failure_says_why"),
    )
    op.create_index("ix_action_results_confirmation_id", "action_results", ["confirmation_id"])
    op.create_index("ix_action_results_started_at", "action_results", ["started_at"])


def downgrade() -> None:
    op.drop_index("ix_action_results_started_at", table_name="action_results")
    op.drop_index("ix_action_results_confirmation_id", table_name="action_results")
    op.drop_table("action_results")
    op.drop_index("ix_action_confirmations_user_id", table_name="action_confirmations")
    op.drop_table("action_confirmations")
