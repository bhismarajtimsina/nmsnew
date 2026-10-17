"""Plan 40: every table is classified as history or reference data, every hypertable has retention and compression,
and the retention report reads the policies as they are in the database. The report deletes nothing."""
import os
import subprocess
import sys

from app.operations.retention import HISTORY, REFERENCE, render, report


async def test_every_table_is_classified_so_a_new_one_cannot_slip_in_without_a_decision(db):
    tables = {r["relname"] for r in await db.fetch(
        "select c.relname from pg_class c join pg_namespace n on n.oid = c.relnamespace where n.nspname = 'public' and c.relkind in ('r', 'p')")}
    unclassified = tables - set(HISTORY) - REFERENCE
    assert unclassified == set(), f"classify these in app/operations/retention.py: {sorted(unclassified)}"
    assert not set(HISTORY) & REFERENCE
    assert set(HISTORY) | REFERENCE <= tables, "the lists name a table that no longer exists"


async def test_every_history_column_exists(db):
    for table, column in HISTORY.items():
        assert await db.fetchval("select exists(select 1 from information_schema.columns where table_name = $1 and column_name = $2)",
                                 table, column), (table, column)


async def test_every_hypertable_has_retention_and_compression(db):
    rows = await db.fetch(
        """
        select h.hypertable_name,
               bool_or(j.proc_name = 'policy_retention') as retention, bool_or(j.proc_name = 'policy_compression') as compression
        from timescaledb_information.hypertables h
        left join timescaledb_information.jobs j on j.hypertable_name = h.hypertable_name
        group by h.hypertable_name
        """)
    assert rows
    missing = [(r["hypertable_name"], r["retention"], r["compression"]) for r in rows if not (r["retention"] and r["compression"])]
    assert missing == []
    assert {r["hypertable_name"] for r in rows} <= set(HISTORY)  # a hypertable is history by definition


async def test_the_report_reads_each_kind_of_policy_and_says_none_where_there_is_none(db):
    rows = {r.table: r for r in await report(db)}
    assert set(rows) == set(HISTORY)
    assert rows["audit_logs"].retention == "2 years (TimescaleDB)" and rows["audit_logs"].compression == "30 days"
    assert rows["interface_counter_samples"].compression == "1 day"
    assert rows["login_attempts"].retention == "90 days (job retention_login_attempts)" and rows["login_attempts"].compression is None
    assert rows["user_sessions"].retention.startswith("7 days after expiry (job ")
    assert rows["console_history"].retention == "none"


async def test_the_report_follows_the_database_not_this_file(db):
    await db.execute("update schedule_jobs set enabled = false, params = params || '{\"days\": 120}' where key = 'retention_login_attempts'")
    await db.execute("insert into login_attempts (username, success, occurred_at) values ('x', false, '2026-01-02 03:04:05+00')")
    await db.execute("delete from schedule_jobs where job_type = 'cleanup_sessions'")
    rows = {r.table: r for r in await report(db)}
    assert rows["login_attempts"].retention == "120 days (job retention_login_attempts, disabled)"
    assert rows["login_attempts"].rows == 1 and rows["login_attempts"].oldest.startswith("2026-01-02")
    assert rows["user_sessions"].retention == "none"


async def test_the_report_deletes_nothing_and_names_the_tables_without_a_period(db):
    await db.execute("insert into login_attempts (username, success, occurred_at) values ('x', false, '2020-01-01')")
    text = render(await report(db))
    assert "audit_logs *" in text and "No retention:" in text and "console_history" in text.split("No retention:")[1]
    assert "login_attempts" not in text.split("No retention:")[1]
    assert await db.fetchval("select count(*) from login_attempts") == 1


def test_the_cli_prints_the_report():
    result = subprocess.run([sys.executable, "-m", "app.cli", "retention", "report"], capture_output=True, text=True, timeout=60,
                            env=os.environ.copy())
    assert result.returncode == 0, result.stderr
    assert result.stdout.splitlines()[0].split() == ["table", "rows", "oldest", "retention", "compression"]
