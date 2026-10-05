import asyncpg
import pytest

from app.core.config import settings
from app.core.crypto import DecryptionError, EncryptionService
from app.repositories.access_profiles import aad
from tests.helpers import bearer, make_device, make_user

CANARY = "CANARY-community-9zQ7"
V3 = {"name": "v3", "snmp_version": "v3", "snmp_v3_username": "monitor", "snmp_v3_auth_protocol": "SHA256",
      "snmp_v3_auth_secret": "AuthSecret-CANARY-1", "snmp_v3_priv_protocol": "AES", "snmp_v3_priv_secret": "PrivSecret-CANARY-2"}


async def admin(app_client, db, role="ISP Admin"):
    await make_user(db, "boss", role)
    headers = await bearer(app_client, "boss")
    app_client.cookies.clear()
    return headers


async def test_a_community_is_stored_encrypted_and_never_returned_or_logged(app_client, db):
    headers = await admin(app_client, db)
    created = await app_client.post("/api/v1/device-access-profiles", headers=headers, json={"name": "v2c", "snmp_version": "v2c", "snmp_community": CANARY})
    assert created.status_code == 201
    body = created.json()
    assert body["has_community"] is True and CANARY not in created.text and "snmp_community" not in body
    stored = await db.fetchrow("select snmp_community_enc from device_access_profiles")
    assert stored["snmp_community_enc"].startswith("v1:test1:") and CANARY not in stored["snmp_community_enc"]
    for view in (await app_client.get("/api/v1/device-access-profiles", headers=headers), await app_client.get(f"/api/v1/device-access-profiles/{body['id']}", headers=headers)):
        assert CANARY not in view.text
    everything = await db.fetchval("select string_agg(t::text, ' ') from audit_logs t")
    assert CANARY not in (everything or "")
    assert await db.fetchval("select count(*) from audit_logs where action = 'access_profile.created'") == 1


async def test_every_v3_secret_is_encrypted_and_hidden(app_client, db):
    headers = await admin(app_client, db)
    created = await app_client.post("/api/v1/device-access-profiles", headers=headers, json=V3)
    assert created.status_code == 201
    assert "CANARY" not in created.text and created.json()["has_auth_secret"] and created.json()["has_priv_secret"]
    row = await db.fetchrow("select snmp_v3_auth_secret_enc a, snmp_v3_priv_secret_enc p from device_access_profiles")
    assert row["a"].startswith("v1:") and row["p"].startswith("v1:") and "CANARY" not in row["a"] + row["p"]
    audit = await db.fetchval("select string_agg(after::text || metadata::text, ' ') from audit_logs")
    assert "CANARY" not in audit


async def test_a_ciphertext_moved_to_another_profile_or_field_does_not_decrypt(app_client, db):
    headers = await admin(app_client, db)
    a = (await app_client.post("/api/v1/device-access-profiles", headers=headers, json={"name": "a", "snmp_version": "v2c", "snmp_community": "alpha-community"})).json()
    b = (await app_client.post("/api/v1/device-access-profiles", headers=headers, json={"name": "b", "snmp_version": "v2c", "snmp_community": "bravo-community"})).json()
    cipher = await db.fetchval("select snmp_community_enc from device_access_profiles where id = $1::uuid", a["id"])
    enc = EncryptionService.from_settings()
    assert enc.decrypt(cipher, aad(a["id"], "snmp_community")) == "alpha-community"
    with pytest.raises(DecryptionError):
        enc.decrypt(cipher, aad(b["id"], "snmp_community"))
    with pytest.raises(DecryptionError):
        enc.decrypt(cipher, aad(a["id"], "snmp_v3_auth_secret"))


