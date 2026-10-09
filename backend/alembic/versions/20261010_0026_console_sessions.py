"""Console sessions and their transcripts (Plan 38).

Revision ID: 20261010_0026
Revises: 20261009_0025
Create Date: 2026-10-10

A console session starts as a request: the API checks permission, scope and limits and returns a single-use ticket
(stored hashed) that the console gateway redeems within seconds. The gateway records every byte in and out in
`console_history`, with input typed at a password prompt replaced by a marker, and closes the session with a reason.
"""
from __future__ import annotations

from alembic import op
import sqlalchemy as sa
from sqlalchemy.dialects import postgresql

revision = "20261010_0026"
down_revision = "20261009_0025"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.create_table(
        "console_sessions",
        sa.Column("id", postgresql.UUID(as_uuid=True), server_default=sa.text("gen_random_uuid()"), primary_key=True),
        sa.Column("user_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("users.id", ondelete="SET NULL"), nullable=True),
        sa.Column("device_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("devices.id", ondelete="SET NULL"), nullable=True),
        sa.Column("device_name", sa.String(255), nullable=False),
        sa.Column("auto_auth", sa.Boolean(), nullable=False, server_default=sa.text("false")),
        sa.Column("status", sa.String(16), nullable=False, server_default="pending"),
        sa.Column("ticket_hash", sa.String(64), nullable=False, unique=True),
        sa.Column("ticket_expires_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.Column("opened_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("closed_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("close_reason", sa.String(120), nullable=True),
        sa.Column("client_ip", postgresql.INET(), nullable=True),
        sa.CheckConstraint("status in ('pending', 'open', 'closed', 'expired')", name="ck_console_sessions_status"),
        sa.CheckConstraint("ticket_expires_at > created_at and ticket_expires_at - created_at <= interval '2 minutes'",
                           name="ck_console_sessions_ticket_lifetime"),
        sa.CheckConstraint("(status = 'open') = (opened_at is not null and closed_at is null)", name="ck_console_sessions_open"),
        sa.CheckConstraint("(status = 'closed') = (closed_at is not null)", name="ck_console_sessions_closed"),
    )
    op.create_index("ix_console_sessions_device_status", "console_sessions", ["device_id", "status"])
    op.create_index("ix_console_sessions_user_status", "console_sessions", ["user_id", "status"])
    op.create_table(
        "console_history",
        sa.Column("id", sa.BigInteger(), sa.Identity(), primary_key=True),
        sa.Column("session_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("console_sessions.id", ondelete="CASCADE"), nullable=False),
        sa.Column("seq", sa.Integer(), nullable=False),
        sa.Column("direction", sa.String(3), nullable=False),
        sa.Column("data", sa.Text(), nullable=False),
        sa.Column("at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.CheckConstraint("direction in ('in', 'out')", name="ck_console_history_direction"),
        sa.CheckConstraint("length(data) <= 65536", name="ck_console_history_chunk"),
        sa.UniqueConstraint("session_id", "seq", name="uq_console_history_seq"),
    )


def downgrade() -> None:
    op.drop_table("console_history")
    op.drop_index("ix_console_sessions_user_status", table_name="console_sessions")
    op.drop_index("ix_console_sessions_device_status", table_name="console_sessions")
    op.drop_table("console_sessions")
