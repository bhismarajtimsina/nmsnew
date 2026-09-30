from app.seed import DEFAULT_JOBS, seed


async def test_reseeding_never_touches_a_schedule_an_operator_changed(db):
    await seed(db, admin_username="boot", admin_password="Boot-Strap-Password-1!")
    await db.execute("update schedule_jobs set enabled = true, crontab = '0 0 * * *' where key = 'poll_switch_basic'")
    await seed(db, admin_username="boot")
    row = await db.fetchrow("select enabled, crontab from schedule_jobs where key = 'poll_switch_basic'")
    assert row["enabled"] is True and row["crontab"] == "0 0 * * *"


async def test_every_default_job_has_a_valid_type_and_crontab(db):
    from app.scheduling.cron import parse
    from app.scheduling.jobs import validate_params

    for key, job_type, params, crontab, enabled, description in DEFAULT_JOBS:
        parse(crontab)  # raises on a bad schedule
        validate_params(job_type, params)  # raises on bad parameters
    assert len(DEFAULT_JOBS) == len({j[0] for j in DEFAULT_JOBS})


async def test_the_device_model_catalogue_is_seeded_from_the_real_legacy_source(db):
    count = await db.fetchval("select count(*) from device_models")
    assert count == 35
    row = await db.fetchrow("select model_name, device_type, family_id is not null as has_family from device_models where legacy_key = 'bdcom_s5612'")
    assert row["model_name"] == "BDCOM S5612" and row["device_type"] == "switch" and row["has_family"]


async def test_reseeding_the_device_model_catalogue_is_idempotent(db):
    from app.seed import seed

    before = await db.fetchval("select count(*) from device_models")
    await seed(db, admin_username="boot2", admin_password="Boot-Strap-Password-2!")
    assert await db.fetchval("select count(*) from device_models") == before == 35
