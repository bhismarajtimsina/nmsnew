"""Flapping suppression for events (Plan 20): an alarm that fires again shortly after Alertmanager resolved it
reopens the same row instead of adding a new one, and these columns record that it did.

Revision ID: 20261001_0018
Revises: 20260929_0017
Create Date: 2026-10-01

New capability, not a port: legacy inserts a fresh `c_events` row on every firing, so a flapping interface there is
as many events as it has flaps. See docs/cybersathy-nms-migration/20-events-alarms.md.
"""
from __future__ import annotations

from alembic import op
import sqlalchemy as sa

revision = "20261001_0018"
down_revision = "20260929_0017"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.add_column("events", sa.Column("flap_count", sa.Integer(), nullable=False, server_default=sa.text("0")))
    op.add_column("events", sa.Column("last_reopened_at", sa.DateTime(timezone=True), nullable=True))
    op.create_check_constraint("ck_events_flap_count", "events", "flap_count >= 0")


def downgrade() -> None:
    op.drop_constraint("ck_events_flap_count", "events", type_="check")
    op.drop_column("events", "last_reopened_at")
    op.drop_column("events", "flap_count")
