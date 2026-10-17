"""Compression for interface counter samples (Plan 40: every hypertable has retention and compression).

Revision ID: 20261017_0032
Revises: 20261010_0031
Create Date: 2026-10-17

Chunks older than a day are compressed, segmented by interface. Utilisation reads at most the last six hours
(legacy's longest period), so the rows it reads are never in a compressed chunk, and samples are only ever written for
the current time.
"""
from __future__ import annotations

from alembic import op

revision = "20261017_0032"
down_revision = "20261010_0031"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.execute(
        "alter table interface_counter_samples set (timescaledb.compress, "
        "timescaledb.compress_segmentby = 'interface_id', timescaledb.compress_orderby = 'sampled_at desc')"
    )
    op.execute("select add_compression_policy('interface_counter_samples', interval '1 day')")


def downgrade() -> None:
    op.execute("select remove_compression_policy('interface_counter_samples', if_exists => true)")
    op.execute("select decompress_chunk(c, true) from show_chunks('interface_counter_samples') c")
    op.execute("alter table interface_counter_samples set (timescaledb.compress = false)")
