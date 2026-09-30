"""Access control: role scope mode, dangerous flag, resellers, legacy ids, constraints, network types.

Revision ID: 20260928_0004
Revises: 20260928_0003
Create Date: 2026-09-28
"""

from __future__ import annotations

from alembic import op
import sqlalchemy as sa
from sqlalchemy.dialects import postgresql

revision = "20260928_0004"
down_revision = "20260928_0003"
branch_labels = None
depends_on = None

LEGACY_ID_TABLES = ("device_groups", "device_models", "device_access_profiles", "devices", "interfaces")
SCOPE_TABLES = ("user_device_group_scopes", "user_device_scopes", "user_interface_scopes")
INTERFACE_STATES = "('up','down','testing','unknown','dormant','notPresent','lowerLayerDown')"


def upgrade() -> None:
    # A role either sees everything ("all") or only what is assigned to the user ("assigned"). Restricted by default.
    op.add_column("roles", sa.Column("scope_mode", sa.String(length=20), nullable=False, server_default=sa.text("'assigned'")))
    op.create_check_constraint("ck_roles_scope_mode", "roles", "scope_mode in ('all','assigned')")
    op.add_column("permissions", sa.Column("is_dangerous", sa.Boolean(), nullable=False, server_default=sa.text("false")))

    op.create_table(
        "resellers",
        sa.Column("id", postgresql.UUID(as_uuid=True), server_default=sa.text("gen_random_uuid()"), primary_key=True),
        sa.Column("name", sa.String(length=160), nullable=False, unique=True),
        sa.Column("description", sa.Text(), nullable=True),
        sa.Column("is_active", sa.Boolean(), nullable=False, server_default=sa.text("true")),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
    )
    op.create_table(
        "reseller_users",
        sa.Column("user_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("users.id", ondelete="CASCADE"), primary_key=True),
        sa.Column("reseller_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("resellers.id", ondelete="CASCADE"), nullable=False),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
    )
    op.create_index("ix_reseller_users_reseller_id", "reseller_users", ["reseller_id"])

    for table in SCOPE_TABLES:
        op.create_check_constraint(f"ck_{table}_level", table, "scope_level in ('viewer','operator','admin')")

    for table in LEGACY_ID_TABLES:
        op.add_column(table, sa.Column("legacy_id", sa.BigInteger(), nullable=True))
        op.create_unique_constraint(f"uq_{table}_legacy_id", table, ["legacy_id"])

    # Migration safety: a migrated device belongs to the legacy poller until an operator hands it over.
    op.add_column("devices", sa.Column("polling_owner", sa.String(length=20), nullable=False, server_default=sa.text("'legacy'")))
    op.create_check_constraint("ck_devices_polling_owner", "devices", "polling_owner in ('legacy','cybersathy')")
    op.create_check_constraint("ck_devices_status", "devices", "status in ('unknown','up','down','maintenance')")
    op.create_check_constraint("ck_interfaces_admin_status", "interfaces", f"admin_status in {INTERFACE_STATES}")
    op.create_check_constraint("ck_interfaces_oper_status", "interfaces", f"oper_status in {INTERFACE_STATES}")

    op.execute("alter table interfaces alter column mac_address type macaddr using nullif(mac_address, '')::macaddr")


def downgrade() -> None:
    op.execute("alter table interfaces alter column mac_address type varchar(32) using mac_address::text")
    op.drop_constraint("ck_interfaces_oper_status", "interfaces", type_="check")
    op.drop_constraint("ck_interfaces_admin_status", "interfaces", type_="check")
    op.drop_constraint("ck_devices_status", "devices", type_="check")
    op.drop_constraint("ck_devices_polling_owner", "devices", type_="check")
    op.drop_column("devices", "polling_owner")
    for table in LEGACY_ID_TABLES:
        op.drop_constraint(f"uq_{table}_legacy_id", table, type_="unique")
        op.drop_column(table, "legacy_id")
    for table in SCOPE_TABLES:
        op.drop_constraint(f"ck_{table}_level", table, type_="check")
    op.drop_index("ix_reseller_users_reseller_id", table_name="reseller_users")
    op.drop_table("reseller_users")
    op.drop_table("resellers")
    op.drop_column("permissions", "is_dangerous")
    op.drop_constraint("ck_roles_scope_mode", "roles", type_="check")
    op.drop_column("roles", "scope_mode")
