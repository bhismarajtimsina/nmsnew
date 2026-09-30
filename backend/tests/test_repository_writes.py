"""The repositories enforce scope on their own, so a future endpoint that forgets to check first still cannot reach a foreign row."""
import pytest

from app.core.security import CurrentUser
from app.repositories import device_groups as groups
from app.repositories import devices
from tests.helpers import make_device, make_group, make_user


def principal(user_id: str, scope_all: bool = False) -> CurrentUser:
    return CurrentUser(id=user_id, username="u", display_name="u", email=None, role="r", role_id="1", scope_mode="all" if scope_all else "assigned")


async def world(db):
    mine, theirs = await make_group(db, "mine"), await make_group(db, "theirs")
    own, foreign = await make_device(db, "own", mine), await make_device(db, "foreign", theirs)
    uid = await make_user(db, "res", "Reseller Admin")
    await db.execute("insert into user_device_group_scopes (user_id, device_group_id) values ($1::uuid, $2::uuid)", uid, mine)
    return principal(uid), {"mine": mine, "theirs": theirs, "own": own, "foreign": foreign}


async def test_update_and_delete_refuse_a_foreign_device_at_the_repository_level(db):
    user, ids = await world(db)
    with pytest.raises(groups.NotVisible):
        await devices.update_device(db, user, ids["foreign"], {"name": "hijacked"})
    with pytest.raises(groups.NotVisible):
        await devices.delete_device(db, user, ids["foreign"])
    assert await db.fetchval("select name from devices where id = $1::uuid", ids["foreign"]) == "foreign"
    await devices.update_device(db, user, ids["own"], {"name": "renamed"})                  # their own is fine
    assert await db.fetchval("select name from devices where id = $1::uuid", ids["own"]) == "renamed"


async def test_moving_a_device_into_a_foreign_group_is_refused_at_the_repository_level(db):
    user, ids = await world(db)
    with pytest.raises(groups.NotVisible):
        await devices.update_device(db, user, ids["own"], {"group_id": ids["theirs"]})
    assert str(await db.fetchval("select group_id from devices where id = $1::uuid", ids["own"])) == ids["mine"]


async def test_creating_in_a_foreign_group_is_refused_at_the_repository_level(db):
    user, ids = await world(db)
    with pytest.raises(groups.NotVisible):
        await devices.create_device(db, user, name="x", hostname=None, management_ip="10.7.7.7", device_type="switch",
                                    vendor_slug=None, family_slug=None, group_id=ids["theirs"], access_profile_id=None)
    assert await db.fetchval("select count(*) from devices where name = 'x'") == 0


async def test_group_writes_refuse_foreign_groups_at_the_repository_level(db):
    user, ids = await world(db)
    with pytest.raises(groups.NotVisible):
        await groups.update_group(db, user, ids["theirs"], {"name": "x"})
    with pytest.raises(groups.NotVisible):
        await groups.delete_group(db, user, ids["theirs"])
    with pytest.raises(groups.NotVisible):
        await groups.create_group(db, user, "child", ids["theirs"], None)
    assert await db.fetchval("select name from device_groups where id = $1::uuid", ids["theirs"]) == "theirs"


async def test_a_role_that_sees_everything_may_reach_any_row(db):
    _, ids = await world(db)
    admin = principal(await make_user(db, "isp", "ISP Admin"), scope_all=True)
    await devices.update_device(db, admin, ids["foreign"], {"name": "moved"})
    assert await db.fetchval("select name from devices where id = $1::uuid", ids["foreign"]) == "moved"
