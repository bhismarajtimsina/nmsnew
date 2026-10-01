"""Encryption key rotation (Plan 36): `crypto status` and `crypto reencrypt`. Secrets are created through the real
code paths (the access-profile repository, the 2FA enrollment API) so that a rotated value is proven readable by the
code that reads it, with the AAD that code uses - not just by this module."""
import base64
import os
import time

from app import cli
from app.core import totp
from app.core.config import settings
from app.core.crypto import EncryptionService, parse_keys
from app.core.rotation import ENCRYPTED_COLUMNS, key_id_of, key_usage, reencrypt_all
from app.repositories import access_profiles
from tests.helpers import bearer, login, make_user

OLD_SPEC = os.environ["ENCRYPTION_KEYS"]  # the test suite's own key, id "test1"
NEW_KEY = "test2:" + base64.urlsafe_b64encode(os.urandom(32)).decode("ascii")
STRANGER = EncryptionService(parse_keys("gone:" + base64.urlsafe_b64encode(os.urandom(32)).decode("ascii")), "gone")


def old() -> EncryptionService:
    return EncryptionService(parse_keys(OLD_SPEC), "test1")


def rotated() -> EncryptionService:
    """Both keys configured, the new one active: step 2 of the runbook."""
    return EncryptionService(parse_keys(f"{OLD_SPEC},{NEW_KEY}"), "test2")


async def make_profile(db, name="p1", community="s3cret-community"):
    return await access_profiles.create_profile(db, old(), {
        "name": name, "snmp_version": "v2c", "snmp_community": community, "timeout_ms": 2000, "retries": 1})


async def community_of(db, profile_id, enc):
    token = await db.fetchval("select snmp_community_enc from device_access_profiles where id = $1::uuid", profile_id)
    return token, enc.decrypt(token, access_profiles.aad(profile_id, "snmp_community"))


async def test_every_encrypted_column_in_the_schema_is_covered_by_rotation(db):
    """A new `*_enc` column that is not on ENCRYPTED_COLUMNS would be silently skipped by every future rotation and
    then become unreadable when the old key is removed."""
    live = {f"{r['table_name']}.{r['column_name']}" for r in await db.fetch(
        "select table_name, column_name from information_schema.columns "
        "where table_schema = 'public' and column_name like '%\\_enc'")}
    assert live == {c.label for c in ENCRYPTED_COLUMNS}


async def test_reencrypt_moves_values_onto_the_active_key_and_they_still_decrypt(db):
    profile = await make_profile(db)
    report = await reencrypt_all(db, rotated())
    assert report.columns["device_access_profiles.snmp_community_enc"].reencrypted == 1
    token, plaintext = await community_of(db, profile, rotated())
    assert key_id_of(token) == "test2" and plaintext == "s3cret-community"
    assert "s3cret" not in token


async def test_a_rotated_totp_secret_still_works_for_a_real_2fa_login(app_client, db, monkeypatch):
    """Proves the TOTP column's AAD matches what app/api/auth.py uses, end to end."""
    await make_user(db, "alice", "Super Admin")
    headers = await bearer(app_client, "alice")
    app_client.cookies.clear()
    secret = (await app_client.post("/api/v1/auth/2fa/enroll", headers=headers)).json()["secret"]
    assert (await app_client.post("/api/v1/auth/2fa/enable", headers=headers,
                                  json={"pin": totp.totp_at(secret)})).status_code == 200

    report = await reencrypt_all(db, rotated())
    assert report.columns["users.totp_secret_enc"].reencrypted == 1 and report.failed == 0
    monkeypatch.setattr(settings, "encryption_keys", NEW_KEY)  # the old key is gone entirely: step 4 of the runbook
    monkeypatch.setattr(settings, "encryption_active_key_id", "test2")
    response = await login(app_client, "alice", twofa_pin=totp.totp_at(secret, time.time() + 30))
    assert response.status_code == 200, response.text


async def test_a_dry_run_writes_nothing(db):
    profile = await make_profile(db)
    before = await db.fetchval("select snmp_community_enc from device_access_profiles where id = $1::uuid", profile)
    report = await reencrypt_all(db, rotated(), dry_run=True)
    assert report.pending == 1 and report.columns["device_access_profiles.snmp_community_enc"].reencrypted == 0
    assert await db.fetchval("select snmp_community_enc from device_access_profiles where id = $1::uuid", profile) == before


