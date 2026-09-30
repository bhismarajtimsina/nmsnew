"""Turn audit_logs into a TimescaleDB hypertable with compression and retention.

Revision ID: 20260928_0005
Revises: 20260928_0004
Create Date: 2026-09-28

A hypertable's unique constraints must include the partition column, so the primary key becomes (id, occurred_at).
Retention and compression follow docs/cybersathy-nms-migration/data-model.md.
"""

from __future__ import annotations

from alembic import op

revision = "20260928_0005"
down_revision = "20260928_0004"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.execute("alter table audit_logs drop constraint audit_logs_pkey")
    op.execute("alter table audit_logs add primary key (id, occurred_at)")
    op.execute(
        "select create_hypertable('audit_logs', 'occurred_at', chunk_time_interval => interval '30 days', migrate_data => true)"
    )
    op.execute("alter table audit_logs set (timescaledb.compress, timescaledb.compress_orderby = 'occurred_at desc')")
    op.execute("select add_compression_policy('audit_logs', interval '30 days')")
    op.execute("select add_retention_policy('audit_logs', interval '2 years')")


def downgrade() -> None:
    op.execute("select remove_retention_policy('audit_logs', if_exists => true)")
    op.execute("select remove_compression_policy('audit_logs', if_exists => true)")
    # A hypertable cannot be converted back in place: copy into a plain table, then swap.
    op.execute(
        """
        select decompress_chunk(c, true) from show_chunks('audit_logs') c
        """
    )
    op.execute("create table audit_logs_plain (like audit_logs including defaults)")
    op.execute("insert into audit_logs_plain select * from audit_logs")
    op.execute("drop table audit_logs")
    op.execute("alter table audit_logs_plain rename to audit_logs")
    op.execute("alter table audit_logs add primary key (id)")
    op.execute("alter table audit_logs add foreign key (actor_user_id) references users(id) on delete set null")
    op.execute("create index ix_audit_logs_occurred_at on audit_logs (occurred_at)")
    op.execute("create index ix_audit_logs_actor_user_id on audit_logs (actor_user_id)")
    op.execute("create index ix_audit_logs_action on audit_logs (action)")
