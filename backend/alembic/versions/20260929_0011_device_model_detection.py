"""Device model detection: link device_models to the vendor registry and add the description-pattern half of detection.

Revision ID: 20260929_0011
Revises: 20260928_0010
Create Date: 2026-09-29

BDCOM (and some other vendors) do not publish per-model sysObjectID assignments, so detection needs a sysDescr regex too,
guarded by the sysObjectID prefix so no other vendor's device can land on it. A model needs at least one of the two.
`priority` is the order to try models in: the source vendor file's own author already ordered specific-before-generic,
and we preserve that ordering rather than inventing a new specificity heuristic.
"""

from __future__ import annotations

from alembic import op
import sqlalchemy as sa
from sqlalchemy.dialects import postgresql

revision = "20260929_0011"
down_revision = "20260928_0010"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.add_column("device_models", sa.Column("family_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("vendor_model_families.id", ondelete="SET NULL"), nullable=True))
    op.add_column("device_models", sa.Column("sysdescr_pattern", sa.Text(), nullable=True))
    op.add_column("device_models", sa.Column("priority", sa.Integer(), nullable=False, server_default=sa.text("1000")))
    op.add_column("device_models", sa.Column("source_note", sa.Text(), nullable=True))
    op.add_column("device_models", sa.Column("legacy_key", sa.String(length=80), nullable=True))
    op.create_index("ix_device_models_family_id", "device_models", ["family_id"])
    op.create_index("ux_device_models_legacy_key", "device_models", ["legacy_key"], unique=True)
    op.create_check_constraint(
        "ck_device_models_has_a_matcher", "device_models", "sysobjectid_matcher is not null or sysdescr_pattern is not null"
    )
    op.create_check_constraint("ck_device_models_priority", "device_models", "priority between 0 and 100000")


def downgrade() -> None:
    op.drop_constraint("ck_device_models_priority", "device_models", type_="check")
    op.drop_constraint("ck_device_models_has_a_matcher", "device_models", type_="check")
    op.drop_index("ux_device_models_legacy_key", table_name="device_models")
    op.drop_index("ix_device_models_family_id", table_name="device_models")
    for column in ("legacy_key", "source_note", "priority", "sysdescr_pattern", "family_id"):
        op.drop_column("device_models", column)
