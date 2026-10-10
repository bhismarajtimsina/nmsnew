"""Interface traffic counter samples (Plan 27, link utilisation).

Revision ID: 20261010_0031
Revises: 20261010_0030
Create Date: 2026-10-10

One row per interface per interface_basic poll: the in and out octet counters as read, and ifSpeed where the device
gave a usable one. Rates are computed from consecutive samples at read time. Kept for seven days, which covers
legacy's longest utilisation period (6 hours) with room to spare; this is not a traffic history.
"""
from __future__ import annotations

from alembic import op
import sqlalchemy as sa
from sqlalchemy.dialects import postgresql

revision = "20261010_0031"
down_revision = "20261010_0030"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.create_table(
        "interface_counter_samples",
        sa.Column("sampled_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.Column("interface_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("interfaces.id", ondelete="CASCADE"), nullable=False),
        sa.Column("in_octets", sa.BigInteger(), nullable=True),
        sa.Column("out_octets", sa.BigInteger(), nullable=True),
        sa.Column("speed_bps", sa.BigInteger(), nullable=True),
        sa.CheckConstraint("in_octets is not null or out_octets is not null", name="ck_interface_counter_samples_has_counter"),
        sa.CheckConstraint("in_octets >= 0 and out_octets >= 0 and speed_bps > 0", name="ck_interface_counter_samples_non_negative"),
        sa.PrimaryKeyConstraint("interface_id", "sampled_at", name="pk_interface_counter_samples"),
    )
    op.execute("select create_hypertable('interface_counter_samples', 'sampled_at', chunk_time_interval => interval '1 day')")
    op.execute("select add_retention_policy('interface_counter_samples', interval '7 days')")


def downgrade() -> None:
    # Dropping a hypertable drops its chunks and its retention policy with it.
    op.execute("drop table interface_counter_samples")
