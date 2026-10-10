"""Transport paths (Plan 27).

Revision ID: 20261010_0029
Revises: 20261010_0028
Create Date: 2026-10-10

Legacy `c_paths`, `c_path_segments` and `c_path_states`: a named route between two endpoint devices made of ordered
links, grouped by `group_key` so paths protecting each other can be judged together, and the last computed state per
path. The `paths_state` schedule recomputes every enabled path each minute, as legacy's `paths:calc-state` does.
"""
from __future__ import annotations

from alembic import op
import sqlalchemy as sa
from sqlalchemy.dialects import postgresql

revision = "20261010_0029"
down_revision = "20261010_0028"
branch_labels = None
depends_on = None

BEFORE = "('poll_group','retention','cleanup_sessions','sync_active_alerts','maintenance_release')"
AFTER = "('poll_group','retention','cleanup_sessions','sync_active_alerts','maintenance_release','paths_state')"


def upgrade() -> None:
    op.create_table(
        "paths",
        sa.Column("id", postgresql.UUID(as_uuid=True), server_default=sa.text("gen_random_uuid()"), primary_key=True),
        sa.Column("name", sa.String(255), nullable=False),
        sa.Column("group_key", sa.String(190), nullable=True),
        sa.Column("priority", sa.Integer(), nullable=False, server_default="100"),
        sa.Column("endpoint_a_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("devices.id", ondelete="CASCADE"), nullable=False),
        sa.Column("endpoint_b_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("devices.id", ondelete="CASCADE"), nullable=False),
        sa.Column("enabled", sa.Boolean(), nullable=False, server_default=sa.text("true")),
        sa.Column("description", sa.String(500), nullable=True),
        sa.Column("created_by", postgresql.UUID(as_uuid=True), sa.ForeignKey("users.id", ondelete="SET NULL"), nullable=True),
        sa.Column("legacy_id", sa.Integer(), nullable=True, unique=True),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.Column("updated_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.CheckConstraint("endpoint_a_id <> endpoint_b_id", name="ck_paths_two_endpoints"),
        sa.CheckConstraint("priority between 0 and 100000", name="ck_paths_priority"),
        sa.CheckConstraint(r"group_key is null or group_key ~ '^[A-Za-z0-9_.:-]{1,190}$'", name="ck_paths_group_key"),
    )
    op.create_index("ix_paths_group_key", "paths", ["group_key"])
    op.create_table(
        "path_segments",
        sa.Column("path_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("paths.id", ondelete="CASCADE"), nullable=False),
        sa.Column("position", sa.Integer(), nullable=False),
        sa.Column("link_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("links.id", ondelete="CASCADE"), nullable=False),
        sa.PrimaryKeyConstraint("path_id", "position", name="pk_path_segments"),
        sa.UniqueConstraint("path_id", "link_id", name="uq_path_segments_link"),
        sa.CheckConstraint("position between 1 and 64", name="ck_path_segments_position"),
    )
    op.create_table(
        "path_states",
        sa.Column("path_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("paths.id", ondelete="CASCADE"), primary_key=True),
        sa.Column("state", sa.String(10), nullable=False, server_default="unknown"),
        sa.Column("last_change", sa.DateTime(timezone=True), nullable=True),
        sa.Column("updated_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.Column("detail", postgresql.JSONB(), nullable=True),
        sa.CheckConstraint("state in ('up', 'degraded', 'down', 'unknown')", name="ck_path_states_state"),
    )
    op.drop_constraint("ck_schedule_jobs_type", "schedule_jobs", type_="check")
    op.create_check_constraint("ck_schedule_jobs_type", "schedule_jobs", f"job_type in {AFTER}")


def downgrade() -> None:
    op.execute("delete from schedule_jobs where job_type = 'paths_state'")
    op.drop_constraint("ck_schedule_jobs_type", "schedule_jobs", type_="check")
    op.create_check_constraint("ck_schedule_jobs_type", "schedule_jobs", f"job_type in {BEFORE}")
    op.drop_table("path_states")
    op.drop_table("path_segments")
    op.drop_index("ix_paths_group_key", table_name="paths")
    op.drop_table("paths")
