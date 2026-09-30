import asyncpg
import pytest

from app.registry.vendor_data import CAPABILITIES, FAMILIES, VENDORS
from app.repositories.vendors import polling_allowed
from app.seed import seed
from tests.helpers import bearer, make_user

SAFE = ["1.3.6.1.2.1.1.1.0", "1.3.6.1.2.1.1.2.0", "1.3.6.1.2.1.1.3.0", "1.3.6.1.2.1.1.5.0"]


async def test_the_seed_creates_every_planned_vendor_and_family(db):
    assert await db.fetchval("select count(*) from vendors") == len(VENDORS) == 22
    assert await db.fetchval("select count(*) from vendor_model_families") == len(FAMILIES)
    assert await db.fetchval("select count(*) from capabilities") == len(CAPABILITIES)


async def test_bdcom_switches_and_olts_are_separate_families_with_separate_types(db):
    rows = await db.fetch(
        "select f.slug, f.device_type from vendor_model_families f join vendors v on v.id = f.vendor_id where v.slug = 'bdcom' order by f.slug")
    assert [(r["slug"], r["device_type"]) for r in rows] == [("bdcom-olt", "olt"), ("bdcom-switch", "switch")]


async def test_every_vendor_has_the_safe_discovery_policy_by_default(db):
    assert await db.fetchval("select count(*) from vendors where discovery_oids = $1::text[]", SAFE) == 22


@pytest.mark.parametrize("oids", [
    ["1.3.6.1.2.1.2.2.1.2"],                       # the interface table
    ["1.3.6.1.4.1.3320"],                          # a private enterprise tree
    SAFE + ["1.3.6.1.2.1.1.6.0"],                  # a fifth OID, even a harmless-looking one
    ["1.3.6.1.2.1.1"],                             # the whole system subtree
    [],
])
async def test_the_database_refuses_any_discovery_beyond_the_four_system_oids(db, oids):
    with pytest.raises(asyncpg.CheckViolationError):
        await db.execute("update vendors set discovery_oids = $1::text[] where slug = 'bdcom'", oids)


async def test_a_narrower_discovery_policy_is_allowed(db):
    await db.execute("update vendors set discovery_oids = $1::text[] where slug = 'bdcom'", SAFE[:2])
    assert await db.fetchval("select cardinality(discovery_oids) from vendors where slug = 'bdcom'") == 2


async def test_a_capability_cannot_be_supported_without_a_fixture(db):
    family = await db.fetchval("select id from vendor_model_families where slug = 'bdcom-olt'")
    with pytest.raises(asyncpg.CheckViolationError):
        await db.execute("insert into family_capabilities (family_id, capability_code, status) values ($1, 'onu_list', 'supported')", family)
    with pytest.raises(asyncpg.CheckViolationError):
        await db.execute(
            "insert into family_capabilities (family_id, capability_code, status, verified_by_fixture) values ($1, 'onu_list', 'supported', true)", family)
    await db.execute(
        "insert into family_capabilities (family_id, capability_code, status, verified_by_fixture, fixture_ref) "
        "values ($1, 'onu_list', 'supported', true, 'fixtures/bdcom/gp3600/onu_list.json')", family)
    assert await db.fetchval("select status from family_capabilities where capability_code = 'onu_list'") == "supported"


async def test_a_high_risk_capability_is_never_enabled_by_default(db):
    with pytest.raises(asyncpg.CheckViolationError):
        await db.execute("insert into capabilities (code, description, risk, enabled_by_default) values ('x_walk', 'x', 'high', true)")
    high = await db.fetch("select code, enabled_by_default from capabilities where risk = 'high'")
    assert {r["code"] for r in high} == {"fdb_full", "private_enterprise_walk"} and not any(r["enabled_by_default"] for r in high)


async def test_switching_polling_off_needs_a_reason_even_directly_in_the_database(db):
    with pytest.raises(asyncpg.CheckViolationError):
        await db.execute("update vendors set polling_enabled = false where slug = 'bdcom'")
    with pytest.raises(asyncpg.CheckViolationError):
        await db.execute("update vendor_model_families set polling_enabled = false where slug = 'bdcom-olt'")


async def test_polling_is_allowed_only_when_vendor_and_family_are_both_on_and_known_things_only(db):
    assert await polling_allowed(db, "bdcom", "bdcom-olt") is True
    assert await polling_allowed(db, "bdcom", "no-such-family") is False      # fails closed
    assert await polling_allowed(db, "no-such-vendor", "bdcom-olt") is False
    assert await polling_allowed(db, "cisco", "bdcom-olt") is False           # a family under a different vendor


async def test_the_kill_switch_can_stop_bdcom_olts_without_touching_bdcom_switches(app_client, db):
    await make_user(db, "noc", "ISP NOC")
    headers = await bearer(app_client, "noc")
    app_client.cookies.clear()
    off = await app_client.put("/api/v1/vendors/bdcom/families/bdcom-olt/polling", headers=headers,
                               json={"enabled": False, "reason": "OLT reboot storm, investigating"})
    assert off.status_code == 200 and off.json()["polling_enabled"] is False
    assert await polling_allowed(db, "bdcom", "bdcom-olt") is False
    assert await polling_allowed(db, "bdcom", "bdcom-switch") is True
    assert await polling_allowed(db, "huawei", "huawei-olt") is True
    audit = await db.fetchrow("select action, metadata->>'reason' as reason from audit_logs where action = 'family.polling_disabled'")
    assert audit["reason"] == "OLT reboot storm, investigating"
    back = await app_client.put("/api/v1/vendors/bdcom/families/bdcom-olt/polling", headers=headers, json={"enabled": True})
    assert back.status_code == 200 and back.json()["polling_disabled_reason"] is None
    assert await polling_allowed(db, "bdcom", "bdcom-olt") is True


