import pytest

from app.core.crypto import DecryptionError, EncryptionNotConfigured, EncryptionService, generate_key, parse_keys


def service(active="k1", **extra):
    keys = {"k1": bytes(range(32)), **extra}
    return EncryptionService(keys, active)


def test_round_trip_and_format():
    token = service().encrypt("public-community")
    assert token.startswith("v1:k1:") and "public-community" not in token
    assert service().decrypt(token) == "public-community"


def test_every_encryption_uses_a_fresh_nonce():
    assert service().encrypt("x") != service().encrypt("x")


def test_ciphertext_is_bound_to_its_context():
    token = service().encrypt("secret", aad="user-1")
    assert service().decrypt(token, aad="user-1") == "secret"
    with pytest.raises(DecryptionError):
        service().decrypt(token, aad="user-2")


def test_tampering_and_wrong_keys_are_detected_without_leaking_material():
    token = service().encrypt("secret")
    tampered = token[:-4] + ("AAAA" if not token.endswith("AAAA") else "BBBB")
    with pytest.raises(DecryptionError) as raised:
        service().decrypt(tampered)
    assert "secret" not in str(raised.value)
    with pytest.raises(DecryptionError):
        EncryptionService({"k1": bytes(reversed(range(32)))}, "k1").decrypt(token)
    with pytest.raises(DecryptionError):
        service().decrypt("not-a-token")


def test_rotation_reads_old_keys_and_writes_new_ones():
    old = service()
    token = old.encrypt("secret")
    rotated = service(active="k2", k2=bytes([7] * 32))
    assert rotated.needs_rotation(token)
    assert rotated.decrypt(token) == "secret"
    moved = rotated.reencrypt(token)
    assert moved.startswith("v1:k2:") and not rotated.needs_rotation(moved)


def test_key_parsing_and_configuration_errors():
    key = generate_key()
    assert parse_keys(f"a:{key},b:{generate_key()}").keys() == {"a", "b"}
    with pytest.raises(ValueError):
        parse_keys("nokeyid")
    with pytest.raises(ValueError):
        parse_keys("a:" + "c2hvcnQ")
    with pytest.raises(EncryptionNotConfigured):
        EncryptionService({"k1": bytes(32)}, "missing")
