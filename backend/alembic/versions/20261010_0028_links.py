"""Topology links (Plan 27).

Revision ID: 20261010_0028
Revises: 20261010_0027
Create Date: 2026-10-10

Legacy `c_links`: a source device and interface, a destination device and interface, how the link was learned
(manual, fdb, lldp) and free parameters. Here the same, with UUIDs, a creator, and two rules legacy does not enforce:
a link never joins an interface to itself, and the same pair of ends is stored once whichever way round it was entered.
`link_external_names` keeps legacy `c_links_external_names`: display names for LLDP neighbours that are not devices in
the inventory, keyed `ext:<local device id>:<chassis MAC>`.
"""
from __future__ import annotations

from alembic import op
import sqlalchemy as sa
from sqlalchemy.dialects import postgresql

revision = "20261010_0028"
down_revision = "20261010_0027"
branch_labels = None
depends_on = None

NIL = "'00000000-0000-0000-0000-000000000000'::uuid"


def upgrade() -> None:
    op.create_table(
        "links",
        sa.Column("id", postgresql.UUID(as_uuid=True), server_default=sa.text("gen_random_uuid()"), primary_key=True),
        sa.Column("src_device_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("devices.id", ondelete="CASCADE"), nullable=False),
        sa.Column("src_interface_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("interfaces.id", ondelete="SET NULL"), nullable=True),
        sa.Column("dest_device_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("devices.id", ondelete="CASCADE"), nullable=False),
        sa.Column("dest_interface_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("interfaces.id", ondelete="SET NULL"), nullable=True),
        sa.Column("source", sa.String(8), nullable=False, server_default="manual"),
        sa.Column("description", sa.String(255), nullable=True),
        sa.Column("created_by", postgresql.UUID(as_uuid=True), sa.ForeignKey("users.id", ondelete="SET NULL"), nullable=True),
        sa.Column("legacy_id", sa.Integer(), nullable=True, unique=True),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.Column("updated_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.CheckConstraint("source in ('manual', 'fdb', 'lldp')", name="ck_links_source"),
        sa.CheckConstraint("src_interface_id is null or src_interface_id is distinct from dest_interface_id", name="ck_links_not_self"),
    )
    # One row per pair of ends, whichever way round: the ends are ordered before comparing.
    op.execute(f"""
        create unique index uq_links_ends on links (
            least(src_device_id::text || '/' || coalesce(src_interface_id, {NIL})::text,
                  dest_device_id::text || '/' || coalesce(dest_interface_id, {NIL})::text),
            greatest(src_device_id::text || '/' || coalesce(src_interface_id, {NIL})::text,
                     dest_device_id::text || '/' || coalesce(dest_interface_id, {NIL})::text))
    """)
    op.create_index("ix_links_src_device", "links", ["src_device_id"])
    op.create_index("ix_links_dest_device", "links", ["dest_device_id"])
    op.create_table(
        "link_external_names",
        sa.Column("id", sa.String(191), primary_key=True),
        sa.Column("name", sa.String(255), nullable=False),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.Column("updated_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.CheckConstraint(r"id ~ '^ext:[0-9a-f-]{36}:([0-9a-f]{2}:){5}[0-9a-f]{2}$'", name="ck_link_external_names_id"),
    )


def downgrade() -> None:
    op.drop_table("link_external_names")
    op.drop_table("links")
