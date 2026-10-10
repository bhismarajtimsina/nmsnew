"""LLDP neighbours as last polled (Plan 27).

Revision ID: 20261010_0030
Revises: 20261010_0029
Create Date: 2026-10-10

One row per neighbour a device reported, replaced on each poll that returns LLDP data. The local LLDP port is matched
to an interface where the names agree; the remote end is matched to a device at read time, inside the reader's scope.
"""
from __future__ import annotations

from alembic import op
import sqlalchemy as sa
from sqlalchemy.dialects import postgresql

revision = "20261010_0030"
down_revision = "20261010_0029"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.create_table(
        "lldp_neighbours",
        sa.Column("device_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("devices.id", ondelete="CASCADE"), nullable=False),
        sa.Column("local_port_num", sa.Integer(), nullable=False),
        sa.Column("remote_index", sa.Integer(), nullable=False),
        sa.Column("local_interface_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("interfaces.id", ondelete="SET NULL"), nullable=True),
        sa.Column("local_port", sa.String(255), nullable=True),
        sa.Column("chassis_subtype", sa.String(20), nullable=True),
        sa.Column("chassis_id", sa.String(255), nullable=True),
        sa.Column("port_subtype", sa.String(20), nullable=True),
        sa.Column("port_id", sa.String(255), nullable=True),
        sa.Column("port_description", sa.String(255), nullable=True),
        sa.Column("system_name", sa.String(255), nullable=True),
        sa.Column("seen_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.PrimaryKeyConstraint("device_id", "local_port_num", "remote_index", name="pk_lldp_neighbours"),
    )
    op.create_index("ix_lldp_neighbours_chassis", "lldp_neighbours", ["chassis_id"])


def downgrade() -> None:
    op.drop_table("lldp_neighbours")
