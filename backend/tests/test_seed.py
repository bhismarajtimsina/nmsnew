import pytest

from app.core.config import settings
from app.core.passwords import verify_password
from app.seed import WeakSeedPassword, seed
from tests.helpers import make_user


async def counts(db):
    return {
        "permissions": await db.fetchval("select count(*) from permissions"),
        "roles": await db.fetchval("select count(*) from roles"),
        "role_permissions": await db.fetchval("select count(*) from role_permissions"),
    }


async def test_a_second_run_changes_nothing_and_reports_nothing(db):
    before = await counts(db)
    first = await seed(db, admin_username="boot", admin_password="Boot-Strap-Password-1!")
    assert first.admin_status == "created"
    again = await seed(db, admin_username="boot", admin_password="A-Different-Password-2!")
    assert (again.permissions_added, again.roles_added, again.role_permissions_added) == (0, [], 0)
    assert again.admin_status == "unchanged" and again.generated_password is None
    assert await counts(db) == before
    stored = await db.fetchval("select password_hash from users where username = 'boot'")
    assert verify_password(stored, "Boot-Strap-Password-1!")[0] and not verify_password(stored, "A-Different-Password-2!")[0]


async def test_a_generated_password_is_reported_once_and_forces_a_change(db):
    first = await seed(db, admin_username="boot")
    assert first.generated_password
    row = await db.fetchrow("select password_hash, must_change_password from users where username = 'boot'")
    assert row["must_change_password"] is True and verify_password(row["password_hash"], first.generated_password)[0]
    second = await seed(db, admin_username="boot")
    assert second.generated_password is None


async def test_a_supplied_password_does_not_force_a_change(db):
    await seed(db, admin_username="boot", admin_password="Boot-Strap-Password-1!")
    assert await db.fetchval("select must_change_password from users where username = 'boot'") is False


async def test_an_existing_user_is_never_promoted_or_reactivated(db):
    await make_user(db, "admin", role="Reseller Viewer", active=False)
    result = await seed(db, admin_username="admin", admin_password="Boot-Strap-Password-1!")
    row = await db.fetchrow("select r.name as role, u.is_active from users u join roles r on r.id = u.role_id where username = 'admin'")
    assert result.admin_status == "unchanged" and row["role"] == "Reseller Viewer" and row["is_active"] is False


async def test_recovery_sets_a_password_only_when_there_is_none(db):
    await make_user(db, "admin", role="Super Admin", password=None)
    assert (await seed(db, admin_username="admin")).admin_status == "unchanged"
    assert await db.fetchval("select password_hash from users where username = 'admin'") is None
    assert (await seed(db, admin_username="admin", admin_password="Recovered-Password-1!")).admin_status == "password_set"
    assert await db.fetchval("select password_hash from users where username = 'admin'") is not None


async def test_a_scope_mode_chosen_by_an_administrator_is_not_reverted(db):
    await db.execute("update roles set scope_mode = 'all' where name = 'Reseller Admin'")
    await seed(db, admin_username="boot", admin_password="Boot-Strap-Password-1!")
    assert await db.fetchval("select scope_mode from roles where name = 'Reseller Admin'") == "all"
    await db.execute("update roles set scope_mode = 'assigned' where name = 'Reseller Admin'")


async def test_a_permission_an_administrator_removed_is_not_put_back_but_reset_restores_it(db):
    await db.execute(
        "delete from role_permissions where role_id = (select id from roles where name = 'ISP NOC') "
        "and permission_id = (select id from permissions where code = 'events.resolve')"
    )
    await seed(db, admin_username="boot", admin_password="Boot-Strap-Password-1!")
    has = "select exists(select 1 from role_permissions rp join roles r on r.id = rp.role_id join permissions p on p.id = rp.permission_id where r.name = 'ISP NOC' and p.code = 'events.resolve')"
    assert await db.fetchval(has) is False
    await seed(db, admin_username="boot", reset_role_permissions=True)
    assert await db.fetchval(has) is True


async def test_a_permission_new_to_the_catalogue_reaches_the_default_roles(db):
    await db.execute("delete from permissions where code = 'analytics.view'")
    result = await seed(db, admin_username="boot", admin_password="Boot-Strap-Password-1!")
    assert result.permissions_added == 1 and result.role_permissions_added >= 1
    holders = await db.fetchval(
        "select count(*) from role_permissions rp join permissions p on p.id = rp.permission_id where p.code = 'analytics.view'")
    assert holders >= 3


async def test_a_weak_supplied_password_is_refused_in_production_only(db, monkeypatch):
    monkeypatch.setattr(settings, "environment", "production")
    with pytest.raises(WeakSeedPassword):
        await seed(db, admin_username="boot", admin_password="weak")
    assert await db.fetchval("select count(*) from users where username = 'boot'") == 0
    monkeypatch.setattr(settings, "environment", "development")
    assert (await seed(db, admin_username="boot", admin_password="weak")).admin_status == "created"


async def test_the_dangerous_flag_and_scope_modes_are_seeded(db):
    assert await db.fetchval("select is_dangerous from permissions where code = 'olts.onu.reboot'") is True
    assert await db.fetchval("select is_dangerous from permissions where code = 'devices.view'") is False
    modes = {r["name"]: r["scope_mode"] for r in await db.fetch("select name, scope_mode from roles")}
    assert modes["Super Admin"] == "all" and modes["ISP Admin"] == "all" and modes["Reseller Viewer"] == "assigned"
