"""CLI credentials on access profiles, for the console's automatic login (Plan 38).

Revision ID: 20261010_0027
Revises: 20261010_0026
Create Date: 2026-10-10

Legacy keeps a login and password per device access entry and its console logs in with them. Here they join the SNMP
secrets on the access profile: the password and the enable password are encrypted with the profile id and field name
as context and never returned; the username, protocol and port are plain settings.
"""
from __future__ import annotations

from alembic import op
import sqlalchemy as sa

revision = "20261010_0027"
down_revision = "20261010_0026"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.add_column("device_access_profiles", sa.Column("cli_protocol", sa.String(8), nullable=True))
    op.add_column("device_access_profiles", sa.Column("cli_port", sa.Integer(), nullable=True))
    op.add_column("device_access_profiles", sa.Column("cli_username", sa.String(120), nullable=True))
    op.add_column("device_access_profiles", sa.Column("cli_password_enc", sa.Text(), nullable=True))
    op.add_column("device_access_profiles", sa.Column("cli_enable_password_enc", sa.Text(), nullable=True))
    op.create_check_constraint("ck_access_profiles_cli_protocol", "device_access_profiles",
                               "cli_protocol is null or cli_protocol in ('ssh', 'telnet')")
    op.create_check_constraint("ck_access_profiles_cli_port", "device_access_profiles", "cli_port is null or cli_port between 1 and 65535")
    # A password without a user to log in as, or an enable password without a login, is meaningless.
    op.create_check_constraint("ck_access_profiles_cli_login", "device_access_profiles",
                               "(cli_password_enc is null or cli_username is not null) "
                               "and (cli_enable_password_enc is null or cli_password_enc is not null)")


def downgrade() -> None:
    op.drop_constraint("ck_access_profiles_cli_login", "device_access_profiles", type_="check")
    op.drop_constraint("ck_access_profiles_cli_port", "device_access_profiles", type_="check")
    op.drop_constraint("ck_access_profiles_cli_protocol", "device_access_profiles", type_="check")
    for column in ("cli_enable_password_enc", "cli_password_enc", "cli_username", "cli_port", "cli_protocol"):
        op.drop_column("device_access_profiles", column)
