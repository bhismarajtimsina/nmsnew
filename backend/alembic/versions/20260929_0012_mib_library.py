"""MIB library: a read-only, searchable index of MIB object names and their resolved numeric OIDs.

Revision ID: 20260929_0012
Revises: 20260929_0011
Create Date: 2026-09-29

Documentation and validation only (see docs/cybersathy-nms-migration/07-mib-library.md): nothing here is read at
polling time. `numeric_oid` is stored without a leading dot, matching this system's own convention everywhere else
(oid_definitions.numeric_oid), so a name can be cross-referenced against a declared OID with a plain string compare.

A name is not unique even within one file: several real MIBs in this repository genuinely declare the same identifier
twice with two different OIDs (for example `sysORID` in SNMPv2-MIB.my, once as a plain OBJECT IDENTIFIER stub and once
as the real OBJECT-TYPE with a different sub-id). That is real information about the source file, not a parsing bug,
so every occurrence is kept as its own row rather than collapsed to one.
"""

from __future__ import annotations

from alembic import op
import sqlalchemy as sa
from sqlalchemy.dialects import postgresql

revision = "20260929_0012"
down_revision = "20260929_0011"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.create_table(
        "mib_files",
        sa.Column("id", postgresql.UUID(as_uuid=True), server_default=sa.text("gen_random_uuid()"), primary_key=True),
        sa.Column("filename", sa.String(length=255), nullable=False, unique=True),
        sa.Column("vendor_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("vendors.id", ondelete="SET NULL"), nullable=True),
        sa.Column("source_path", sa.Text(), nullable=False),
        sa.Column("object_count", sa.Integer(), nullable=False, server_default=sa.text("0")),
        sa.Column("resolved_count", sa.Integer(), nullable=False, server_default=sa.text("0")),
        sa.Column("imported_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
    )
    op.create_index("ix_mib_files_vendor_id", "mib_files", ["vendor_id"])

    op.create_table(
        "mib_objects",
        sa.Column("id", postgresql.UUID(as_uuid=True), server_default=sa.text("gen_random_uuid()"), primary_key=True),
        sa.Column("file_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("mib_files.id", ondelete="CASCADE"), nullable=False),
        sa.Column("name", sa.String(length=160), nullable=False),
        sa.Column("kind", sa.String(length=30), nullable=False),
        sa.Column("parent_name", sa.String(length=160), nullable=True),
        sa.Column("sub_id", sa.Integer(), nullable=True),
        sa.Column("numeric_oid", sa.String(length=255), nullable=True),
        sa.CheckConstraint(r"numeric_oid is null or numeric_oid ~ '^[0-9]+(\.[0-9]+)+$'", name="ck_mib_objects_numeric_oid"),
    )
    op.create_index("ix_mib_objects_file_name", "mib_objects", ["file_id", "name"])
    op.create_index("ix_mib_objects_name", "mib_objects", ["name"])
    op.create_index("ix_mib_objects_numeric_oid", "mib_objects", ["numeric_oid"])


def downgrade() -> None:
    op.drop_index("ix_mib_objects_numeric_oid", table_name="mib_objects")
    op.drop_index("ix_mib_objects_name", table_name="mib_objects")
    op.drop_index("ix_mib_objects_file_name", table_name="mib_objects")
    op.drop_table("mib_objects")
    op.drop_index("ix_mib_files_vendor_id", table_name="mib_files")
    op.drop_table("mib_files")
