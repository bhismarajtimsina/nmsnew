from datetime import datetime, timedelta, timezone

from tests.helpers import bearer, make_user
from tests.polling_helpers import make_active_profile


async def login_as(app_client, db, name, role):
    await make_user(db, name, role)
    headers = await bearer(app_client, name)
    app_client.cookies.clear()
    return headers


async def test_the_default_jobs_are_seeded_and_polls_start_disabled(app_client, db):
    headers = await login_as(app_client, db, "noc", "ISP NOC")
    jobs = {j["key"]: j for j in (await app_client.get("/api/v1/system/schedule", headers=headers)).json()}
    assert jobs["sessions_cleanup"]["enabled"] is True
    for key in ("poll_switch_basic", "poll_olt_basic", "poll_router_basic"):
        assert jobs[key]["enabled"] is False and jobs[key]["job_type"] == "poll_group"
    assert len(jobs["sessions_cleanup"]["upcoming"]) == 3
    runs = await app_client.get("/api/v1/system/schedule/sessions_cleanup/runs", headers=headers)
    assert runs.status_code == 200 and runs.json() == []
    assert (await app_client.get("/api/v1/system/schedule/no-such-job/runs", headers=headers)).status_code == 404


async def test_only_holders_of_schedule_manage_can_change_a_job(app_client, db):
    headers = await login_as(app_client, db, "support", "ISP Support")
    assert (await app_client.get("/api/v1/system/schedule", headers=headers)).status_code == 403          # Support reads neither
    assert (await app_client.patch("/api/v1/system/schedule/sessions_cleanup", headers=headers, json={"enabled": False})).status_code == 403
    noc = await login_as(app_client, db, "noc", "ISP NOC")
    assert (await app_client.get("/api/v1/system/schedule", headers=noc)).status_code == 200               # NOC reads
    assert (await app_client.patch("/api/v1/system/schedule/sessions_cleanup", headers=noc, json={"enabled": False})).status_code == 403   # but only Admin edits


async def test_a_crontab_and_parameters_are_validated_before_anything_is_saved(app_client, db):
    headers = await login_as(app_client, db, "admin", "ISP Admin")
    bad_cron = await app_client.patch("/api/v1/system/schedule/sessions_cleanup", headers=headers, json={"crontab": "not a schedule"})
    assert bad_cron.status_code == 422 and "crontab" in bad_cron.json()["detail"]
    bad_params = await app_client.patch("/api/v1/system/schedule/retention_login_attempts", headers=headers, json={"params": {"target": "login_attempts", "days": 1}})
    assert bad_params.status_code == 422
    unchanged = await app_client.get("/api/v1/system/schedule", headers=headers)
    row = next(j for j in unchanged.json() if j["key"] == "retention_login_attempts")
    assert row["params"]["days"] == 90


async def test_enabling_a_poll_group_needs_an_active_profile_to_poll_with(app_client, db):
    headers = await login_as(app_client, db, "admin", "ISP Admin")
    refused = await app_client.patch("/api/v1/system/schedule/poll_switch_basic", headers=headers, json={"enabled": True})
    assert refused.status_code == 409 and "switch_basic" in refused.json()["detail"]
    await make_active_profile(db, "switch_basic")
    ok = await app_client.patch("/api/v1/system/schedule/poll_switch_basic", headers=headers, json={"enabled": True})
    assert ok.status_code == 200 and ok.json()["enabled"] is True and ok.json()["next_run_at"] is not None


async def test_a_fixed_job_cannot_be_edited(app_client, db):
    headers = await login_as(app_client, db, "admin", "ISP Admin")
    await db.execute("update schedule_jobs set editable = false where key = 'sessions_cleanup'")
    assert (await app_client.patch("/api/v1/system/schedule/sessions_cleanup", headers=headers, json={"enabled": False})).status_code == 403


async def test_run_now_is_refused_when_disabled_or_run_too_recently(app_client, db):
    headers = await login_as(app_client, db, "admin", "ISP Admin")
    assert (await app_client.post("/api/v1/system/schedule/poll_switch_basic/run", headers=headers)).status_code == 409
    await db.execute("update schedule_jobs set enabled = true, last_run_at = now() where key = 'sessions_cleanup'")
    soon = await app_client.post("/api/v1/system/schedule/sessions_cleanup/run", headers=headers)
    assert soon.status_code == 429 and soon.headers["retry-after"] == "60"
    await db.execute("update schedule_jobs set last_run_at = now() - interval '2 minutes' where key = 'sessions_cleanup'")
    ok = await app_client.post("/api/v1/system/schedule/sessions_cleanup/run", headers=headers)
    assert ok.status_code == 202
    assert await db.fetchval("select next_run_at <= now() from schedule_jobs where key = 'sessions_cleanup'") is True
    audit = await db.fetchval("select count(*) from audit_logs where action = 'schedule.run_requested'")
    assert audit == 1


async def test_changes_are_audited_and_history_reflects_the_scheduler(app_client, db):
    headers = await login_as(app_client, db, "admin", "ISP Admin")
    await app_client.patch("/api/v1/system/schedule/sessions_cleanup", headers=headers, json={"crontab": "0 4 * * *"})
    audit = await db.fetchrow("select before, after from audit_logs where action = 'schedule.updated'")
    assert '"crontab"' in audit["before"] and '"0 4 * * *"' in audit["after"]
    job_id = await db.fetchval("select id from schedule_jobs where key = 'sessions_cleanup'")
    await db.execute("insert into schedule_runs (job_id, scheduled_for, status, output) values ($1, now(), 'ok', 'did the thing')", job_id)
    runs = (await app_client.get("/api/v1/system/schedule/sessions_cleanup/runs", headers=headers)).json()
    assert runs[0]["output"] == "did the thing"
