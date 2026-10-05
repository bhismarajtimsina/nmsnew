from app.core.audit import redact, write_audit


def test_sensitive_fields_are_redacted_at_every_depth():
    payload = {
        "username": "alice",
        "password": "hunter2",
        "new_password": "hunter3",
        "snmp_community_enc": "v1:k:cipher",
        "api_token": "cst_abc",
        "nested": {"totp_secret": "JBSWY3DP", "keep": "visible", "items": [{"auth_key": "k", "name": "n"}]},
        "recovery_code": "aaaa-bbbb",
        "twofa_pin": "123456",
        "password_hash": "$argon2id$...",
    }
    clean = redact(payload)
    assert clean["username"] == "alice" and clean["nested"]["keep"] == "visible" and clean["nested"]["items"][0]["name"] == "n"
    text = str(clean)
    for secret in ("hunter2", "hunter3", "cipher", "cst_abc", "JBSWY3DP", "aaaa-bbbb", "123456", "argon2id"):
        assert secret not in text
    assert clean["password"] == clean["nested"]["totp_secret"] == "[redacted]"


def test_redaction_does_not_mutate_its_input_and_leaves_ordinary_fields_alone():
    original = {"password": "x", "role": "ISP Admin", "permissions": ["devices.view"]}
    assert redact(original)["role"] == "ISP Admin" and redact(original)["permissions"] == ["devices.view"]
    assert original["password"] == "x"


async def test_the_audit_writer_redacts_before_storing(db):
    await write_audit(
        db, action="test.event", before={"password": "old-secret", "name": "a"}, after={"snmp_community": "public-community"},
        metadata={"token": "cst_leak", "note": "fine"},
    )
    row = await db.fetchrow("select before::text as b, after::text as a, metadata::text as m from audit_logs where action = 'test.event'")
    joined = row["b"] + row["a"] + row["m"]
    assert "old-secret" not in joined and "public-community" not in joined and "cst_leak" not in joined
    assert '"name": "a"' in row["b"] and "fine" in row["m"]


async def test_timestamps_and_other_non_json_values_are_stored_as_text(db):
    import datetime

    await write_audit(db, action="test.time", after={"when": datetime.datetime(2026, 9, 29, 12, 0)})
    assert "2026-09-29" in await db.fetchval("select after::text from audit_logs where action = 'test.time'")


def test_plural_keys_holding_several_secrets_are_redacted_too():
    """Found 2026-10-05: `tokens`, `passwords` and `communities` used to pass through unredacted."""
    payload = {"tokens": ["a", "b"], "passwords": ["x"], "snmp_communities": ["public"], "api_keys": ["k"],
               "recovery_codes": ["1"], "secrets": {"x": 1}, "hashes": ["h"], "pins": ["0000"],
               "tokenizer": "kept", "pinned": True, "codec": "kept", "description": "kept"}
    clean = redact(payload)
    assert all(clean[k] == "[redacted]" for k in ("tokens", "passwords", "snmp_communities", "api_keys", "recovery_codes", "secrets", "hashes", "pins"))
    assert clean["tokenizer"] == "kept" and clean["pinned"] is True and clean["codec"] == "kept" and clean["description"] == "kept"