@pytest.mark.parametrize("body", [
    {"name": "x", "snmp_version": "v2c"},                                           # no community
    {"name": "x", "snmp_version": "v2c", "snmp_community": "   "},                  # blank community
    {"name": "x", "snmp_version": "v1"},
    {"name": "x", "snmp_version": "v3"},                                            # nothing for v3
    {**V3, "snmp_v3_priv_secret": "short"},                                          # secret under 8 characters
    {**V3, "snmp_v3_auth_protocol": "ROT13"},
    {"name": "x", "snmp_version": "v4", "snmp_community": "c"},
    {"name": "x", "snmp_version": "v2c", "snmp_community": "c", "timeout_ms": 50},
    {"name": "x", "snmp_version": "v2c", "snmp_community": "c", "retries": 9},
])
async def test_incomplete_or_out_of_range_profiles_are_refused(app_client, db, body):
    headers = await admin(app_client, db)
    assert (await app_client.post("/api/v1/device-access-profiles", headers=headers, json=body)).status_code == 422


async def test_the_database_refuses_an_incomplete_profile_even_when_the_api_is_bypassed(db):
    with pytest.raises(asyncpg.CheckViolationError):
        await db.execute("insert into device_access_profiles (name, snmp_version) values ('naked', 'v2c')")
    with pytest.raises(asyncpg.CheckViolationError):
        await db.execute("insert into device_access_profiles (name, snmp_version, snmp_v3_username) values ('half', 'v3', 'u')")
    with pytest.raises(asyncpg.CheckViolationError):
        await db.execute("insert into device_access_profiles (name, snmp_version, snmp_community_enc, timeout_ms) values ('slow', 'v2c', 'v1:k:x', 999999)")


async def test_secrets_can_be_rotated_but_never_read_back(app_client, db):
    headers = await admin(app_client, db)
    made = (await app_client.post("/api/v1/device-access-profiles", headers=headers, json={"name": "v2c", "snmp_version": "v2c", "snmp_community": "first-community"})).json()
    before = await db.fetchval("select snmp_community_enc from device_access_profiles")
    rotated = await app_client.patch(f"/api/v1/device-access-profiles/{made['id']}", headers=headers, json={"snmp_community": "second-community", "timeout_ms": 3000})
    assert rotated.status_code == 200 and "second-community" not in rotated.text and rotated.json()["timeout_ms"] == 3000
    after = await db.fetchval("select snmp_community_enc from device_access_profiles")
    assert after != before
    assert EncryptionService.from_settings().decrypt(after, aad(made["id"], "snmp_community")) == "second-community"
    audit = await db.fetchrow("select before::text b, after::text a, metadata::text m from audit_logs where action = 'access_profile.updated'")
    assert "second-community" not in audit["b"] + audit["a"] + audit["m"] and "snmp_community" in audit["m"]


async def test_profile_rules_on_update_delete_and_names(app_client, db):
    headers = await admin(app_client, db)
    v2c = (await app_client.post("/api/v1/device-access-profiles", headers=headers, json={"name": "v2c", "snmp_version": "v2c", "snmp_community": "c1"})).json()
    v3 = (await app_client.post("/api/v1/device-access-profiles", headers=headers, json=V3)).json()
    assert (await app_client.post("/api/v1/device-access-profiles", headers=headers, json={"name": "v2c", "snmp_version": "v2c", "snmp_community": "c"})).status_code == 409
    assert (await app_client.patch(f"/api/v1/device-access-profiles/{v2c['id']}", headers=headers, json={"snmp_v3_username": "x"})).status_code == 422
    assert (await app_client.patch(f"/api/v1/device-access-profiles/{v3['id']}", headers=headers, json={"snmp_community": "x"})).status_code == 422
    assert (await app_client.patch(f"/api/v1/device-access-profiles/{v3['id']}", headers=headers, json={"name": "v2c"})).status_code == 409
    device = await make_device(db, "sw1")
    await db.execute("update devices set access_profile_id = $1::uuid", v2c["id"])
    used = await app_client.delete(f"/api/v1/device-access-profiles/{v2c['id']}", headers=headers)
    assert used.status_code == 409 and "1 device" in used.json()["detail"]
    await db.execute("update devices set access_profile_id = null where id = $1::uuid", device)
    assert (await app_client.delete(f"/api/v1/device-access-profiles/{v2c['id']}", headers=headers)).status_code == 200
    assert (await app_client.get(f"/api/v1/device-access-profiles/{v2c['id']}", headers=headers)).status_code == 404
    assert (await app_client.get("/api/v1/device-access-profiles/not-a-uuid", headers=headers)).status_code == 404


