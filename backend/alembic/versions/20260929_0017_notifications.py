"""Notifications: contacts, per-event config, and the delivery queue/log, ported from the real legacy schema
(`c_notifications_contacts`, `c_notifications_events_config`, `c_notifications_ignore_devices`, `c_notifications`;
`components/Notifications/migrations/`).

Revision ID: 20260929_0017
Revises: 20260929_0016
Create Date: 2026-09-29

Deliberately not ported: the `PHONE`/`PHONE_FOR_TELEGRAM` contact types (the real event-generation code
(`EventGenerator::getContacts`) explicitly skips both - they were never wired to a working channel) and the
`notification` contact/queue type (that is `ActionGenerator`'s audit-notification path - "device added", "user
logged in" - a distinct feature from event/alarm notifications and not part of this pass). `send_only_by_devices` on
a contact is kept as a column for schema fidelity but is not read by any real code path in the legacy system either
(confirmed by reading `EventGenerator`) - reserved, not wired up here either.

`notifications` is a TimescaleDB hypertable on `created_at` (D-01): the legacy `c_notifications` is purged by a real
cron job (`clear_old_notifications`, daily, 30 days), matched here exactly rather than guessed at - the same
approach already taken for `trap_history`.
"""

from __future__ import annotations

from alembic import op
import sqlalchemy as sa
from sqlalchemy.dialects import postgresql

revision = "20260929_0017"
down_revision = "20260929_0016"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.create_table(
        "notification_contacts",
        sa.Column("id", postgresql.UUID(as_uuid=True), server_default=sa.text("gen_random_uuid()"), primary_key=True),
        sa.Column("user_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("users.id", ondelete="CASCADE"), nullable=False),
        sa.Column("type", sa.String(length=20), nullable=False),
        sa.Column("value", sa.String(length=150), nullable=False),
        sa.Column("enabled", sa.Boolean(), nullable=False, server_default=sa.text("true")),
        sa.Column("description", sa.String(length=200), nullable=False, server_default=sa.text("''")),
        sa.Column("severities", postgresql.ARRAY(sa.String(length=10)), nullable=False,
                  server_default=sa.text("'{info,warning,critical}'")),
        sa.Column("ignore_event_names", postgresql.ARRAY(sa.String(length=100)), nullable=False, server_default=sa.text("'{}'")),
        sa.Column("send_only_by_devices", sa.Boolean(), nullable=False, server_default=sa.text("false")),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.Column("updated_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.CheckConstraint("type in ('email', 'telegram_id')", name="ck_notification_contacts_type"),
        sa.UniqueConstraint("user_id", "type", "value", name="uq_notification_contacts_user_type_value"),
    )

    op.create_table(
        "notification_event_config",
        sa.Column("id", postgresql.UUID(as_uuid=True), server_default=sa.text("gen_random_uuid()"), primary_key=True),
        sa.Column("event_name", sa.String(length=100), nullable=False, unique=True),
        sa.Column("enabled", sa.Boolean(), nullable=False, server_default=sa.text("true")),
        sa.Column("delay_before_send_seconds", sa.Integer(), nullable=False, server_default=sa.text("0")),
        sa.Column("send_resolved", sa.Boolean(), nullable=False, server_default=sa.text("true")),
        # Reserved, matching the legacy column exactly: no-op until Plan 27 (topology/links) exists. Legacy's own
        # check falls back to "always allowed" when its `links` component isn't enabled - the same fallback this
        # system has today, since it has no links component either.
        sa.Column("check_uplink", sa.Boolean(), nullable=False, server_default=sa.text("false")),
        sa.Column("legacy_id", sa.BigInteger(), nullable=True, unique=True),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.Column("updated_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.CheckConstraint("delay_before_send_seconds >= 0", name="ck_notification_event_config_delay"),
    )

    op.create_table(
        "notification_event_ignored_devices",
        sa.Column("config_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("notification_event_config.id", ondelete="CASCADE"), primary_key=True),
        sa.Column("device_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("devices.id", ondelete="CASCADE"), primary_key=True),
    )

    op.create_table(
        "notifications",
        sa.Column("id", postgresql.UUID(as_uuid=True), server_default=sa.text("gen_random_uuid()"), nullable=False),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.Column("send_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column("sent_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("type", sa.String(length=20), nullable=False),
        sa.Column("contact_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("notification_contacts.id", ondelete="CASCADE"), nullable=False),
        # No FK to events: legacy's own c_notifications.event_id has none either (events is a hypertable, partitioned
        # PK (id, occurred_at) - referencing plain `id` would need a redundant unique index the real schema never
        # had). A notification survives its event being deleted, matching legacy's own lack of a cascade here.
        sa.Column("event_id", postgresql.UUID(as_uuid=True), nullable=True),
        # No FK, for the same reason as event_id above: TimescaleDB refuses any unique constraint that excludes the
        # partitioning column, on a hypertable, at any time - not just at create_hypertable() (confirmed directly:
        # a unique index on `id` alone, added before the create_hypertable() call, made that call itself fail with
        # "cannot create a unique index without the column \"created_at\""). A self-reference to this same table's
        # `id` is therefore not something a hypertable can enforce at the database level.
        sa.Column("previous_notification_id", postgresql.UUID(as_uuid=True), nullable=True),
        sa.Column("status", sa.String(length=20), nullable=False, server_default=sa.text("'queued'")),
        sa.Column("meta", postgresql.JSONB(astext_type=sa.Text()), nullable=False, server_default=sa.text("'{}'::jsonb")),
        sa.PrimaryKeyConstraint("id", "created_at"),
        sa.CheckConstraint("type in ('alert', 'resolved')", name="ck_notifications_type"),
        sa.CheckConstraint("status in ('queued', 'in_process', 'sent', 'failed', 'canceled')", name="ck_notifications_status"),
    )
    op.create_index("ix_notifications_previous_notification_id", "notifications", ["previous_notification_id"])
    op.create_index("ix_notifications_send_at", "notifications", ["send_at"], postgresql_where=sa.text("status = 'queued'"))
    op.create_index("ix_notifications_contact_id", "notifications", ["contact_id", "created_at"])
    op.create_index("ix_notifications_event_id", "notifications", ["event_id"])
    op.execute("select create_hypertable('notifications', 'created_at', chunk_time_interval => interval '7 days')")
    op.execute(
        "alter table notifications set (timescaledb.compress, "
        "timescaledb.compress_segmentby = 'contact_id', timescaledb.compress_orderby = 'created_at desc')"
    )
    op.execute("select add_compression_policy('notifications', interval '7 days')")
    op.execute("select add_retention_policy('notifications', interval '30 days')")


def downgrade() -> None:
    # notifications was created (and made a hypertable) by this same migration's upgrade(), so downgrading it just
    # drops it: dropping a hypertable cascades to its chunks and to the compression/retention policies on it.
    op.execute("drop table notifications")
    op.drop_table("notification_event_ignored_devices")
    op.drop_table("notification_event_config")
    op.drop_table("notification_contacts")
