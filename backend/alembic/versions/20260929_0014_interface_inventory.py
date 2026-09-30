"""Interface inventory: status-change history, favorites and tags, ported from the real, production
device_interfaces_tags design (src/Storage/Devices/DeviceInterfaceTagStorage.php).

Revision ID: 20260929_0014
Revises: 20260929_0013
Create Date: 2026-09-29

Marks and tags are global to the interface in the legacy system, not per-user: `DeviceInterfaceTagStorage` keys
everything by `interface_id` alone, with no `user_id` anywhere in it. This keeps that shape but normalizes the
legacy single polymorphic `device_interfaces_tags(type, value)` table into two proper tables, per
docs/cybersathy-nms-migration/data-model.md's target design: `interface_marks` (a row's existence is the favorite,
the same existence-based semantics the legacy `INSERT IGNORE` / `DELETE` pair already used) and `interface_tags`
(one row per tag, unique per interface).

`interface_status_history` is a TimescaleDB hypertable on `changed_at` (D-01). Nothing writes to it yet: no OID
profile decodes a poll result into an interface row (that needs Plans 11 and 13-19's vendor-specific parsing), so
the writer this table supports is tested directly rather than through a live poll.
"""

from __future__ import annotations

from alembic import op
import sqlalchemy as sa
from sqlalchemy.dialects import postgresql

revision = "20260929_0014"
down_revision = "20260929_0013"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.create_table(
        "interface_status_history",
        sa.Column("id", postgresql.UUID(as_uuid=True), server_default=sa.text("gen_random_uuid()"), nullable=False),
        sa.Column("changed_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.Column("interface_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("interfaces.id", ondelete="CASCADE"), nullable=False),
        sa.Column("device_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("devices.id", ondelete="CASCADE"), nullable=False),
        sa.Column("admin_status", sa.String(length=40), nullable=False),
        sa.Column("oper_status", sa.String(length=40), nullable=False),
        sa.PrimaryKeyConstraint("id", "changed_at"),
    )
    op.create_index("ix_interface_status_history_interface_id", "interface_status_history", ["interface_id", "changed_at"])
    op.execute("select create_hypertable('interface_status_history', 'changed_at', chunk_time_interval => interval '7 days')")
    op.execute(
        "alter table interface_status_history set (timescaledb.compress, "
        "timescaledb.compress_segmentby = 'interface_id', timescaledb.compress_orderby = 'changed_at desc')"
    )
    op.execute("select add_compression_policy('interface_status_history', interval '30 days')")
    op.execute("select add_retention_policy('interface_status_history', interval '1 year')")

    op.create_table(
        "interface_marks",
        sa.Column("interface_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("interfaces.id", ondelete="CASCADE"), primary_key=True),
        sa.Column("favorited_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
    )

    op.create_table(
        "interface_tags",
        sa.Column("id", postgresql.UUID(as_uuid=True), server_default=sa.text("gen_random_uuid()"), primary_key=True),
        sa.Column("interface_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("interfaces.id", ondelete="CASCADE"), nullable=False),
        sa.Column("value", sa.String(length=60), nullable=False),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.CheckConstraint("length(value) > 0", name="ck_interface_tags_value_not_empty"),
        sa.UniqueConstraint("interface_id", "value", name="uq_interface_tags_interface_value"),
    )
    op.create_index("ix_interface_tags_value", "interface_tags", ["value"])


def downgrade() -> None:
    op.drop_index("ix_interface_tags_value", table_name="interface_tags")
    op.drop_table("interface_tags")
    op.drop_table("interface_marks")

    # interface_status_history was created (and made a hypertable) by this same migration's upgrade(), so downgrading
    # it just drops it: dropping a hypertable cascades to its chunks and to the compression/retention policies
    # attached to it (see 20260929_0013's downgrade for the same reasoning, and the bug it fixed).
    op.execute("drop table interface_status_history")
