import csv
import time

from app import cli
from app.services.device_import import MAX_ROWS, import_devices, read_csv

SAFE = ["1.3.6.1.2.1.1.1.0", "1.3.6.1.2.1.1.2.0", "1.3.6.1.2.1.1.3.0", "1.3.6.1.2.1.1.5.0"]
FIELDS = ["name", "management_ip", "device_type", "vendor", "family", "group", "access_profile"]


def write(path, rows):
    with open(path, "w", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=FIELDS)
        writer.writeheader()
        writer.writerows(rows)


async def setup(db):
    await db.execute("insert into device_groups (name) values ('core')")
    await db.execute("insert into device_access_profiles (name, snmp_version, snmp_community_enc) values ('snmp', 'v2c', 'v1:test1:x')")


def row(i, **extra):
    return {"name": f"sw-{i}", "management_ip": f"10.{i // 60000}.{(i // 250) % 250}.{i % 250 + 1}", "device_type": "switch",
            "vendor": "bdcom", "family": "bdcom-switch", "group": "core", "access_profile": "snmp", **extra}


async def test_a_dry_run_validates_everything_and_writes_nothing(db, tmp_path):
    await setup(db)
    path = tmp_path / "d.csv"
    write(path, [row(1), row(2), row(3, management_ip="127.0.0.1"), row(4, group="nowhere"), row(5, family="bdcom-olt")])
    report = await import_devices(db, read_csv(str(path)), apply=False)
    assert report.applied is False and report.created == 0
    problems = {e["row"]: " ".join(e["problems"]) for e in report.errors}
    assert set(problems) == {4, 5, 6} and "management_ip" in problems[4] and "nowhere" in problems[5] and "does not match" in problems[6]
    assert await db.fetchval("select count(*) from devices") == 0 and await db.fetchval("select count(*) from discovery_jobs") == 0


async def test_one_bad_row_stops_the_whole_import_before_anything_is_written(db, tmp_path):
    from app.services.device_import import ImportAborted
    import pytest

    await setup(db)
    path = tmp_path / "d.csv"
    write(path, [row(1), row(2), row(3, management_ip="not-an-ip")])
    with pytest.raises(ImportAborted):
        await import_devices(db, read_csv(str(path)), apply=True)
    assert await db.fetchval("select count(*) from devices") == 0


async def test_duplicates_inside_the_file_and_against_existing_devices_are_reported(db, tmp_path):
    await setup(db)
    await db.execute("insert into devices (name, management_ip, device_type) values ('old', '10.0.0.1', 'switch')")
    path = tmp_path / "d.csv"
    write(path, [row(1, management_ip="10.0.0.1"), row(2, management_ip="10.9.9.9"), row(3, management_ip="10.9.9.9")])
    report = await import_devices(db, read_csv(str(path)), apply=False)
    text = str(report.errors)
    assert "appears twice" in text and "already exists" in text


async def test_a_thousand_devices_import_with_polling_off_and_only_safe_discovery_queued(db, tmp_path):
    await setup(db)
    path = tmp_path / "d.csv"
    write(path, [row(i) for i in range(1, 1001)])
    started = time.monotonic()
    report = await import_devices(db, read_csv(str(path)), apply=True)
    assert report.created == 1000 and report.discovery_jobs == 1000 and not report.errors
    assert time.monotonic() - started < 60
    assert await db.fetchval("select count(*) from devices where polling_enabled") == 0
    assert await db.fetchval("select count(*) from devices where polling_owner <> 'cybersathy'") == 0
    assert await db.fetchval("select count(*) from devices where created_by is not null") == 0
    assert await db.fetchval("select count(*) from discovery_jobs") == 1000
    assert await db.fetchval("select count(*) from discovery_jobs where oids <> $1::text[]", SAFE) == 0     # nothing but discovery, ever
    assert await db.fetchval("select count(distinct status) from discovery_jobs") == 1


async def test_the_cli_reports_and_exits_with_the_right_codes(db, tmp_path, capsys):
    await setup(db)
    good, bad = tmp_path / "good.csv", tmp_path / "bad.csv"
    write(good, [row(1), row(2)])
    write(bad, [row(3), row(4, device_type="bridge")])
    assert await cli._devices_import(str(good), apply=False) == 0
    assert "dry run: 2 row(s) valid" in capsys.readouterr().out and await db.fetchval("select count(*) from devices") == 0
    assert await cli._devices_import(str(bad), apply=True) == 1
    captured = capsys.readouterr()
    assert "nothing was created" in captured.err and await db.fetchval("select count(*) from devices") == 0
    assert await cli._devices_import(str(good), apply=True) == 0
    assert "created 2 device(s) with polling off" in capsys.readouterr().out and await db.fetchval("select count(*) from devices") == 2


async def test_unreadable_or_malformed_files_are_refused(db, tmp_path, capsys):
    missing = tmp_path / "nope.csv"
    assert await cli._devices_import(str(missing), apply=False) == 2
    headerless = tmp_path / "h.csv"
    headerless.write_text("name,device_type\nsw,switch\n")
    assert await cli._devices_import(str(headerless), apply=False) == 2 and "missing column" in capsys.readouterr().err
    assert MAX_ROWS == 20_000