async def test_switching_a_whole_vendor_off_stops_all_of_its_families(app_client, db):
    await make_user(db, "noc", "ISP NOC")
    headers = await bearer(app_client, "noc")
    app_client.cookies.clear()
    assert (await app_client.put("/api/v1/vendors/bdcom/polling", headers=headers, json={"enabled": False, "reason": "vendor advisory"})).status_code == 200
    assert await polling_allowed(db, "bdcom", "bdcom-olt") is False and await polling_allowed(db, "bdcom", "bdcom-switch") is False
    assert await polling_allowed(db, "zte", "zte-c-olt") is True
    assert (await app_client.put("/api/v1/vendors/bdcom/polling", headers=headers, json={"enabled": True})).status_code == 200


async def test_switching_off_needs_a_reason_and_a_known_target(app_client, db):
    await make_user(db, "noc", "ISP NOC")
    headers = await bearer(app_client, "noc")
    app_client.cookies.clear()
    assert (await app_client.put("/api/v1/vendors/bdcom/polling", headers=headers, json={"enabled": False})).status_code == 422
    assert (await app_client.put("/api/v1/vendors/bdcom/polling", headers=headers, json={"enabled": False, "reason": "   "})).status_code == 422
    assert (await app_client.put("/api/v1/vendors/nope/polling", headers=headers, json={"enabled": True})).status_code == 404
    assert (await app_client.put("/api/v1/vendors/bdcom/families/nope/polling", headers=headers, json={"enabled": True})).status_code == 404
    assert await polling_allowed(db, "bdcom", "bdcom-olt") is True


async def test_who_may_read_and_who_may_stop_polling(app_client, db):
    for name, role in (("support", "ISP Support"), ("reseller", "Reseller Admin"), ("admin", "ISP Admin"), ("root", "Super Admin")):
        await make_user(db, name, role)
    tokens = {name: await bearer(app_client, name) for name in ("support", "reseller", "admin", "root")}
    app_client.cookies.clear()
    body = {"enabled": False, "reason": "test"}
    assert (await app_client.get("/api/v1/vendors", headers=tokens["support"])).status_code == 200
    assert (await app_client.put("/api/v1/vendors/bdcom/polling", headers=tokens["support"], json=body)).status_code == 403
    assert (await app_client.get("/api/v1/vendors", headers=tokens["reseller"])).status_code == 403
    assert (await app_client.get("/api/v1/capabilities", headers=tokens["reseller"])).status_code == 403
    assert (await app_client.patch("/api/v1/vendors/bdcom", headers=tokens["admin"], json={"notes": "x"})).status_code == 403
    assert (await app_client.patch("/api/v1/vendors/bdcom", headers=tokens["root"], json={"notes": "checked", "discovery_timeout_ms": 3000})).status_code == 200
    assert (await app_client.get("/api/v1/vendors/nope", headers=tokens["root"])).status_code == 404


async def test_the_discovery_oids_cannot_be_changed_through_the_api(app_client, db):
    await make_user(db, "root", "Super Admin")
    headers = await bearer(app_client, "root")
    app_client.cookies.clear()
    response = await app_client.patch("/api/v1/vendors/bdcom", headers=headers, json={"discovery_oids": ["1.3.6.1.4.1.3320"], "notes": "n"})
    assert response.status_code == 200 and response.json()["discovery_oids"] == SAFE
    assert (await app_client.patch("/api/v1/vendors/bdcom", headers=headers, json={"discovery_timeout_ms": 5})).status_code == 422


async def test_capabilities_read_as_unverified_until_a_fixture_says_otherwise(app_client, db):
    await make_user(db, "support", "ISP Support")
    headers = await bearer(app_client, "support")
    app_client.cookies.clear()
    rows = (await app_client.get("/api/v1/vendors/bdcom/families/bdcom-olt/capabilities", headers=headers)).json()
    assert len(rows) == len(CAPABILITIES) and {r["status"] for r in rows} == {"unverified"}
    assert not any(r["verified_by_fixture"] for r in rows)
    assert (await app_client.get("/api/v1/vendors/bdcom/families/nope/capabilities", headers=headers)).status_code == 404
    vendor = (await app_client.get("/api/v1/vendors/bdcom", headers=headers)).json()
    assert {f["slug"] for f in vendor["model_families"]} == {"bdcom-switch", "bdcom-olt"}


async def test_reseeding_neither_duplicates_nor_reverts_an_operators_kill_switch(db):
    await db.execute("update vendors set polling_enabled = false, polling_disabled_reason = 'incident' where slug = 'zte'")
    await db.execute("update capabilities set risk = 'low' where code = 'fdb_full'")
    result = await seed(db, admin_username="boot", admin_password="Boot-Strap-Password-1!")
    assert (result.vendors_added, result.families_added) == (0, 0)
    assert await db.fetchval("select polling_enabled from vendors where slug = 'zte'") is False   # operator's decision stands
    assert await db.fetchval("select risk from capabilities where code = 'fdb_full'") == "high"    # code-owned safety fact restored
    await db.execute("update vendors set polling_enabled = true, polling_disabled_reason = null where slug = 'zte'")
