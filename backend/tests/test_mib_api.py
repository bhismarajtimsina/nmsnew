from app.repositories.mib import check_definition, import_sources
from tests.helpers import bearer, make_user

SOURCES = {
    "a.mib": "sysDescr OBJECT-TYPE\n    ::= { mib-2 1 }\nsysContact OBJECT-TYPE\n    ::= { mib-2 4 }\n",
    "b.mib": "bdcomThing OBJECT-TYPE\n    ::= { enterprises 3320 }\n",
}


async def login_as(app_client, db, name, role):
    await make_user(db, name, role)
    headers = await bearer(app_client, name)
    app_client.cookies.clear()
    return headers


async def test_importing_indexes_files_and_objects_and_is_idempotent(db):
    report = await import_sources(db, SOURCES, None)
    assert (report.files, report.objects, report.resolved) == (2, 3, 3)
    assert await db.fetchval("select count(*) from mib_files") == 2
    assert await db.fetchval("select count(*) from mib_objects") == 3
    assert await db.fetchval("select numeric_oid from mib_objects where name = 'sysDescr'") == "1.3.6.1.2.1.1"

    again = await import_sources(db, SOURCES, None)
    assert (again.files, again.objects, again.resolved) == (2, 3, 3)
    assert await db.fetchval("select count(*) from mib_files") == 2   # re-importing does not duplicate
    assert await db.fetchval("select count(*) from mib_objects") == 3


async def test_reimporting_a_file_replaces_its_objects_not_appends(db):
    await import_sources(db, {"a.mib": SOURCES["a.mib"]}, None)
    changed = {"a.mib": "sysDescr OBJECT-TYPE\n    ::= { mib-2 1 }\n"}  # sysContact removed from the real file
    await import_sources(db, changed, None)
    names = {r["name"] for r in await db.fetch("select name from mib_objects")}
    assert names == {"sysDescr"}


async def test_cross_check_confirms_a_correct_declaration_and_flags_a_wrong_one(db):
    await import_sources(db, SOURCES, None)
    ok = await check_definition(db, "sysDescr", "1.3.6.1.2.1.1")
    assert ok == {"status": "confirmed", "name": "sysDescr", "declared_oid": "1.3.6.1.2.1.1", "mib_matches": [{"numeric_oid": "1.3.6.1.2.1.1", "filename": "a.mib"}]}
    wrong = await check_definition(db, "sysDescr", "9.9.9.9")
    assert wrong["status"] == "mismatch"
    unknown = await check_definition(db, "neverImported", "1.2.3")
    assert unknown["status"] == "not_found_in_mibs" and unknown["mib_matches"] == []


async def test_import_associates_files_with_a_vendor(db):
    vendor_id = await db.fetchval("select id from vendors where slug = 'bdcom'")
    await import_sources(db, {"b.mib": SOURCES["b.mib"]}, str(vendor_id))
    row = await db.fetchrow("select v.slug from mib_files f join vendors v on v.id = f.vendor_id where f.filename = 'b.mib'")
    assert row["slug"] == "bdcom"


async def test_the_api_lists_files_and_searches_objects(app_client, db):
    await import_sources(db, SOURCES, None)
    headers = await login_as(app_client, db, "noc", "ISP NOC")
    files = (await app_client.get("/api/v1/mib/files", headers=headers)).json()
    assert {f["filename"] for f in files} == {"a.mib", "b.mib"}
    by_name = (await app_client.get("/api/v1/mib/objects", headers=headers, params={"query": "sysDescr"})).json()
    assert len(by_name) == 1 and by_name[0]["numeric_oid"] == "1.3.6.1.2.1.1"
    by_oid = (await app_client.get("/api/v1/mib/objects", headers=headers, params={"query": "1.3.6.1.4.1.3320"})).json()
    assert len(by_oid) == 1 and by_oid[0]["name"] == "bdcomThing"
    empty = (await app_client.get("/api/v1/mib/objects", headers=headers, params={"query": "nothing-like-this"})).json()
    assert empty == []


async def test_the_api_check_endpoint_validates_a_declared_oid(app_client, db):
    await import_sources(db, SOURCES, None)
    headers = await login_as(app_client, db, "noc", "ISP NOC")
    ok = await app_client.post("/api/v1/mib/check", headers=headers, json={"name": "sysDescr", "numeric_oid": "1.3.6.1.2.1.1"})
    assert ok.status_code == 200 and ok.json()["status"] == "confirmed"
    bad = await app_client.post("/api/v1/mib/check", headers=headers, json={"name": "sysDescr", "numeric_oid": "9.9.9"})
    assert bad.json()["status"] == "mismatch"
    malformed = await app_client.post("/api/v1/mib/check", headers=headers, json={"name": "x", "numeric_oid": "not-an-oid"})
    assert malformed.status_code == 422


async def test_resellers_and_support_cannot_read_the_mib_library(app_client, db):
    for name, role in (("res", "Reseller Admin"), ("support", "ISP Support")):
        headers = await login_as(app_client, db, name, role)
        assert (await app_client.get("/api/v1/mib/files", headers=headers)).status_code == (403 if role != "ISP Support" else 200)


async def test_a_name_genuinely_declared_twice_in_one_file_is_kept_as_two_rows_not_collapsed(db):
    # A real, found-in-the-wild pattern (SNMPv2-MIB.my's sysORID, BDCOM-LLDP-MIB.MIB's lldpLocManAddrOID): a leftover
    # OBJECT IDENTIFIER stub alongside the real OBJECT-TYPE definition, each with a different OID.
    source = {"dup.mib": (
        "sysORID OBJECT IDENTIFIER ::= { enterprises 1 }\n"
        "sysORID OBJECT-TYPE\n"
        "    SYNTAX OBJECT IDENTIFIER\n"
        "    ::= { enterprises 2 }\n"
    )}
    report = await import_sources(db, source, None)
    assert report.objects == 2
    rows = await db.fetch("select numeric_oid from mib_objects where name = 'sysORID' order by numeric_oid")
    assert [r["numeric_oid"] for r in rows] == ["1.3.6.1.4.1.1", "1.3.6.1.4.1.2"]
    result = await check_definition(db, "sysORID", "1.3.6.1.4.1.2")
    assert result["status"] == "confirmed" and len(result["mib_matches"]) == 2

    reimported = await import_sources(db, source, None)  # re-importing does not accumulate duplicates further
    assert reimported.objects == 2 and await db.fetchval("select count(*) from mib_objects where name = 'sysORID'") == 2
