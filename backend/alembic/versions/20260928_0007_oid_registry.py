"""OID registry: definitions, versioned profiles, transform rules, and the rules that keep polling safe.

Revision ID: 20260928_0007
Revises: 20260928_0006
Create Date: 2026-09-28

Enforced by the database, not by convention:
  * a writable or "dangerous" OID can never be an entry of a polling profile
  * every walk has a row limit and a timeout
  * an active profile is immutable: change it by creating a new version
  * a scale transform needs a physical range and a fixture that proves it
"""

from __future__ import annotations

from alembic import op
import sqlalchemy as sa
from sqlalchemy.dialects import postgresql

revision = "20260928_0007"
down_revision = "20260928_0006"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.create_table(
        "oid_definitions",
        sa.Column("id", postgresql.UUID(as_uuid=True), server_default=sa.text("gen_random_uuid()"), primary_key=True),
        sa.Column("vendor_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("vendors.id", ondelete="RESTRICT"), nullable=True),
        sa.Column("logical_name", sa.String(length=120), nullable=False),
        sa.Column("numeric_oid", sa.String(length=255), nullable=False),
        sa.Column("module", sa.String(length=80), nullable=False),
        sa.Column("access", sa.String(length=20), nullable=False),
        sa.Column("safety_level", sa.String(length=10), nullable=False, server_default=sa.text("'safe'")),
        sa.Column("value_map", postgresql.JSONB(astext_type=sa.Text()), nullable=True),
        sa.Column("unit", sa.String(length=20), nullable=True),
        sa.Column("mib_object", sa.String(length=160), nullable=True),
        sa.Column("source_note", sa.Text(), nullable=False),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.CheckConstraint(r"numeric_oid ~ '^[0-9]+(\.[0-9]+)+$'", name="ck_oid_definitions_numeric_oid"),
        sa.CheckConstraint("access in ('read-only','read-write','write-only','read-create','not-accessible')", name="ck_oid_definitions_access"),
        sa.CheckConstraint("safety_level in ('safe','bounded','dangerous')", name="ck_oid_definitions_safety"),
        sa.CheckConstraint("logical_name ~ '^[a-z0-9_.]+$'", name="ck_oid_definitions_logical_name"),
        # A writable object is dangerous by definition. It may be recorded (so it is known and blocked) but never polled.
        sa.CheckConstraint("access in ('read-only','not-accessible') or safety_level = 'dangerous'", name="ck_oid_definitions_writable_is_dangerous"),
    )
    op.execute(
        "create unique index ux_oid_definitions_vendor_name on oid_definitions "
        "(coalesce(vendor_id, '00000000-0000-0000-0000-000000000000'::uuid), logical_name)"
    )
    op.create_index("ix_oid_definitions_numeric_oid", "oid_definitions", ["numeric_oid"])

    op.create_table(
        "oid_transform_rules",
        sa.Column("id", postgresql.UUID(as_uuid=True), server_default=sa.text("gen_random_uuid()"), primary_key=True),
        sa.Column("name", sa.String(length=120), nullable=False, unique=True),
        sa.Column("kind", sa.String(length=10), nullable=False),
        sa.Column("factor", sa.Numeric(), nullable=True),
        sa.Column("offset_value", sa.Numeric(), nullable=True),
        sa.Column("unit", sa.String(length=20), nullable=True),
        sa.Column("valid_min", sa.Numeric(), nullable=True),
        sa.Column("valid_max", sa.Numeric(), nullable=True),
        sa.Column("fixture_ref", sa.String(length=255), nullable=True),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.CheckConstraint("kind in ('scale','map')", name="ck_oid_transform_kind"),
        # A wrong scale hides a bad optical signal, so it needs a physical range and a fixture proving the arithmetic.
        sa.CheckConstraint(
            "kind <> 'scale' or (factor is not null and factor <> 0 and valid_min is not null and valid_max is not null "
            "and valid_min < valid_max and fixture_ref is not null)",
            name="ck_oid_transform_scale_needs_range_and_fixture",
        ),
    )

    op.create_table(
        "oid_profiles",
        sa.Column("id", postgresql.UUID(as_uuid=True), server_default=sa.text("gen_random_uuid()"), primary_key=True),
        sa.Column("name", sa.String(length=120), nullable=False),
        sa.Column("version", sa.Integer(), nullable=False),
        sa.Column("vendor_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("vendors.id", ondelete="RESTRICT"), nullable=True),
        sa.Column("family_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("vendor_model_families.id", ondelete="RESTRICT"), nullable=True),
        sa.Column("status", sa.String(length=10), nullable=False, server_default=sa.text("'draft'")),
        sa.Column("description", sa.Text(), nullable=True),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.Column("activated_at", sa.DateTime(timezone=True), nullable=True),
        sa.UniqueConstraint("name", "version", name="uq_oid_profiles_name_version"),
        sa.CheckConstraint("status in ('draft','active','retired')", name="ck_oid_profiles_status"),
        sa.CheckConstraint("version >= 1", name="ck_oid_profiles_version"),
    )
    op.create_index("ux_oid_profiles_one_active", "oid_profiles", ["name"], unique=True, postgresql_where=sa.text("status = 'active'"))

    op.create_table(
        "oid_profile_entries",
        sa.Column("profile_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("oid_profiles.id", ondelete="CASCADE"), primary_key=True),
        sa.Column("definition_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("oid_definitions.id", ondelete="RESTRICT"), primary_key=True),
        sa.Column("walk_strategy", sa.String(length=10), nullable=False),
        sa.Column("max_rows", sa.Integer(), nullable=True),
        sa.Column("timeout_ms", sa.Integer(), nullable=True),
        sa.Column("transform_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("oid_transform_rules.id", ondelete="RESTRICT"), nullable=True),
        sa.Column("position", sa.Integer(), nullable=False, server_default=sa.text("0")),
        sa.CheckConstraint("walk_strategy in ('get','getnext','walk','bulkwalk')", name="ck_oid_entries_strategy"),
        sa.CheckConstraint("max_rows is null or max_rows between 1 and 5000", name="ck_oid_entries_max_rows"),
        sa.CheckConstraint("timeout_ms is null or timeout_ms between 200 and 30000", name="ck_oid_entries_timeout"),
        sa.CheckConstraint("walk_strategy in ('get','getnext') or (max_rows is not null and timeout_ms is not null)", name="ck_oid_entries_walk_is_bounded"),
    )

    op.execute(
        """
        create function cs_check_profile_entry() returns trigger language plpgsql as $$
        declare
            d record;
            p record;
            target_profile uuid;
        begin
            target_profile := case when tg_op = 'DELETE' then old.profile_id else new.profile_id end;
            select status into p from oid_profiles where id = target_profile;
            if p.status = 'active' then
                raise exception 'profile is active and immutable: create a new version instead' using errcode = 'check_violation';
            end if;
            if tg_op = 'DELETE' then
                return old;
            end if;
            select logical_name, access, safety_level into d from oid_definitions where id = new.definition_id;
            if d.access not in ('read-only', 'not-accessible') or d.safety_level = 'dangerous' then
                raise exception 'OID % is writable or dangerous and cannot be part of a polling profile', d.logical_name using errcode = 'check_violation';
            end if;
            return new;
        end
        $$
        """
    )
    op.execute(
        "create trigger trg_oid_profile_entries_guard before insert or update or delete on oid_profile_entries "
        "for each row execute function cs_check_profile_entry()"
    )

    op.execute(
        """
        create function cs_check_definition_change() returns trigger language plpgsql as $$
        begin
            if (new.access not in ('read-only', 'not-accessible') or new.safety_level = 'dangerous')
               and exists (select 1 from oid_profile_entries where definition_id = new.id) then
                raise exception 'OID % is used by a polling profile and cannot become writable or dangerous', new.logical_name using errcode = 'check_violation';
            end if;
            return new;
        end
        $$
        """
    )
    op.execute(
        "create trigger trg_oid_definitions_guard before update on oid_definitions "
        "for each row execute function cs_check_definition_change()"
    )

    op.execute(
        """
        create function cs_check_profile_activation() returns trigger language plpgsql as $$
        begin
            if new.status = 'active' and old.status <> 'active' then
                if not exists (select 1 from oid_profile_entries where profile_id = new.id) then
                    raise exception 'a profile with no entries cannot be activated' using errcode = 'check_violation';
                end if;
                new.activated_at := now();
            end if;
            if old.status = 'active' and (new.name <> old.name or new.version <> old.version) then
                raise exception 'an active profile cannot be renamed or renumbered' using errcode = 'check_violation';
            end if;
            return new;
        end
        $$
        """
    )
    op.execute(
        "create trigger trg_oid_profiles_activation before update on oid_profiles "
        "for each row execute function cs_check_profile_activation()"
    )


def downgrade() -> None:
    op.execute("drop trigger trg_oid_profiles_activation on oid_profiles")
    op.execute("drop function cs_check_profile_activation()")
    op.execute("drop trigger trg_oid_definitions_guard on oid_definitions")
    op.execute("drop function cs_check_definition_change()")
    op.execute("drop trigger trg_oid_profile_entries_guard on oid_profile_entries")
    op.execute("drop function cs_check_profile_entry()")
    op.drop_table("oid_profile_entries")
    op.drop_index("ux_oid_profiles_one_active", table_name="oid_profiles")
    op.drop_table("oid_profiles")
    op.drop_table("oid_transform_rules")
    op.execute("drop index ux_oid_definitions_vendor_name")
    op.drop_index("ix_oid_definitions_numeric_oid", table_name="oid_definitions")
    op.drop_table("oid_definitions")
