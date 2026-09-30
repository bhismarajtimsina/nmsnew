"""Time-based one-time passwords (RFC 6238) on top of HOTP (RFC 4226), implemented on the standard library."""
from __future__ import annotations

import base64
import hashlib
import hmac
import os
import struct
import time
from urllib.parse import quote


def generate_secret() -> str:
    return base64.b32encode(os.urandom(20)).decode("ascii").rstrip("=")


def _key(secret: str) -> bytes:
    return base64.b32decode(secret.upper() + "=" * (-len(secret) % 8))


def hotp(secret: str, counter: int, digits: int = 6) -> str:
    digest = hmac.new(_key(secret), struct.pack(">Q", counter), hashlib.sha1).digest()
    offset = digest[-1] & 0x0F
    code = (struct.unpack(">I", digest[offset : offset + 4])[0] & 0x7FFFFFFF) % (10**digits)
    return str(code).zfill(digits)


def counter_at(timestamp: float | None = None, step: int = 30) -> int:
    return int((time.time() if timestamp is None else timestamp) // step)


def totp_at(secret: str, timestamp: float | None = None, step: int = 30, digits: int = 6) -> str:
    return hotp(secret, counter_at(timestamp, step), digits)


def verify(
    secret: str,
    pin: str,
    *,
    timestamp: float | None = None,
    step: int = 30,
    digits: int = 6,
    window: int = 1,
    last_counter: int | None = None,
) -> int | None:
    """Return the matched counter, or None. A counter at or before `last_counter` is refused (no replay)."""
    pin = (pin or "").strip().replace(" ", "")
    if len(pin) != digits or not pin.isdigit():
        return None
    now = counter_at(timestamp, step)
    matched: int | None = None
    for candidate in range(now - window, now + window + 1):
        if hmac.compare_digest(hotp(secret, candidate, digits), pin) and matched is None:
            matched = candidate
    if matched is None or (last_counter is not None and matched <= last_counter):
        return None
    return matched


def otpauth_uri(secret: str, account: str, issuer: str) -> str:
    return f"otpauth://totp/{quote(issuer)}:{quote(account)}?secret={secret}&issuer={quote(issuer)}&digits=6&period=30"
