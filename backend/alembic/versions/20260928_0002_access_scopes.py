"""Add CyberSathy-NMS role scope tables.

Revision ID: 20260928_0002
Revises: 20260928_0001
Create Date: 2026-09-28
"""

from __future__ import annotations

from alembic import op
import sqlalchemy as sa
from sqlalchemy.dialects import postgresql

revision = "20260928_0002"
down_revision = "20260928_0001"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.create_table(
        "user_device_group_scopes",
        sa.Column("user_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("users.id", ondelete="CASCADE"), primary_key=True),
        sa.Column(
            "device_group_id",
            postgresql.UUID(as_uuid=True),
            sa.ForeignKey("device_groups.id", ondelete="CASCADE"),
            primary_key=True,
        ),
        sa.Column("scope_level", sa.String(length=40), nullable=False, server_default=sa.text("'viewer'")),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
    )
    op.create_index("ix_user_device_group_scopes_user_id", "user_device_group_scopes", ["user_id"])
    op.create_index(
        "ix_user_device_group_scopes_device_group_id",
        "user_device_group_scopes",
        ["device_group_id"],
    )

    op.create_table(
        "user_device_scopes",
        sa.Column("user_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("users.id", ondelete="CASCADE"), primary_key=True),
        sa.Column("device_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("devices.id", ondelete="CASCADE"), primary_key=True),
        sa.Column("scope_level", sa.String(length=40), nullable=False, server_default=sa.text("'viewer'")),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
    )
    op.create_index("ix_user_device_scopes_user_id", "user_device_scopes", ["user_id"])
    op.create_index("ix_user_device_scopes_device_id", "user_device_scopes", ["device_id"])

    op.create_table(
        "user_interface_scopes",
        sa.Column("user_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("users.id", ondelete="CASCADE"), primary_key=True),
        sa.Column(
            "interface_id",
            postgresql.UUID(as_uuid=True),
            sa.ForeignKey("interfaces.id", ondelete="CASCADE"),
            primary_key=True,
        ),
        sa.Column("scope_level", sa.String(length=40), nullable=False, server_default=sa.text("'viewer'")),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
    )
    op.create_index("ix_user_interface_scopes_user_id", "user_interface_scopes", ["user_id"])
    op.create_index("ix_user_interface_scopes_interface_id", "user_interface_scopes", ["interface_id"])


def downgrade() -> None:
    op.drop_index("ix_user_interface_scopes_interface_id", table_name="user_interface_scopes")
    op.drop_index("ix_user_interface_scopes_user_id", table_name="user_interface_scopes")
    op.drop_table("user_interface_scopes")
    op.drop_index("ix_user_device_scopes_device_id", table_name="user_device_scopes")
    op.drop_index("ix_user_device_scopes_user_id", table_name="user_device_scopes")
    op.drop_table("user_device_scopes")
    op.drop_index("ix_user_device_group_scopes_device_group_id", table_name="user_device_group_scopes")
    op.drop_index("ix_user_device_group_scopes_user_id", table_name="user_device_group_scopes")
    op.drop_table("user_device_group_scopes")
