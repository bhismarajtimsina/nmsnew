"""Encryption of secrets at rest (device credentials, TOTP secrets, integration keys).

AES-256-GCM. Every ciphertext records the id of the key that produced it, so keys can be rotated: the active key
encrypts, every configured key can decrypt, and `reencrypt` moves a value onto the active key.

Format:  v1:<key_id>:<urlsafe-base64(nonce || ciphertext+tag)>
"""
from __future__ import annotations

import base64
import os

from cryptography.exceptions import InvalidTag
from cryptography.hazmat.primitives.ciphers.aead import AESGCM

from app.core.config import settings


class EncryptionNotConfigured(RuntimeError):
    pass


class DecryptionError(ValueError):
    pass


def generate_key() -> str:
    return base64.urlsafe_b64encode(os.urandom(32)).decode("ascii")


def parse_keys(spec: str) -> dict[str, bytes]:
    keys: dict[str, bytes] = {}
    for item in spec.split(","):
        item = item.strip()
        if not item:
            continue
        key_id, sep, encoded = item.partition(":")
        if not sep or not key_id:
            raise ValueError("ENCRYPTION_KEYS entries must look like key_id:base64key")
        raw = base64.urlsafe_b64decode(encoded + "=" * (-len(encoded) % 4))
        if len(raw) != 32:
            raise ValueError(f"encryption key {key_id!r} must be 32 bytes")
        keys[key_id] = raw
    return keys


class EncryptionService:
    def __init__(self, keys: dict[str, bytes], active_key_id: str) -> None:
        if active_key_id not in keys:
            raise EncryptionNotConfigured("the active encryption key id is not among the configured keys")
        self._keys = keys
        self.active_key_id = active_key_id

    @classmethod
    def from_settings(cls) -> "EncryptionService":
        if not settings.encryption_keys or not settings.encryption_active_key_id:
            raise EncryptionNotConfigured("ENCRYPTION_KEYS and ENCRYPTION_ACTIVE_KEY_ID must be set")
        return cls(parse_keys(settings.encryption_keys), settings.encryption_active_key_id)

    def encrypt(self, plaintext: str, aad: str = "") -> str:
        nonce = os.urandom(12)
        ciphertext = AESGCM(self._keys[self.active_key_id]).encrypt(nonce, plaintext.encode("utf-8"), aad.encode("utf-8"))
        blob = base64.urlsafe_b64encode(nonce + ciphertext).decode("ascii")
        return f"v1:{self.active_key_id}:{blob}"

    def decrypt(self, token: str, aad: str = "") -> str:
        try:
            version, key_id, blob = token.split(":", 2)
            if version != "v1" or key_id not in self._keys:
                raise DecryptionError("unknown ciphertext version or key id")
            raw = base64.urlsafe_b64decode(blob + "=" * (-len(blob) % 4))
            plaintext = AESGCM(self._keys[key_id]).decrypt(raw[:12], raw[12:], aad.encode("utf-8"))
        except (InvalidTag, ValueError) as exc:
            # Never include the token or the key material in the message.
            raise DecryptionError("could not decrypt value") from exc
        return plaintext.decode("utf-8")

    def needs_rotation(self, token: str) -> bool:
        parts = token.split(":", 2)
        return len(parts) != 3 or parts[1] != self.active_key_id

    def reencrypt(self, token: str, aad: str = "") -> str:
        return self.encrypt(self.decrypt(token, aad), aad)
