"""Plan 40: a database backup with its manifest, and the restore drill that proves it restores. Runs pg_dump and
pg_restore against the test database server; no device is involved."""
import json
import shutil

import pytest

from app.core.config import settings
from app.operations import backup
from tests.helpers import make_user

pytestmark = pytest.mark.skipif(shutil.which("pg_dump") is None or shutil.which("pg_restore") is None,
                                reason="pg_dump and pg_restore are not installed")


async def scratch_databases(db):
    return await db.fetchval("select count(*) from pg_database where datname like 'cs_restore_drill_%'")


async def test_a_backup_restores_and_matches_its_manifest(db, tmp_path):
    await db.execute("insert into login_attempts (username, success) values ('drill', false)")
    await make_user(db, "root-admin", "Super Admin")
    dump, manifest = await backup.create(settings, tmp_path)
    assert dump.exists() and dump.with_suffix(".json").exists()
    assert manifest["tables"]["login_attempts"] == 1 and manifest["revision"] == await db.fetchval("select version_num from alembic_version")
    assert manifest["bytes"] == dump.stat().st_size and len(manifest["sha256"]) == 64
    result = await backup.drill(settings, dump)
    assert result.ok, result.problems
    assert result.tables_checked == len(manifest["tables"]) and result.restore_seconds > 0
    assert await scratch_databases(db) == 0  # the scratch database is gone afterwards


async def test_a_dump_that_does_not_match_its_manifest_is_refused_before_restoring(db, tmp_path):
    dump, _ = await backup.create(settings, tmp_path)
    with dump.open("ab") as fh:
        fh.write(b"tampered")
    result = await backup.drill(settings, dump)
    assert not result.ok and "SHA-256" in result.problems[0] and result.tables_checked == 0


async def test_a_restore_that_lost_rows_or_is_at_another_revision_fails_and_is_still_cleaned_up(db, tmp_path):
    dump, manifest = await backup.create(settings, tmp_path)
    manifest["tables"]["login_attempts"] += 5
    manifest["revision"] = "19990101_0000"
    dump.with_suffix(".json").write_text(json.dumps(manifest))
    result = await backup.drill(settings, dump)
    assert not result.ok
    assert any(p.startswith("login_attempts: ") for p in result.problems)
    assert any("schema revision" in p for p in result.problems)
    assert await scratch_databases(db) == 0


async def test_a_restore_nobody_could_administer_fails(db, tmp_path):
    admin = await make_user(db, "root-admin", "Super Admin")
    await make_user(db, "viewer", "Reseller Viewer")
    await db.execute("update users set is_active = false where id = $1::uuid", admin)
    dump, _ = await backup.create(settings, tmp_path)
    result = await backup.drill(settings, dump)
    assert not result.ok and any("nobody could administer" in p for p in result.problems)


async def test_the_password_never_reaches_the_command_line(monkeypatch, tmp_path):
    seen = []

    def fake_run(args, **kwargs):
        seen.append((args, kwargs["env"]))
        (tmp_path / "x.dump").write_bytes(b"")
        raise RuntimeError("stop here")

    monkeypatch.setattr(backup.subprocess, "run", fake_run)
    with pytest.raises(RuntimeError):
        await backup.create(settings, tmp_path)
    args, env = seen[0]
    assert settings.postgres_password and settings.postgres_password not in " ".join(args)
    assert env["PGPASSWORD"] == settings.postgres_password


def test_the_cli_says_plainly_when_the_client_tools_are_missing(monkeypatch, capsys):
    import shutil as sh

    from app.cli import main

    monkeypatch.setattr(sh, "which", lambda tool: None)
    assert main(["backup", "drill", "nothing.dump"]) == 2
    assert "pg_dump and pg_restore not found" in capsys.readouterr().out
