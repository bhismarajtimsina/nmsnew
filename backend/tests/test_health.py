import uuid

from tests.helpers import bearer, make_user


async def test_health_reveals_nothing_beyond_status(app_client):
    for path in ("/health", "/api/v1/health"):
        response = await app_client.get(path)
        assert response.status_code == 200 and response.json() == {"status": "ok"}


async def test_ready_reports_ok_per_dependency(app_client):
    response = await app_client.get("/ready")
    assert response.status_code == 200 and response.json() == {"ready": True, "checks": {"postgres": "ok", "redis": "ok"}}


async def test_ready_never_leaks_error_detail(app_client, monkeypatch):
    async def broken(client):
        raise RuntimeError("could not connect to server at host=10.9.8.7 user=cybersathy password=hunter2")

    monkeypatch.setattr("app.main.check_redis", broken)
    response = await app_client.get("/ready")
    assert response.status_code == 503 and response.json() == {"ready": False, "checks": {"postgres": "ok", "redis": "error"}}
    assert "hunter2" not in response.text and "10.9.8.7" not in response.text


async def test_metrics_label_routes_by_template_so_series_do_not_multiply(app_client, db):
    await make_user(db, "alice", "ISP Admin")
    headers = await bearer(app_client, "alice")
    app_client.cookies.clear()
    ids = [str(uuid.uuid4()) for _ in range(3)]
    for device_id in ids:
        await app_client.get(f"/api/v1/devices/{device_id}", headers=headers)
    await app_client.get("/wp-admin/setup-config.php")
    await app_client.get(f"/no/such/{uuid.uuid4()}")
    text = (await app_client.get("/metrics")).text
    assert 'route="/api/v1/devices/{device_id}"' in text
    assert 'route="unmatched"' in text
    assert not any(device_id in text for device_id in ids)
    assert "wp-admin" not in text
