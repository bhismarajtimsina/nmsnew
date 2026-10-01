"""Encryption key rotation (Plan 36): which key protects each stored secret, and moving them onto the active key.

The procedure is in docs/cybersathy-nms-migration/key-rotation-runbook.md. In short: add a new key alongside the old
one, make it active, run `reencrypt`, confirm with `status` that nothing still uses the old key, then remove it.

`ENCRYPTED_COLUMNS` is the single list of every column holding a ciphertext, with the additional authenticated data
(AAD) each was encrypted with. tests/test_key_rotation.py checks that every `*_enc` column in the live schema is on
this list, so a new encrypted column cannot be silently left out of a rotation.

Nothing here logs, prints or returns a secret, plaintext or ciphertext: only row ids and counts.
"""
from __future__ import annotations

from collections import Counter
from dataclasses import dataclass, field
from typing import Callable

import asyncpg

from app.core.crypto import DecryptionError, EncryptionService


@dataclass(frozen=True)
class EncryptedColumn:
    table: str
    column: str
    aad: Callable[[str], str]  # row id -> the AAD the value was encrypted with

    @property
    def label(self) -> str:
        return f"{self.table}.{self.column}"


def _profile_aad(field_name: str) -> Callable[[str], str]:
    # Must stay identical to app/repositories/access_profiles.py's aad().
    return lambda row_id: f"device_access_profile:{row_id}:{field_name}"


ENCRYPTED_COLUMNS: tuple[EncryptedColumn, ...] = (
    EncryptedColumn("device_access_profiles", "snmp_community_enc", _profile_aad("snmp_community")),
    EncryptedColumn("device_access_profiles", "snmp_v3_auth_secret_enc", _profile_aad("snmp_v3_auth_secret")),
    EncryptedColumn("device_access_profiles", "snmp_v3_priv_secret_enc", _profile_aad("snmp_v3_priv_secret")),
    # app/api/auth.py encrypts the TOTP secret with the bare user id as its AAD.
    EncryptedColumn("users", "totp_secret_enc", lambda row_id: row_id),
)


def key_id_of(token: str) -> str:
    """The key id a ciphertext names, read from its `v1:<key_id>:...` prefix without decrypting anything."""
    parts = token.split(":", 2)
    return parts[1] if len(parts) == 3 and parts[0] == "v1" else "(unrecognized format)"


async def key_usage(conn: asyncpg.Connection) -> dict[str, Counter]:
    """For each encrypted column, how many stored values each key id protects. An old key may be removed from
    ENCRYPTION_KEYS only once it appears nowhere here."""
    usage: dict[str, Counter] = {}
    for col in ENCRYPTED_COLUMNS:
        rows = await conn.fetch(f"select {col.column} as v from {col.table} where {col.column} is not null")
        usage[col.label] = Counter(key_id_of(r["v"]) for r in rows)
    return usage


@dataclass
class ColumnReport:
    total: int = 0
    already_active: int = 0
    reencrypted: int = 0
    changed_concurrently: int = 0
    failed_ids: list[str] = field(default_factory=list)  # could not be decrypted with any configured key


@dataclass
class RotationReport:
    dry_run: bool
    columns: dict[str, ColumnReport] = field(default_factory=dict)

    @property
    def failed(self) -> int:
        return sum(len(c.failed_ids) for c in self.columns.values())

    @property
    def pending(self) -> int:
        """Values still not on the active key after this run (including any a dry run would move)."""
        return sum(c.total - c.already_active - c.reencrypted for c in self.columns.values())


async def reencrypt_all(conn: asyncpg.Connection, enc: EncryptionService, *, dry_run: bool = False) -> RotationReport:
    """Moves every stored secret that is not on the active key onto it.

    Each value is decrypted with whichever configured key it names, checked against its own AAD, and re-encrypted
    with the active key. A value that cannot be decrypted is left exactly as it is and reported by row id; the run
    carries on with the rest. Every update is conditional on the stored value being unchanged since it was read, so
    a credential an operator edits mid-run is never overwritten with an older one. Safe to run repeatedly."""
    report = RotationReport(dry_run=dry_run)
    for col in ENCRYPTED_COLUMNS:
        result = report.columns[col.label] = ColumnReport()
        rows = await conn.fetch(f"select id, {col.column} as v from {col.table} where {col.column} is not null order by id")
        result.total = len(rows)
        for row in rows:
            row_id, token = str(row["id"]), row["v"]
            if not enc.needs_rotation(token):
                result.already_active += 1
                continue
            try:
                fresh = enc.reencrypt(token, col.aad(row_id))
            except DecryptionError:
                result.failed_ids.append(row_id)
                continue
            if dry_run:
                continue
            status = await conn.execute(
                f"update {col.table} set {col.column} = $1 where id = $2::uuid and {col.column} = $3",
                fresh, row_id, token,
            )
            if status.endswith(" 0"):
                result.changed_concurrently += 1
            else:
                result.reencrypted += 1
    return report
