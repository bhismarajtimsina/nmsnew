"""Administration commands:  python -m app.cli <command>

  db upgrade                   apply migrations up to head
  db check                     compare the live schema with the committed snapshot
  db snapshot [--write]        print (or write) the current schema snapshot
  seed                         insert permissions, roles and the first admin (idempotent)
  users set-password <name>    set a user's password (prompts, or reads CYBERSATHY_NEW_PASSWORD)
  sessions cleanup             delete expired and long-revoked sessions and old login attempts
  devices import <file.csv>    validate a CSV of devices; add --apply to create them (polling stays off)
  mib import <dir> [--vendor]  read every file in a directory as MIB text and index its objects (read-only, offline)
  crypto generate-key          print a new encryption key
  crypto status                count stored secrets per encryption key id (never prints a secret)
  crypto reencrypt [--apply]   move every stored secret onto the active key (default is a dry run)
  openapi [--write]            print (or write to frontend/src/api/openapi.json) the API's OpenAPI schema
"""
from __future__ import annotations

import argparse
import asyncio
import getpass
import os
import sys

import asyncpg

from app.core.config import settings


async def _connect() -> asyncpg.Connection:
    return await asyncpg.connect(settings.postgres_dsn)


def _db_upgrade() -> int:
    from alembic import command

    from app.schema import alembic_config

    command.upgrade(alembic_config(), "head")
    return 0


async def _db_check() -> int:
    from app import dbschema
    from app.schema import current_revision, head_revision

    conn = await _connect()
    try:
        revision = await current_revision(conn)
        problems = []
        if revision != head_revision():
            problems.append(f"revision is {revision!r}, expected {head_revision()!r}")
        problems += dbschema.diff(dbschema.load_committed(), await dbschema.snapshot(conn))
    finally:
        await conn.close()
    for problem in problems:
        print(problem)
    print("schema OK" if not problems else f"{len(problems)} difference(s)")
    return 1 if problems else 0


async def _db_snapshot(write: bool) -> int:
    from app import dbschema

    conn = await _connect()
    try:
        text = dbschema.dump(await dbschema.snapshot(conn))
    finally:
        await conn.close()
    if write:
        dbschema.SNAPSHOT_PATH.write_text(text)
        print(f"wrote {dbschema.SNAPSHOT_PATH}")
    else:
        sys.stdout.write(text)
    return 0


async def _seed(reset_role_permissions: bool) -> int:
    from app.seed import WeakSeedPassword, seed

    conn = await _connect()
    try:
        result = await seed(
            conn,
            admin_username=os.getenv("CYBERSATHY_SEED_ADMIN_USERNAME", "admin"),
            admin_display_name=os.getenv("CYBERSATHY_SEED_ADMIN_DISPLAY_NAME", "CyberSathy Admin"),
            admin_email=os.getenv("CYBERSATHY_SEED_ADMIN_EMAIL") or None,
            admin_password=os.getenv("CYBERSATHY_SEED_ADMIN_PASSWORD") or None,
            reset_role_permissions=reset_role_permissions,
        )
    except WeakSeedPassword as exc:
        print(f"refused: {exc}", file=sys.stderr)
        return 2
    finally:
        await conn.close()
    print(
        f"seed: {result.permissions_added} permission(s) added, roles added {result.roles_added or 'none'}, "
        f"{result.role_permissions_added} role permission(s) added, admin {result.admin_status}"
    )
    if result.generated_password:
        print("Generated one-time admin password (change it at first login):")
        print(result.generated_password)
    return 0


async def _set_password(username: str) -> int:
    from app.core.passwords import SCHEME_ARGON2, check_strength, hash_password

    password = os.getenv("CYBERSATHY_NEW_PASSWORD") or getpass.getpass("New password: ")
    problems = check_strength(password, settings.password_min_length)
    if problems:
        print(f"refused: {', '.join(problems)}", file=sys.stderr)
        return 2
    conn = await _connect()
    try:
        status = await conn.execute(
            """
            update users set password_hash = $2, hash_scheme = $3, password_changed_at = now(),
                             must_change_password = false, updated_at = now()
            where username = $1
            """,
            username, hash_password(password), SCHEME_ARGON2,
        )
        if status.endswith(" 0"):
            print(f"no such user: {username}", file=sys.stderr)
            return 1
        # Existing sessions were issued under the old password.
        await conn.execute(
            "update user_sessions set revoked_at = now() where revoked_at is null and user_id = (select id from users where username = $1)",
            username,
        )
    finally:
        await conn.close()
    print(f"password updated for {username}; existing sessions revoked")
    return 0