async def test_running_it_again_changes_nothing(db):
    await make_profile(db)
    await reencrypt_all(db, rotated())
    again = await reencrypt_all(db, rotated())
    col = again.columns["device_access_profiles.snmp_community_enc"]
    assert (col.already_active, col.reencrypted) == (1, 0)


async def test_an_undecryptable_value_is_reported_left_alone_and_does_not_stop_the_rest(db):
    good = await make_profile(db, "good")
    bad = await make_profile(db, "bad")
    orphan = STRANGER.encrypt("x", access_profiles.aad(bad, "snmp_community"))
    await db.execute("update device_access_profiles set snmp_community_enc = $2 where id = $1::uuid", bad, orphan)
    report = await reencrypt_all(db, rotated())
    col = report.columns["device_access_profiles.snmp_community_enc"]
    assert col.failed_ids == [bad] and col.reencrypted == 1 and report.failed == 1
    assert await db.fetchval("select snmp_community_enc from device_access_profiles where id = $1::uuid", bad) == orphan
    assert key_id_of((await community_of(db, good, rotated()))[0]) == "test2"


async def test_a_value_copied_to_another_row_is_refused_not_rotated(db):
    """AAD binds a ciphertext to its row: rotation must not launder a value moved between profiles."""
    a = await make_profile(db, "a", "community-a")
    b = await make_profile(db, "b", "community-b")
    stolen = await db.fetchval("select snmp_community_enc from device_access_profiles where id = $1::uuid", a)
    await db.execute("update device_access_profiles set snmp_community_enc = $2 where id = $1::uuid", b, stolen)
    report = await reencrypt_all(db, rotated())
    assert report.columns["device_access_profiles.snmp_community_enc"].failed_ids == [b]


async def test_a_credential_edited_during_the_run_is_never_overwritten(db):
    profile = await make_profile(db)
    edited = old().encrypt("edited-by-operator", access_profiles.aad(profile, "snmp_community"))

    class EditsFirst:
        """The real connection, except an operator saves a new community just before the rotation's own write."""
        def __init__(self, conn):
            self.conn = conn

        async def fetch(self, *args):
            return await self.conn.fetch(*args)

        async def execute(self, sql, *args):
            if sql.startswith("update device_access_profiles"):
                await self.conn.execute("update device_access_profiles set snmp_community_enc = $2 where id = $1::uuid",
                                        profile, edited)
            return await self.conn.execute(sql, *args)

    report = await reencrypt_all(EditsFirst(db), rotated())
    assert report.columns["device_access_profiles.snmp_community_enc"].changed_concurrently == 1
    assert (await community_of(db, profile, rotated()))[1] == "edited-by-operator"


async def test_key_usage_counts_values_per_key_without_decrypting(db):
    await make_profile(db, "a")
    await make_profile(db, "b")
    assert (await key_usage(db))["device_access_profiles.snmp_community_enc"] == {"test1": 2}


async def test_the_cli_reports_stale_values_then_clean_after_reencrypt(db, monkeypatch, capsys):
    await make_profile(db)
    monkeypatch.setattr(settings, "encryption_keys", f"{OLD_SPEC},{NEW_KEY}")
    monkeypatch.setattr(settings, "encryption_active_key_id", "test2")
    assert await cli._crypto_status() == 2
    assert await cli._crypto_reencrypt(apply=False) == 0
    assert await cli._crypto_status() == 2  # the dry run wrote nothing
    assert await cli._crypto_reencrypt(apply=True) == 0
    assert await cli._crypto_status() == 0
    out = capsys.readouterr()
    assert "s3cret" not in out.out + out.err  # never prints a secret


async def test_the_cli_fails_when_a_value_cannot_be_decrypted(db, monkeypatch):
    profile = await make_profile(db)
    await db.execute("update device_access_profiles set snmp_community_enc = $2 where id = $1::uuid",
                     profile, STRANGER.encrypt("x", access_profiles.aad(profile, "snmp_community")))
    assert await cli._crypto_reencrypt(apply=True) == 1
