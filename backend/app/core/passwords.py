"""Password hashing and verification.

New hashes are argon2id. Verification also accepts the two formats the legacy system wrote, so imported accounts keep
working and are upgraded to argon2id on their next successful login:

  * bcrypt (`$2y$`, `$2a$`, `$2b$`), written by the legacy password_hash()
  * unsalted sha1, 40 hex characters, written by older legacy releases
"""
from __future__ import annotations

import hashlib
import hmac
import re
import secrets
import string

import bcrypt
from argon2 import PasswordHasher
from argon2.exceptions import InvalidHashError, VerificationError, VerifyMismatchError

_hasher = PasswordHasher()
_HEX40 = re.compile(r"^[0-9a-fA-F]{40}$")

SCHEME_ARGON2 = "argon2id"
SCHEME_BCRYPT = "bcrypt"
SCHEME_SHA1 = "legacy_sha1"
SCHEME_UNKNOWN = "unknown"


def hash_password(plain: str) -> str:
    return _hasher.hash(plain)


def scheme_of(stored: str | None) -> str:
    if not stored:
        return SCHEME_UNKNOWN
    if stored.startswith("$argon2"):
        return SCHEME_ARGON2
    if stored.startswith(("$2y$", "$2a$", "$2b$")):
        return SCHEME_BCRYPT
    if _HEX40.match(stored):
        return SCHEME_SHA1
    return SCHEME_UNKNOWN


def verify_password(stored: str | None, plain: str) -> tuple[bool, bool]:
    """Return (matches, needs_rehash). Never raises on a malformed stored value."""
    scheme = scheme_of(stored)
    if scheme == SCHEME_ARGON2:
        try:
            _hasher.verify(stored, plain)
        except (VerifyMismatchError, VerificationError, InvalidHashError):
            return False, False
        return True, _hasher.check_needs_rehash(stored)
    if scheme == SCHEME_BCRYPT:
        normalized = "$2b$" + stored[4:] if stored.startswith("$2y$") else stored
        try:
            ok = bcrypt.checkpw(plain.encode("utf-8")[:72], normalized.encode("utf-8"))
        except ValueError:
            return False, False
        return ok, ok
    if scheme == SCHEME_SHA1:
        digest = hashlib.sha1(plain.encode("utf-8")).hexdigest()
        ok = hmac.compare_digest(stored.lower(), digest)
        return ok, ok
    return False, False


_DUMMY_HASH: str | None = None


def dummy_verify(plain: str) -> None:
    """Spend the same time as a real verification, so an unknown user cannot be told apart by response time."""
    global _DUMMY_HASH
    if _DUMMY_HASH is None:
        _DUMMY_HASH = _hasher.hash("cybersathy-timing-equaliser")
    verify_password(_DUMMY_HASH, plain)


def check_strength(password: str, min_length: int) -> list[str]:
    """Return a list of problems. Empty means acceptable."""
    problems: list[str] = []
    if len(password) < min_length:
        problems.append("TOO_SHORT")
    classes = sum(
        bool(re.search(pattern, password))
        for pattern in (r"[a-z]", r"[A-Z]", r"\d", r"[^A-Za-z0-9]")
    )
    if classes < 3:
        problems.append("NEEDS_MIXED_CHARACTERS")
    return problems


GENERATED_LENGTH = 24
_ALPHABET = string.ascii_letters + string.digits + "-_"
_MAX_DRAWS = 100


def generate_password(min_length: int) -> str:
    """A random password that always passes `check_strength` for the configured minimum length.

    The routes used `secrets.token_urlsafe(18)` and then checked it, which failed about 1 time in 150 (24 characters
    with no digit, `-` or `_` have only two character classes) and failed every time once `min_length` was set above
    24. Drawing again until the result passes keeps every password uniformly random among the acceptable ones."""
    length = max(GENERATED_LENGTH, min_length)
    for _ in range(_MAX_DRAWS):
        candidate = "".join(secrets.choice(_ALPHABET) for _ in range(length))
        if not check_strength(candidate, min_length):
            return candidate
    # Unreachable with these rules (a draw fails about 1 time in 150); a rule change that made it reachable would
    # otherwise hang the request instead of failing it.
    raise RuntimeError("could not generate a password that meets the strength rules")

