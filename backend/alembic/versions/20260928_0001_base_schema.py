"""Create CyberSathy-NMS base schema.

Revision ID: 20260928_0001
Revises:
Create Date: 2026-09-28
"""

from __future__ import annotations

from alembic import op
import sqlalchemy as sa
from sqlalchemy.dialects import postgresql

revision = "20260928_0001"
down_revision = None
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.execute('CREATE EXTENSION IF NOT EXISTS "pgcrypto"')
    op.execute('CREATE EXTENSION IF NOT EXISTS "timescaledb"')

    op.create_table(
        "roles",
        sa.Column("id", postgresql.UUID(as_uuid=True), server_default=sa.text("gen_random_uuid()"), primary_key=True),
        sa.Column("name", sa.String(length=80), nullable=False, unique=True),
        sa.Column("description", sa.Text(), nullable=True),
        sa.Column("is_system", sa.Boolean(), nullable=False, server_default=sa.text("false")),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.Column("updated_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
    )

    op.create_table(
        "permissions",
        sa.Column("id", postgresql.UUID(as_uuid=True), server_default=sa.text("gen_random_uuid()"), primary_key=True),
        sa.Column("code", sa.String(length=120), nullable=False, unique=True),
        sa.Column("group_name", sa.String(length=80), nullable=False),
        sa.Column("description", sa.Text(), nullable=True),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
    )
    op.create_index("ix_permissions_group_name", "permissions", ["group_name"])

    op.create_table(
        "role_permissions",
        sa.Column("role_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("roles.id", ondelete="CASCADE"), primary_key=True),
        sa.Column("permission_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("permissions.id", ondelete="CASCADE"), primary_key=True),
    )

    op.create_table(
        "users",
        sa.Column("id", postgresql.UUID(as_uuid=True), server_default=sa.text("gen_random_uuid()"), primary_key=True),
        sa.Column("role_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("roles.id", ondelete="RESTRICT"), nullable=False),
        sa.Column("username", sa.String(length=80), nullable=False, unique=True),
        sa.Column("email", sa.String(length=255), nullable=True, unique=True),
        sa.Column("display_name", sa.String(length=160), nullable=False),
        sa.Column("auth_key_hash", sa.String(length=128), nullable=True),
        sa.Column("is_active", sa.Boolean(), nullable=False, server_default=sa.text("true")),
        sa.Column("last_login_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.Column("updated_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
    )
    op.create_index("ix_users_role_id", "users", ["role_id"])

    op.create_table(
        "user_sessions",
        sa.Column("id", postgresql.UUID(as_uuid=True), server_default=sa.text("gen_random_uuid()"), primary_key=True),
        sa.Column("user_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("users.id", ondelete="CASCADE"), nullable=False),
        sa.Column("auth_key_hash", sa.String(length=128), nullable=False),
        sa.Column("ip_address", postgresql.INET(), nullable=True),
        sa.Column("user_agent", sa.Text(), nullable=True),
        sa.Column("expires_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column("revoked_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
    )
    op.create_index("ix_user_sessions_user_id", "user_sessions", ["user_id"])
    op.create_index("ix_user_sessions_auth_key_hash", "user_sessions", ["auth_key_hash"])

    op.create_table(
        "system_settings",
        sa.Column("key", sa.String(length=120), primary_key=True),
        sa.Column("value", postgresql.JSONB(astext_type=sa.Text()), nullable=False, server_default=sa.text("'{}'::jsonb")),
        sa.Column("description", sa.Text(), nullable=True),
        sa.Column("updated_by_user_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("users.id", ondelete="SET NULL"), nullable=True),
        sa.Column("updated_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
    )

    op.create_table(
        "device_groups",
        sa.Column("id", postgresql.UUID(as_uuid=True), server_default=sa.text("gen_random_uuid()"), primary_key=True),
        sa.Column("parent_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("device_groups.id", ondelete="SET NULL"), nullable=True),
        sa.Column("name", sa.String(length=120), nullable=False),
        sa.Column("description", sa.Text(), nullable=True),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.UniqueConstraint("parent_id", "name", name="uq_device_groups_parent_name"),
    )

    op.create_table(
        "device_models",
        sa.Column("id", postgresql.UUID(as_uuid=True), server_default=sa.text("gen_random_uuid()"), primary_key=True),
        sa.Column("vendor", sa.String(length=80), nullable=False),
        sa.Column("model_name", sa.String(length=120), nullable=False),
        sa.Column("device_type", sa.String(length=40), nullable=False),
        sa.Column("sysobjectid_matcher", sa.String(length=255), nullable=True),
        sa.Column("default_polling_profile", sa.String(length=120), nullable=True),
        sa.Column("supported_modules", postgresql.JSONB(astext_type=sa.Text()), nullable=False, server_default=sa.text("'[]'::jsonb")),
        sa.Column("icon", sa.String(length=120), nullable=True),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.UniqueConstraint("vendor", "model_name", name="uq_device_models_vendor_model"),
    )
    op.create_index("ix_device_models_device_type", "device_models", ["device_type"])

    op.create_table(
        "device_access_profiles",
        sa.Column("id", postgresql.UUID(as_uuid=True), server_default=sa.text("gen_random_uuid()"), primary_key=True),
        sa.Column("name", sa.String(length=120), nullable=False, unique=True),
        sa.Column("snmp_version", sa.String(length=20), nullable=False),
        sa.Column("snmp_community_enc", sa.Text(), nullable=True),
        sa.Column("snmp_v3_username", sa.String(length=120), nullable=True),
        sa.Column("snmp_v3_auth_protocol", sa.String(length=40), nullable=True),
        sa.Column("snmp_v3_auth_secret_enc", sa.Text(), nullable=True),
        sa.Column("snmp_v3_priv_protocol", sa.String(length=40), nullable=True),
        sa.Column("snmp_v3_priv_secret_enc", sa.Text(), nullable=True),
        sa.Column("timeout_ms", sa.Integer(), nullable=False, server_default=sa.text("2000")),
        sa.Column("retries", sa.Integer(), nullable=False, server_default=sa.text("1")),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.Column("updated_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
    )

    op.create_table(
        "devices",
        sa.Column("id", postgresql.UUID(as_uuid=True), server_default=sa.text("gen_random_uuid()"), primary_key=True),
        sa.Column("group_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("device_groups.id", ondelete="SET NULL"), nullable=True),
        sa.Column("model_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("device_models.id", ondelete="SET NULL"), nullable=True),
        sa.Column("access_profile_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("device_access_profiles.id", ondelete="SET NULL"), nullable=True),
        sa.Column("name", sa.String(length=160), nullable=False),
        sa.Column("hostname", sa.String(length=255), nullable=True),
        sa.Column("management_ip", postgresql.INET(), nullable=False),
        sa.Column("device_type", sa.String(length=40), nullable=False),
        sa.Column("vendor", sa.String(length=80), nullable=True),
        sa.Column("sys_name", sa.String(length=255), nullable=True),
        sa.Column("sys_descr", sa.Text(), nullable=True),
        sa.Column("sys_object_id", sa.String(length=255), nullable=True),
        sa.Column("serial_number", sa.String(length=120), nullable=True),
        sa.Column("status", sa.String(length=40), nullable=False, server_default=sa.text("'unknown'")),
        sa.Column("polling_enabled", sa.Boolean(), nullable=False, server_default=sa.text("true")),
        sa.Column("metadata", postgresql.JSONB(astext_type=sa.Text()), nullable=False, server_default=sa.text("'{}'::jsonb")),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.Column("updated_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.UniqueConstraint("management_ip", name="uq_devices_management_ip"),
    )
    op.create_index("ix_devices_device_type", "devices", ["device_type"])
    op.create_index("ix_devices_status", "devices", ["status"])
    op.create_index("ix_devices_vendor", "devices", ["vendor"])

    op.create_table(
        "interfaces",
        sa.Column("id", postgresql.UUID(as_uuid=True), server_default=sa.text("gen_random_uuid()"), primary_key=True),
        sa.Column("device_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("devices.id", ondelete="CASCADE"), nullable=False),
        sa.Column("parent_interface_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("interfaces.id", ondelete="SET NULL"), nullable=True),
        sa.Column("if_index", sa.Integer(), nullable=False),
        sa.Column("name", sa.String(length=160), nullable=False),
        sa.Column("alias", sa.String(length=255), nullable=True),
        sa.Column("if_type", sa.String(length=80), nullable=True),
        sa.Column("admin_status", sa.String(length=40), nullable=False, server_default=sa.text("'unknown'")),
        sa.Column("oper_status", sa.String(length=40), nullable=False, server_default=sa.text("'unknown'")),
        sa.Column("speed_bps", sa.BigInteger(), nullable=True),
        sa.Column("mac_address", sa.String(length=32), nullable=True),
        sa.Column("metadata", postgresql.JSONB(astext_type=sa.Text()), nullable=False, server_default=sa.text("'{}'::jsonb")),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.Column("updated_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.UniqueConstraint("device_id", "if_index", name="uq_interfaces_device_if_index"),
    )
    op.create_index("ix_interfaces_device_id", "interfaces", ["device_id"])
    op.create_index("ix_interfaces_oper_status", "interfaces", ["oper_status"])

    op.create_table(
        "audit_logs",
        sa.Column("id", postgresql.UUID(as_uuid=True), server_default=sa.text("gen_random_uuid()"), primary_key=True),
        sa.Column("occurred_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.Column("actor_user_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("users.id", ondelete="SET NULL"), nullable=True),
        sa.Column("action", sa.String(length=120), nullable=False),
        sa.Column("resource_type", sa.String(length=80), nullable=True),
        sa.Column("resource_id", postgresql.UUID(as_uuid=True), nullable=True),
        sa.Column("ip_address", postgresql.INET(), nullable=True),
        sa.Column("user_agent", sa.Text(), nullable=True),
        sa.Column("before", postgresql.JSONB(astext_type=sa.Text()), nullable=True),
        sa.Column("after", postgresql.JSONB(astext_type=sa.Text()), nullable=True),
        sa.Column("metadata", postgresql.JSONB(astext_type=sa.Text()), nullable=False, server_default=sa.text("'{}'::jsonb")),
    )
    op.create_index("ix_audit_logs_occurred_at", "audit_logs", ["occurred_at"])
    op.create_index("ix_audit_logs_actor_user_id", "audit_logs", ["actor_user_id"])
    op.create_index("ix_audit_logs_action", "audit_logs", ["action"])


def downgrade() -> None:
    op.drop_index("ix_audit_logs_action", table_name="audit_logs")
    op.drop_index("ix_audit_logs_actor_user_id", table_name="audit_logs")
    op.drop_index("ix_audit_logs_occurred_at", table_name="audit_logs")
    op.drop_table("audit_logs")
    op.drop_index("ix_interfaces_oper_status", table_name="interfaces")
    op.drop_index("ix_interfaces_device_id", table_name="interfaces")
    op.drop_table("interfaces")
    op.drop_index("ix_devices_vendor", table_name="devices")
    op.drop_index("ix_devices_status", table_name="devices")
    op.drop_index("ix_devices_device_type", table_name="devices")
    op.drop_table("devices")
    op.drop_table("device_access_profiles")
    op.drop_index("ix_device_models_device_type", table_name="device_models")
    op.drop_table("device_models")
    op.drop_table("device_groups")
    op.drop_table("system_settings")
    op.drop_index("ix_user_sessions_auth_key_hash", table_name="user_sessions")
    op.drop_index("ix_user_sessions_user_id", table_name="user_sessions")
    op.drop_table("user_sessions")
    op.drop_index("ix_users_role_id", table_name="users")
    op.drop_table("users")
    op.drop_table("role_permissions")
    op.drop_index("ix_permissions_group_name", table_name="permissions")
    op.drop_table("permissions")
    op.drop_table("roles")
    # The extensions are shared database infrastructure. A downgrade never removes them.