async def test_only_roles_that_manage_access_may_touch_profiles(app_client, db):
    for name, role in (("noc", "ISP NOC"), ("support", "ISP Support"), ("reseller", "Reseller Admin"), ("admin", "ISP Admin"), ("root", "Super Admin")):
        await make_user(db, name, role)
    tokens = {n: await bearer(app_client, n) for n in ("noc", "support", "reseller", "admin", "root")}
    app_client.cookies.clear()
    body = {"name": "p", "snmp_version": "v2c", "snmp_community": "c"}
    for n in ("noc", "support", "reseller"):
        assert (await app_client.get("/api/v1/device-access-profiles", headers=tokens[n])).status_code == 403, n
        assert (await app_client.post("/api/v1/device-access-profiles", headers=tokens[n], json=body)).status_code == 403, n
    assert (await app_client.post("/api/v1/device-access-profiles", headers=tokens["admin"], json=body)).status_code == 201
    assert (await app_client.get("/api/v1/device-access-profiles", headers=tokens["root"])).status_code == 200


async def test_credentials_cannot_be_stored_when_encryption_is_not_configured(app_client, db, monkeypatch):
    headers = await admin(app_client, db)
    monkeypatch.setattr(settings, "encryption_keys", None)
    response = await app_client.post("/api/v1/device-access-profiles", headers=headers, json={"name": "p", "snmp_version": "v2c", "snmp_community": "c"})
    assert response.status_code == 503 and "encryption is not configured" in response.json()["detail"]
    assert await db.fetchval("select count(*) from device_access_profiles") == 0


async def test_a_write_community_is_stored_encrypted_never_returned_and_refused_on_v3(app_client, db):
    headers = await admin(app_client, db)
    made = await app_client.post("/api/v1/device-access-profiles", headers=headers,
                                 json={"name": "rw", "snmp_version": "v2c", "snmp_community": "ro", "snmp_write_community": "rw-secret-1"})
    assert made.status_code in (200, 201), made.text
    assert made.json()["has_write_community"] is True and "rw-secret-1" not in made.text
    stored = await db.fetchval("select snmp_write_community_enc from device_access_profiles where name = 'rw'")
    assert stored and "rw-secret-1" not in stored
    plain = await app_client.post("/api/v1/device-access-profiles", headers=headers, json={"name": "ro", "snmp_version": "v2c", "snmp_community": "ro"})
    assert plain.json()["has_write_community"] is False
    rotated = await app_client.patch(f"/api/v1/device-access-profiles/{plain.json()['id']}", headers=headers, json={"snmp_write_community": "rw-2"})
    assert rotated.json()["has_write_community"] is True and "rw-2" not in rotated.text
    v3 = await app_client.post("/api/v1/device-access-profiles", headers=headers, json={**V3, "name": "v3rw", "snmp_write_community": "x"})
    assert v3.status_code == 422
    v3ok = (await app_client.post("/api/v1/device-access-profiles", headers=headers, json={**V3, "name": "v3ok"})).json()
    assert (await app_client.patch(f"/api/v1/device-access-profiles/{v3ok['id']}", headers=headers, json={"snmp_write_community": "x"})).status_code == 422
    audit = await db.fetch("select before::text b, after::text a, metadata::text m from audit_logs where action like 'access_profile.%'")
    assert not any("rw-secret-1" in (r["b"] or "") + (r["a"] or "") + r["m"] or "rw-2" in (r["b"] or "") + (r["a"] or "") + r["m"] for r in audit)