async def _sessions_cleanup() -> int:
    conn = await _connect()
    try:
        sessions = await conn.execute(
            "delete from user_sessions where expires_at < now() - interval '7 days' or revoked_at < now() - interval '7 days'"
        )
        attempts = await conn.execute("delete from login_attempts where occurred_at < now() - interval '90 days'")
    finally:
        await conn.close()
    print(f"sessions: {sessions}; login attempts: {attempts}")
    return 0


async def _mib_import(directory: str, vendor_slug: str | None) -> int:
    import os

    from app.repositories.mib import import_sources

    if not os.path.isdir(directory):
        print(f"not a directory: {directory}", file=sys.stderr)
        return 2
    sources = {}
    for name in sorted(os.listdir(directory)):
        path = os.path.join(directory, name)
        if os.path.isfile(path):
            try:
                sources[name] = open(path, errors="ignore").read()
            except OSError as exc:
                print(f"skipping {name}: {exc}", file=sys.stderr)
    if not sources:
        print(f"no files found in {directory}", file=sys.stderr)
        return 2
    conn = await _connect()
    try:
        vendor_id = None
        if vendor_slug:
            vendor_id = await conn.fetchval("select id from vendors where slug = $1", vendor_slug)
            if vendor_id is None:
                print(f"unknown vendor slug: {vendor_slug}", file=sys.stderr)
                return 2
        report = await import_sources(conn, sources, str(vendor_id) if vendor_id else None)
    finally:
        await conn.close()
    print(f"imported {report.files} file(s), {report.objects} object(s), {report.resolved} resolved to a numeric OID")
    return 0


async def _devices_import(path: str, apply: bool) -> int:
    from app.services.device_import import ImportAborted, import_devices, read_csv

    try:
        rows = read_csv(path)
    except (OSError, ValueError) as exc:
        print(f"cannot read {path}: {exc}", file=sys.stderr)
        return 2
    conn = await _connect()
    try:
        try:
            report = await import_devices(conn, rows, apply=apply)
        except ImportAborted as aborted:
            report = None
            for error in aborted.report.errors:
                print(f"row {error['row']} ({error['name']}): {'; '.join(error['problems'])}")
    finally:
        await conn.close()
    if report is None:
        print("nothing was created: fix the errors reported above and run again", file=sys.stderr)
        return 1
    for error in report.errors:
        print(f"row {error['row']} ({error['name']}): {'; '.join(error['problems'])}")
    if apply:
        print(f"created {report.created} device(s) with polling off; {report.discovery_jobs} safe discovery job(s) queued")
    else:
        print(f"dry run: {report.valid} row(s) valid, {len(report.errors)} with errors. Nothing was written; add --apply to create them")
    return 1 if report.errors else 0


async def _crypto_status() -> int:
    from app.core.crypto import EncryptionNotConfigured, EncryptionService
    from app.core.rotation import key_usage

    try:
        active = EncryptionService.from_settings().active_key_id
    except EncryptionNotConfigured as exc:
        print(f"encryption is not configured: {exc}", file=sys.stderr)
        return 1
    conn = await _connect()
    try:
        usage = await key_usage(conn)
    finally:
        await conn.close()
    stale = 0
    print(f"active key: {active}")
    for column, counts in usage.items():
        detail = ", ".join(f"{key}={n}" for key, n in sorted(counts.items())) or "no values"
        print(f"  {column}: {detail}")
        stale += sum(n for key, n in counts.items() if key != active)
    if stale:
        print(f"{stale} value(s) are not on the active key: run `python -m app.cli crypto reencrypt --apply`")
        return 2
    print("every stored value is on the active key; keys no longer listed above may be removed from ENCRYPTION_KEYS")
    return 0


