"""Vendor registry: vendors, model families, capabilities, and the polling kill switches.

Revision ID: 20260928_0006
Revises: 20260928_0005
Create Date: 2026-09-28

Safety rules live in the database so no code path can bypass them:
  * safe discovery can only ever name the four scalar system OIDs (sysDescr, sysObjectID, sysUpTime, sysName)
  * a capability cannot be marked "supported" unless a recorded fixture verified it
  * a high-risk capability is never enabled by default
  * a vendor or family with polling switched off must say why
"""

from __future__ import annotations

from alembic import op
import sqlalchemy as sa
from sqlalchemy.dialects import postgresql

revision = "20260928_0006"
down_revision = "20260928_0005"
branch_labels = None
depends_on = None

SAFE_OIDS = "array['1.3.6.1.2.1.1.1.0','1.3.6.1.2.1.1.2.0','1.3.6.1.2.1.1.3.0','1.3.6.1.2.1.1.5.0']"


def upgrade() -> None:
    op.execute(
        """
        create function cs_safe_discovery_oids(oids text[]) returns boolean language sql immutable as $$
            select coalesce(cardinality(oids) between 1 and 4, false)
               and not exists (
                   select 1 from unnest(oids) as o
                   where o not in ('1.3.6.1.2.1.1.1.0', '1.3.6.1.2.1.1.2.0', '1.3.6.1.2.1.1.3.0', '1.3.6.1.2.1.1.5.0')
               )
        $$
        """
    )

    op.create_table(
        "vendors",
        sa.Column("id", postgresql.UUID(as_uuid=True), server_default=sa.text("gen_random_uuid()"), primary_key=True),
        sa.Column("slug", sa.String(length=60), nullable=False, unique=True),
        sa.Column("name", sa.String(length=120), nullable=False, unique=True),
        sa.Column("polling_enabled", sa.Boolean(), nullable=False, server_default=sa.text("true")),
        sa.Column("polling_disabled_reason", sa.Text(), nullable=True),
        sa.Column("polling_toggled_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("polling_toggled_by", postgresql.UUID(as_uuid=True), sa.ForeignKey("users.id", ondelete="SET NULL"), nullable=True),
        sa.Column("discovery_oids", postgresql.ARRAY(sa.Text()), nullable=False, server_default=sa.text(SAFE_OIDS)),
        sa.Column("discovery_timeout_ms", sa.Integer(), nullable=False, server_default=sa.text("2000")),
        sa.Column("discovery_retries", sa.Integer(), nullable=False, server_default=sa.text("1")),
        sa.Column("notes", sa.Text(), nullable=True),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.Column("updated_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.CheckConstraint("slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'", name="ck_vendors_slug"),
        sa.CheckConstraint("cs_safe_discovery_oids(discovery_oids)", name="ck_vendors_safe_discovery"),
        sa.CheckConstraint("discovery_timeout_ms between 200 and 10000", name="ck_vendors_discovery_timeout"),
        sa.CheckConstraint("discovery_retries between 0 and 3", name="ck_vendors_discovery_retries"),
        sa.CheckConstraint("polling_enabled or polling_disabled_reason is not null", name="ck_vendors_disabled_needs_reason"),
    )

    op.create_table(
        "vendor_model_families",
        sa.Column("id", postgresql.UUID(as_uuid=True), server_default=sa.text("gen_random_uuid()"), primary_key=True),
        sa.Column("vendor_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("vendors.id", ondelete="CASCADE"), nullable=False),
        sa.Column("slug", sa.String(length=80), nullable=False),
        sa.Column("name", sa.String(length=160), nullable=False),
        sa.Column("device_type", sa.String(length=20), nullable=False),
        sa.Column("polling_enabled", sa.Boolean(), nullable=False, server_default=sa.text("true")),
        sa.Column("polling_disabled_reason", sa.Text(), nullable=True),
        sa.Column("polling_toggled_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("polling_toggled_by", postgresql.UUID(as_uuid=True), sa.ForeignKey("users.id", ondelete="SET NULL"), nullable=True),
        sa.Column("notes", sa.Text(), nullable=True),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.UniqueConstraint("vendor_id", "slug", name="uq_vendor_families_vendor_slug"),
        sa.CheckConstraint("device_type in ('switch','olt','router','sensor','other')", name="ck_vendor_families_device_type"),
        sa.CheckConstraint("polling_enabled or polling_disabled_reason is not null", name="ck_vendor_families_disabled_needs_reason"),
    )
    op.create_index("ix_vendor_model_families_vendor_id", "vendor_model_families", ["vendor_id"])

    op.create_table(
        "capabilities",
        sa.Column("code", sa.String(length=60), primary_key=True),
        sa.Column("description", sa.Text(), nullable=False),
        sa.Column("risk", sa.String(length=10), nullable=False),
        sa.Column("enabled_by_default", sa.Boolean(), nullable=False, server_default=sa.text("false")),
        sa.CheckConstraint("risk in ('low','bounded','high')", name="ck_capabilities_risk"),
        sa.CheckConstraint("risk <> 'high' or not enabled_by_default", name="ck_capabilities_high_risk_not_default"),
        sa.CheckConstraint("code ~ '^[a-z0-9_]+$'", name="ck_capabilities_code"),
    )

    op.create_table(
        "family_capabilities",
        sa.Column("family_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("vendor_model_families.id", ondelete="CASCADE"), primary_key=True),
        sa.Column("capability_code", sa.String(length=60), sa.ForeignKey("capabilities.code", ondelete="CASCADE"), primary_key=True),
        sa.Column("status", sa.String(length=12), nullable=False, server_default=sa.text("'unverified'")),
        sa.Column("verified_by_fixture", sa.Boolean(), nullable=False, server_default=sa.text("false")),
        sa.Column("fixture_ref", sa.String(length=255), nullable=True),
        sa.Column("note", sa.Text(), nullable=True),
        sa.CheckConstraint("status in ('supported','unsupported','unverified')", name="ck_family_capabilities_status"),
        # What cannot be confirmed offline is not enabled.
        sa.CheckConstraint("status <> 'supported' or (verified_by_fixture and fixture_ref is not null)", name="ck_family_capabilities_supported_needs_fixture"),
    )


def downgrade() -> None:
    op.drop_table("family_capabilities")
    op.drop_table("capabilities")
    op.drop_index("ix_vendor_model_families_vendor_id", table_name="vendor_model_families")
    op.drop_table("vendor_model_families")
    op.drop_table("vendors")
    op.execute("drop function cs_safe_discovery_oids(text[])")
