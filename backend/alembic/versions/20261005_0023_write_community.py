"""A separate SNMP write community on access profiles (Plan 38).

Revision ID: 20261005_0023
Revises: 20261005_0022
Create Date: 2026-10-05

Legacy keeps two communities per access profile, `public_community` (read) and `private_community` (write). The new
schema had only one, used for polling. Device actions must not write with the polling community, so the write
community gets its own encrypted column. It is optional: a profile without one can be polled but not written to.
SNMPv3 profiles write with their own user (the device's view decides what it may set), so a write community is
refused on them.
"""
from __future__ import annotations

from alembic import op
import sqlalchemy as sa

revision = "20261005_0023"
down_revision = "20261005_0022"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.add_column("device_access_profiles", sa.Column("snmp_write_community_enc", sa.Text(), nullable=True))
    op.create_check_constraint(
        "ck_access_profiles_write_community_version", "device_access_profiles",
        "snmp_write_community_enc is null or snmp_version in ('v1', 'v2c')",
    )


def downgrade() -> None:
    op.drop_constraint("ck_access_profiles_write_community_version", "device_access_profiles", type_="check")
    op.drop_column("device_access_profiles", "snmp_write_community_enc")
