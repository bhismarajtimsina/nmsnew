"""Macros and ONU-registration templates (Plan 38): stored templates with declared parameters.

Revision ID: 20261005_0022
Revises: 20261001_0021
Create Date: 2026-10-05

One table for both kinds; legacy kept them in two (c_macros and the OntsRegistration component's table) with the same
shape. A template is rendered by app/actions/templates.py, never by the database, and is checked there when saved;
the constraints below are the backstop. Running one against a device is Plan 38's queued action flow, not this table.
"""
from __future__ import annotations

from alembic import op
import sqlalchemy as sa
from sqlalchemy.dialects import postgresql

revision = "20261005_0022"
down_revision = "20261001_0021"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.create_table(
        "macros",
        sa.Column("id", postgresql.UUID(as_uuid=True), server_default=sa.text("gen_random_uuid()"), primary_key=True),
        sa.Column("kind", sa.String(24), nullable=False),
        sa.Column("name", sa.String(200), nullable=False),
        sa.Column("description", sa.Text(), nullable=False, server_default=""),
        sa.Column("template", sa.Text(), nullable=False),
        sa.Column("parameters", postgresql.JSONB(), nullable=False, server_default=sa.text("'[]'::jsonb")),
        sa.Column("display_output", sa.String(8), nullable=False, server_default="none"),
        sa.Column("model_keys", postgresql.ARRAY(sa.Text()), nullable=False, server_default=sa.text("'{}'::text[]")),
        sa.Column("version", sa.Integer(), nullable=False, server_default="1"),
        sa.Column("created_by_user_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("users.id", ondelete="SET NULL"), nullable=True),
        sa.Column("updated_by_user_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("users.id", ondelete="SET NULL"), nullable=True),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.Column("updated_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.CheckConstraint("kind in ('macro', 'onu_registration')", name="ck_macros_kind"),
        sa.CheckConstraint("display_output in ('none', 'all', 'last')", name="ck_macros_display_output"),
        sa.CheckConstraint("length(name) between 1 and 200", name="ck_macros_name"),
        sa.CheckConstraint("length(template) between 1 and 20000", name="ck_macros_template_size"),
        sa.CheckConstraint("jsonb_typeof(parameters) = 'array' and jsonb_array_length(parameters) <= 40", name="ck_macros_parameters"),
        sa.UniqueConstraint("kind", "name", name="uq_macros_kind_name"),
    )


def downgrade() -> None:
    op.drop_table("macros")
