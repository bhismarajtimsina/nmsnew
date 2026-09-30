"""Credentials: passwords, 2FA, IP restrictions, login attempts, API tokens.

Revision ID: 20260928_0003
Revises: 20260928_0002
Create Date: 2026-09-28
"""

from __future__ import annotations

from alembic import op
import sqlalchemy as sa
from sqlalchemy.dialects import postgresql

revision = "20260928_0003"
down_revision = "20260928_0002"
branch_labels = None
depends_on = None


def upgrade() -> None:
    # users: real password credentials replace the static auth key
    op.add_column("users", sa.Column("password_hash", sa.Text(), nullable=True))
    op.add_column("users", sa.Column("hash_scheme", sa.String(length=20), nullable=True))
    op.add_column("users", sa.Column("password_changed_at", sa.DateTime(timezone=True), nullable=True))
    op.add_column("users", sa.Column("must_change_password", sa.Boolean(), nullable=False, server_default=sa.text("false")))
    op.add_column("users", sa.Column("totp_secret_enc", sa.Text(), nullable=True))
    op.add_column("users", sa.Column("totp_enabled", sa.Boolean(), nullable=False, server_default=sa.text("false")))
    op.add_column("users", sa.Column("totp_last_counter", sa.BigInteger(), nullable=True))
    op.add_column("users", sa.Column("strict_ip_enabled", sa.Boolean(), nullable=False, server_default=sa.text("false")))
    op.add_column("users", sa.Column("allowed_ips", postgresql.ARRAY(postgresql.INET()), nullable=True))
    op.add_column("users", sa.Column("legacy_id", sa.BigInteger(), nullable=True))
    op.create_unique_constraint("uq_users_legacy_id", "users", ["legacy_id"])
    op.drop_column("users", "auth_key_hash")

    # sessions: the credential is a token, stored only as a hash, and it must be unique
    op.drop_index("ix_user_sessions_auth_key_hash", table_name="user_sessions")
    op.alter_column("user_sessions", "auth_key_hash", new_column_name="token_hash")
    op.create_index("ux_user_sessions_token_hash", "user_sessions", ["token_hash"], unique=True)
    op.add_column("user_sessions", sa.Column("last_activity_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")))
    op.add_column("user_sessions", sa.Column("mfa_verified", sa.Boolean(), nullable=False, server_default=sa.text("false")))
    op.create_index("ix_user_sessions_expires_at", "user_sessions", ["expires_at"])

    op.create_table(
        "login_attempts",
        sa.Column("id", postgresql.UUID(as_uuid=True), server_default=sa.text("gen_random_uuid()"), primary_key=True),
        sa.Column("occurred_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.Column("username", sa.String(length=160), nullable=False),
        sa.Column("ip_address", postgresql.INET(), nullable=True),
        sa.Column("success", sa.Boolean(), nullable=False),
        sa.Column("reason", sa.String(length=60), nullable=True),
    )
    op.create_index("ix_login_attempts_occurred_at", "login_attempts", ["occurred_at"])
    op.create_index("ix_login_attempts_username", "login_attempts", ["username"])

    op.create_table(
        "api_tokens",
        sa.Column("id", postgresql.UUID(as_uuid=True), server_default=sa.text("gen_random_uuid()"), primary_key=True),
        sa.Column("user_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("users.id", ondelete="CASCADE"), nullable=False),
        sa.Column("name", sa.String(length=120), nullable=False),
        sa.Column("token_hash", sa.String(length=128), nullable=False),
        sa.Column("permissions", postgresql.ARRAY(sa.Text()), nullable=False),
        sa.Column("expires_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("last_used_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("last_used_ip", postgresql.INET(), nullable=True),
        sa.Column("revoked_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.UniqueConstraint("user_id", "name", name="uq_api_tokens_user_name"),
        sa.CheckConstraint("cardinality(permissions) > 0", name="ck_api_tokens_permissions_not_empty"),
    )
    op.create_index("ux_api_tokens_token_hash", "api_tokens", ["token_hash"], unique=True)
    op.create_index("ix_api_tokens_user_id", "api_tokens", ["user_id"])

    op.create_table(
        "totp_recovery_codes",
        sa.Column("id", postgresql.UUID(as_uuid=True), server_default=sa.text("gen_random_uuid()"), primary_key=True),
        sa.Column("user_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("users.id", ondelete="CASCADE"), nullable=False),
        sa.Column("code_hash", sa.String(length=128), nullable=False),
        sa.Column("used_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
    )
    op.create_index("ix_totp_recovery_codes_user_id", "totp_recovery_codes", ["user_id"])


def downgrade() -> None:
    op.drop_index("ix_totp_recovery_codes_user_id", table_name="totp_recovery_codes")
    op.drop_table("totp_recovery_codes")
    op.drop_index("ix_api_tokens_user_id", table_name="api_tokens")
    op.drop_index("ux_api_tokens_token_hash", table_name="api_tokens")
    op.drop_table("api_tokens")
    op.drop_index("ix_login_attempts_username", table_name="login_attempts")
    op.drop_index("ix_login_attempts_occurred_at", table_name="login_attempts")
    op.drop_table("login_attempts")

    op.drop_index("ix_user_sessions_expires_at", table_name="user_sessions")
    op.drop_column("user_sessions", "mfa_verified")
    op.drop_column("user_sessions", "last_activity_at")
    op.drop_index("ux_user_sessions_token_hash", table_name="user_sessions")
    op.alter_column("user_sessions", "token_hash", new_column_name="auth_key_hash")
    op.create_index("ix_user_sessions_auth_key_hash", "user_sessions", ["auth_key_hash"])

    op.add_column("users", sa.Column("auth_key_hash", sa.String(length=128), nullable=True))
    op.drop_constraint("uq_users_legacy_id", "users", type_="unique")
    for column in (
        "legacy_id", "allowed_ips", "strict_ip_enabled", "totp_last_counter", "totp_enabled", "totp_secret_enc",
        "must_change_password", "password_changed_at", "hash_scheme", "password_hash",
    ):
        op.drop_column("users", column)