async def _crypto_reencrypt(apply: bool) -> int:
    from app.core.crypto import EncryptionNotConfigured, EncryptionService
    from app.core.rotation import reencrypt_all

    try:
        enc = EncryptionService.from_settings()
    except EncryptionNotConfigured as exc:
        print(f"encryption is not configured: {exc}", file=sys.stderr)
        return 1
    conn = await _connect()
    try:
        report = await reencrypt_all(conn, enc, dry_run=not apply)
    finally:
        await conn.close()
    for column, r in report.columns.items():
        moved = f"re-encrypted {r.reencrypted}" if apply else f"would re-encrypt {r.total - r.already_active - len(r.failed_ids)}"
        line = f"{column}: {r.total} value(s), {r.already_active} already on {enc.active_key_id}, {moved}"
        if r.changed_concurrently:
            line += f", {r.changed_concurrently} changed during the run (run again)"
        print(line)
        for row_id in r.failed_ids:
            print(f"  cannot decrypt row {row_id}: its key is missing from ENCRYPTION_KEYS, or the value is damaged", file=sys.stderr)
    if report.failed:
        print(f"{report.failed} value(s) could not be decrypted and were left unchanged", file=sys.stderr)
        return 1
    if not apply:
        print("dry run: nothing was written; add --apply to re-encrypt")
    return 0


OPENAPI_PATH = os.path.join(os.path.dirname(__file__), "..", "..", "frontend", "src", "api", "openapi.json")


def openapi_text() -> str:
    """The API's OpenAPI schema, serialized deterministically so the committed copy diffs cleanly. The frontend's
    TypeScript types are generated from that copy (Plan 24), and tests fail when it is out of date."""
    import json

    from app.main import app

    return json.dumps(app.openapi(), indent=2, sort_keys=True, ensure_ascii=False) + "\n"


def _openapi(write: bool) -> int:
    text = openapi_text()
    if not write:
        sys.stdout.write(text)
        return 0
    path = os.path.normpath(OPENAPI_PATH)
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w", encoding="utf-8") as fh:
        fh.write(text)
    print(f"wrote {path}")
    return 0


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="python -m app.cli")
    sub = parser.add_subparsers(dest="group", required=True)

    db = sub.add_parser("db").add_subparsers(dest="command", required=True)
    db.add_parser("upgrade")
    db.add_parser("check")
    snap = db.add_parser("snapshot")
    snap.add_argument("--write", action="store_true")

    seed_parser = sub.add_parser("seed")
    seed_parser.add_argument("--reset-role-permissions", action="store_true", help="discard permission changes on system roles")

    users = sub.add_parser("users").add_subparsers(dest="command", required=True)
    setpw = users.add_parser("set-password")
    setpw.add_argument("username")

    mib = sub.add_parser("mib").add_subparsers(dest="command", required=True)
    mib_import = mib.add_parser("import")
    mib_import.add_argument("directory")
    mib_import.add_argument("--vendor", default=None, help="vendor slug to associate the imported files with")

    devices = sub.add_parser("devices").add_subparsers(dest="command", required=True)
    imp = devices.add_parser("import")
    imp.add_argument("file")
    imp.add_argument("--apply", action="store_true", help="create the devices (default is a dry run)")

    sub.add_parser("sessions").add_subparsers(dest="command", required=True).add_parser("cleanup")
    crypto = sub.add_parser("crypto").add_subparsers(dest="command", required=True)
    crypto.add_parser("generate-key")
    crypto.add_parser("status")
    reenc = crypto.add_parser("reencrypt")
    reenc.add_argument("--apply", action="store_true", help="write the re-encrypted values (default is a dry run)")

    openapi = sub.add_parser("openapi")
    openapi.add_argument("--write", action="store_true", help="write frontend/src/api/openapi.json")

    args = parser.parse_args(argv)
    if args.group == "db":
        if args.command == "upgrade":
            return _db_upgrade()
        if args.command == "check":
            return asyncio.run(_db_check())
        return asyncio.run(_db_snapshot(args.write))
    if args.group == "seed":
        return asyncio.run(_seed(args.reset_role_permissions))
    if args.group == "users":
        return asyncio.run(_set_password(args.username))
    if args.group == "mib":
        return asyncio.run(_mib_import(args.directory, args.vendor))
    if args.group == "devices":
        return asyncio.run(_devices_import(args.file, args.apply))
    if args.group == "sessions":
        return asyncio.run(_sessions_cleanup())
    if args.group == "openapi":
        return _openapi(args.write)
    if args.group == "crypto":
        if args.command == "status":
            return asyncio.run(_crypto_status())
        if args.command == "reencrypt":
            return asyncio.run(_crypto_reencrypt(args.apply))
        from app.core.crypto import generate_key

        print(generate_key())
        return 0
    return 1


if __name__ == "__main__":
    raise SystemExit(main())
