import hashlib

import bcrypt

from app.core.passwords import (
    SCHEME_ARGON2,
    SCHEME_BCRYPT,
    SCHEME_SHA1,
    SCHEME_UNKNOWN,
    check_strength,
    hash_password,
    scheme_of,
    verify_password,
)


def test_new_hashes_are_argon2id_and_verify():
    stored = hash_password("Correct-Horse-Battery-9")
    assert scheme_of(stored) == SCHEME_ARGON2 and stored.startswith("$argon2id$")
    assert verify_password(stored, "Correct-Horse-Battery-9") == (True, False)
    assert verify_password(stored, "wrong") == (False, False)


def test_two_hashes_of_one_password_differ():
    assert hash_password("same-password-1A!") != hash_password("same-password-1A!")


def test_legacy_php_bcrypt_hash_verifies_and_asks_for_rehash():
    # PHP writes $2y$; the bcrypt library writes $2b$. They are the same algorithm, so mimic PHP's prefix.
    php_style = "$2y$" + bcrypt.hashpw(b"old-secret", bcrypt.gensalt(rounds=4)).decode()[4:]
    assert scheme_of(php_style) == SCHEME_BCRYPT
    assert verify_password(php_style, "old-secret") == (True, True)
    assert verify_password(php_style, "nope") == (False, False)


def test_legacy_unsalted_sha1_verifies_and_asks_for_rehash():
    legacy = hashlib.sha1(b"ancient").hexdigest()
    assert scheme_of(legacy) == SCHEME_SHA1
    assert verify_password(legacy, "ancient") == (True, True)
    assert verify_password(legacy.upper(), "ancient") == (True, True)
    assert verify_password(legacy, "other") == (False, False)


def test_malformed_or_empty_stored_values_never_raise_and_never_match():
    for stored in (None, "", "garbage", "$2y$broken", "$argon2id$nonsense", "z" * 40):
        assert verify_password(stored, "anything") == (False, False)
    assert scheme_of("garbage") == SCHEME_UNKNOWN


def test_strength_rules():
    assert check_strength("Short1!", 12) == ["TOO_SHORT"]
    assert "NEEDS_MIXED_CHARACTERS" in check_strength("alllowercaseletters", 12)
    assert check_strength("Correct-Horse-Battery-9", 12) == []


def test_generated_passwords_always_pass_the_strength_rules(monkeypatch):
    """The old `token_urlsafe(18)` failed about 1 time in 150, so user creation randomly answered 422."""
    from app.core import passwords

    for min_length in (8, 12, 24, 40):
        for _ in range(500):
            generated = passwords.generate_password(min_length)
            assert passwords.check_strength(generated, min_length) == []
            assert len(generated) == max(24, min_length)


def test_a_weak_draw_is_drawn_again_not_returned(monkeypatch):
    from app.core import passwords

    draws = iter("a" * 24 + "Ab1" + "c" * 21)  # first draw: one character class only; second: three classes
    monkeypatch.setattr(passwords.secrets, "choice", lambda alphabet: next(draws))
    assert passwords.generate_password(12) == "Ab1" + "c" * 21


def test_rules_that_can_never_be_met_fail_instead_of_hanging(monkeypatch):
    import pytest

    from app.core import passwords

    monkeypatch.setattr(passwords, "check_strength", lambda password, min_length: ["NEVER"])
    with pytest.raises(RuntimeError):
        passwords.generate_password(12)
