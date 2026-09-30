"""Events and alarms: the alarm rule registry and the events table, ported from the real, production-validated
c_events / c_events_alertmanager_rules design.

Revision ID: 20260929_0013
Revises: 20260929_0012
Create Date: 2026-09-29

An unresolved row *is* the open alarm; a resolved row is history — the same single-table shape the legacy system uses
(confirmed on real data: components/Events/Api/GetIncidentsAction.php's own commit message reports 961 open events
collapsing to 64 real incidents against this exact schema). `events` is a TimescaleDB hypertable on `occurred_at`;
`resolved_at` is still updatable in place, which TimescaleDB supports.
"""

from __future__ import annotations

from alembic import op
import sqlalchemy as sa
from sqlalchemy.dialects import postgresql

revision = "20260929_0013"
down_revision = "20260929_0012"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.create_table(
        "alarm_rules",
        sa.Column("id", postgresql.UUID(as_uuid=True), server_default=sa.text("gen_random_uuid()"), primary_key=True),
        sa.Column("group_name", sa.String(length=100), nullable=False),
        sa.Column("alert_name", sa.String(length=150), nullable=False, unique=True),
        sa.Column("expression", sa.Text(), nullable=False),
        sa.Column("for_duration", sa.String(length=30), nullable=False),
        sa.Column("severity", sa.String(length=10), nullable=False),
        sa.Column("audience", sa.String(length=10), nullable=True),
        sa.Column("isp_focus", sa.String(length=10), nullable=False, server_default=sa.text("'secondary'")),
        sa.Column("reseller_focus", sa.String(length=10), nullable=False, server_default=sa.text("'muted'")),
        sa.Column("annotation_summary", sa.Text(), nullable=False),
        sa.Column("annotation_description", sa.Text(), nullable=False),
        sa.Column("enabled", sa.Boolean(), nullable=False, server_default=sa.text("true")),
        sa.Column("internal", sa.Boolean(), nullable=False, server_default=sa.text("false")),
        sa.Column("legacy_id", sa.BigInteger(), nullable=True, unique=True),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.Column("updated_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.CheckConstraint("severity in ('info','warning','critical')", name="ck_alarm_rules_severity"),
        sa.CheckConstraint("audience is null or audience in ('isp','reseller','both','operator')", name="ck_alarm_rules_audience"),
        sa.CheckConstraint("isp_focus in ('primary','secondary','muted')", name="ck_alarm_rules_isp_focus"),
        sa.CheckConstraint("reseller_focus in ('primary','secondary','muted')", name="ck_alarm_rules_reseller_focus"),
        sa.CheckConstraint("length(expression) > 0", name="ck_alarm_rules_expression_not_empty"),
    )
    op.create_index("ix_alarm_rules_group_name", "alarm_rules", ["group_name"])

    op.create_table(
        "events",
        sa.Column("id", postgresql.UUID(as_uuid=True), server_default=sa.text("gen_random_uuid()"), nullable=False),
        sa.Column("occurred_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.Column("name", sa.String(length=100), nullable=False),
        sa.Column("dedup_key", sa.String(length=200), nullable=True),
        sa.Column("labels", postgresql.JSONB(astext_type=sa.Text()), nullable=False, server_default=sa.text("'{}'::jsonb")),
        sa.Column("description", sa.Text(), nullable=True),
        sa.Column("severity", sa.String(length=10), nullable=False, server_default=sa.text("'warning'")),
        sa.Column("device_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("devices.id", ondelete="SET NULL"), nullable=True),
        sa.Column("resolved_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("resolved_by_user_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("users.id", ondelete="SET NULL"), nullable=True),
        sa.Column("is_autoresolved", sa.Boolean(), nullable=True),
        sa.Column("legacy_id", sa.BigInteger(), nullable=True),
        sa.PrimaryKeyConstraint("id", "occurred_at"),
        sa.CheckConstraint("severity in ('info','warning','critical')", name="ck_events_severity"),
    )
    op.create_index("ix_events_name_dedup_open", "events", ["name", "dedup_key"], postgresql_where=sa.text("resolved_at is null"))
    op.create_index("ix_events_device_id", "events", ["device_id"])
    op.create_index("ix_events_resolved_at", "events", ["resolved_at"])
    # A hypertable's unique indexes must include the partitioning column. legacy_id has no import path yet (Plan 32);
    # (legacy_id, occurred_at) is enough to prevent an accidental double-import once one exists.
    op.create_index("ux_events_legacy_id", "events", ["legacy_id", "occurred_at"], unique=True, postgresql_where=sa.text("legacy_id is not null"))
    op.execute("select create_hypertable('events', 'occurred_at', chunk_time_interval => interval '7 days')")
    op.execute("alter table events set (timescaledb.compress, timescaledb.compress_segmentby = 'name', timescaledb.compress_orderby = 'occurred_at desc')")
    op.execute("select add_compression_policy('events', interval '14 days')")
    op.execute("select add_retention_policy('events', interval '2 years')")


def downgrade() -> None:
    # events was created (and made a hypertable) by this same migration's upgrade(), so downgrading it just drops it:
    # dropping a hypertable cascades to its chunks and to the compression/retention policies attached to it.
    op.execute("drop table events")

    op.drop_index("ix_alarm_rules_group_name", table_name="alarm_rules")
    op.drop_table("alarm_rules")
