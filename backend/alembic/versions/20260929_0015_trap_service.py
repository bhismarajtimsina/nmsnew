"""Trap service: the trap definition registry and trap history, ported from the real trap configs
(vendor/meklis/switcher-core/configs/traps/) and the legacy trap listener's own device-resolution rule.

Revision ID: 20260929_0015
Revises: 20260929_0014
Create Date: 2026-09-29

`trap_profiles` is code-owned vendor knowledge (like `device_models`): refreshed on every seed, not something an
operator edits here. Its OID is unique across every vendor - each vendor's traps live under its own IANA enterprise
number, so a decoded trap's OID alone identifies which profile it is, with no vendor lookup needed first.

`trap_history` is a TimescaleDB hypertable on `received_at` (D-01), a straight port of the legacy `c_trap_logs`
(`components/TrapService/migrations/01_init/up.sql`): a rolling diagnostic log, not an alarm store - the legacy
system never turned a trap into an event (confirmed by reading the real trap pipeline: `c_trap_logs` has no
severity, status or dedup column, and nothing in `components/TrapService/` ever touches `c_events`). Its 30-day
retention matches the legacy `trapservice_clear_old_logs` cron job exactly, rather than inventing a longer window.

A trap from a source IP that matches no device's management address is never stored here at all
(app/traps/ingest.py): there is nothing to keep from a source this system cannot attribute to a device, and storing
it would make this an easy way to fill the database from off-network.
"""

from __future__ import annotations

from alembic import op
import sqlalchemy as sa
from sqlalchemy.dialects import postgresql

revision = "20260929_0015"
down_revision = "20260929_0014"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.create_table(
        "trap_profiles",
        sa.Column("id", postgresql.UUID(as_uuid=True), server_default=sa.text("gen_random_uuid()"), primary_key=True),
        sa.Column("vendor", sa.String(length=40), nullable=False),
        sa.Column("name", sa.String(length=120), nullable=False),
        sa.Column("oid", sa.String(length=255), nullable=False, unique=True),
        sa.Column("is_interface", sa.Boolean(), nullable=False, server_default=sa.text("false")),
        sa.Column("modules", postgresql.ARRAY(sa.String(length=60)), nullable=False, server_default=sa.text("'{}'")),
        sa.Column("description", sa.Text(), nullable=False),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.Column("updated_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.UniqueConstraint("vendor", "name", name="uq_trap_profiles_vendor_name"),
        sa.CheckConstraint("length(oid) > 0", name="ck_trap_profiles_oid_not_empty"),
    )

    op.create_table(
        "trap_history",
        sa.Column("id", postgresql.UUID(as_uuid=True), server_default=sa.text("gen_random_uuid()"), nullable=False),
        sa.Column("received_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.Column("source_ip", postgresql.INET(), nullable=False),
        sa.Column("device_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("devices.id", ondelete="SET NULL"), nullable=True),
        sa.Column("trap_profile_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("trap_profiles.id", ondelete="SET NULL"), nullable=True),
        sa.Column("vendor", sa.String(length=40), nullable=True),
        sa.Column("trap_name", sa.String(length=120), nullable=True),
        sa.Column("trap_oid", sa.String(length=255), nullable=False),
        sa.Column("version", sa.String(length=10), nullable=False),
        sa.Column("varbinds", postgresql.JSONB(astext_type=sa.Text()), nullable=False, server_default=sa.text("'{}'::jsonb")),
        sa.PrimaryKeyConstraint("id", "received_at"),
        sa.CheckConstraint("version in ('v1', 'v2c')", name="ck_trap_history_version"),
    )
    op.create_index("ix_trap_history_device_id", "trap_history", ["device_id", "received_at"])
    op.create_index("ix_trap_history_unknown", "trap_history", ["received_at"], postgresql_where=sa.text("trap_profile_id is null"))
    op.execute("select create_hypertable('trap_history', 'received_at', chunk_time_interval => interval '7 days')")
    op.execute(
        "alter table trap_history set (timescaledb.compress, "
        "timescaledb.compress_segmentby = 'device_id', timescaledb.compress_orderby = 'received_at desc')"
    )
    op.execute("select add_compression_policy('trap_history', interval '3 days')")
    op.execute("select add_retention_policy('trap_history', interval '30 days')")


def downgrade() -> None:
    # trap_history was created (and made a hypertable) by this same migration's upgrade(), so downgrading it just
    # drops it: dropping a hypertable cascades to its chunks and to the compression/retention policies on it.
    op.execute("drop table trap_history")
    op.drop_table("trap_profiles")
