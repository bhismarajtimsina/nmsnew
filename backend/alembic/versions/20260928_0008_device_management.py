"""Device management: registry links, access profile rules, discovery jobs.

Revision ID: 20260928_0008
Revises: 20260928_0007
Create Date: 2026-09-28

  * a device can point at its vendor and model family (the registry from migration 0006)
  * an access profile must be complete for its SNMP version, and its timeouts are bounded
  * a discovery job can only ever carry the four safe system OIDs (the same database function as vendors use)
"""

from __future__ import annotations

from alembic import op
import sqlalchemy as sa
from sqlalchemy.dialects import postgresql

revision = "20260928_0008"
down_revision = "20260928_0007"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.add_column("devices", sa.Column("vendor_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("vendors.id", ondelete="SET NULL"), nullable=True))
    op.add_column("devices", sa.Column("family_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("vendor_model_families.id", ondelete="SET NULL"), nullable=True))
    op.add_column("devices", sa.Column("created_by", postgresql.UUID(as_uuid=True), sa.ForeignKey("users.id", ondelete="SET NULL"), nullable=True))
    op.create_index("ix_devices_family_id", "devices", ["family_id"])
    op.create_index("ix_devices_group_id", "devices", ["group_id"])
    op.create_check_constraint("ck_devices_device_type", "devices", "device_type in ('switch','olt','router','sensor','other')")

    op.create_check_constraint("ck_access_profiles_version", "device_access_profiles", "snmp_version in ('v1','v2c','v3')")
    op.create_check_constraint("ck_access_profiles_timeout", "device_access_profiles", "timeout_ms between 200 and 10000")
    op.create_check_constraint("ck_access_profiles_retries", "device_access_profiles", "retries between 0 and 3")
    op.create_check_constraint(
        "ck_access_profiles_complete",
        "device_access_profiles",
        "(snmp_version in ('v1','v2c') and snmp_community_enc is not null) or "
        "(snmp_version = 'v3' and snmp_v3_username is not null and snmp_v3_auth_secret_enc is not null "
        "and snmp_v3_priv_secret_enc is not null)",
    )

    op.create_table(
        "discovery_jobs",
        sa.Column("id", postgresql.UUID(as_uuid=True), server_default=sa.text("gen_random_uuid()"), primary_key=True),
        sa.Column("device_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("devices.id", ondelete="CASCADE"), nullable=False),
        sa.Column("status", sa.String(length=12), nullable=False, server_default=sa.text("'queued'")),
        sa.Column("oids", postgresql.ARRAY(sa.Text()), nullable=False),
        sa.Column("timeout_ms", sa.Integer(), nullable=False),
        sa.Column("retries", sa.Integer(), nullable=False),
        sa.Column("requested_by", postgresql.UUID(as_uuid=True), sa.ForeignKey("users.id", ondelete="SET NULL"), nullable=True),
        sa.Column("requested_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.Column("started_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("finished_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("result", postgresql.JSONB(astext_type=sa.Text()), nullable=True),
        sa.Column("error", sa.Text(), nullable=True),
        sa.CheckConstraint("status in ('queued','running','succeeded','failed','skipped')", name="ck_discovery_jobs_status"),
        sa.CheckConstraint("cs_safe_discovery_oids(oids)", name="ck_discovery_jobs_safe_oids"),
        sa.CheckConstraint("timeout_ms between 200 and 10000", name="ck_discovery_jobs_timeout"),
        sa.CheckConstraint("retries between 0 and 3", name="ck_discovery_jobs_retries"),
        sa.CheckConstraint("status <> 'skipped' or error is not null", name="ck_discovery_jobs_skipped_says_why"),
    )
    op.create_index("ix_discovery_jobs_device_id", "discovery_jobs", ["device_id", "requested_at"])
    op.create_index("ix_discovery_jobs_status", "discovery_jobs", ["status"])


def downgrade() -> None:
    op.drop_index("ix_discovery_jobs_status", table_name="discovery_jobs")
    op.drop_index("ix_discovery_jobs_device_id", table_name="discovery_jobs")
    op.drop_table("discovery_jobs")
    for name in ("complete", "retries", "timeout", "version"):
        op.drop_constraint(f"ck_access_profiles_{name}", "device_access_profiles", type_="check")
    op.drop_constraint("ck_devices_device_type", "devices", type_="check")
    op.drop_index("ix_devices_group_id", table_name="devices")
    op.drop_index("ix_devices_family_id", table_name="devices")
    for column in ("created_by", "family_id", "vendor_id"):
        op.drop_column("devices", column)
