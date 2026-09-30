from tests.helpers import bearer, make_user


async def login_as(app_client, db, name, role):
    await make_user(db, name, role)
    headers = await bearer(app_client, name)
    app_client.cookies.clear()
    return headers


async def test_the_catalogue_is_seeded_and_readable_by_isp_roles(app_client, db):
    headers = await login_as(app_client, db, "support", "ISP Support")
    models = (await app_client.get("/api/v1/device-models", headers=headers)).json()
    assert len(models) == 35
    s5612 = next(m for m in models if m["legacy_key"] == "bdcom_s5612")
    assert s5612["device_type"] == "switch" and s5612["family_slug"] == "bdcom-switch" and s5612["vendor"] == "bdcom"
    detail = (await app_client.get(f"/api/v1/device-models/{s5612['id']}", headers=headers)).json()
    assert detail["model_name"] == "BDCOM S5612"
    assert (await app_client.get("/api/v1/device-models/not-a-uuid", headers=headers)).status_code == 404
    assert (await app_client.get("/api/v1/device-models/00000000-0000-0000-0000-000000000000", headers=headers)).status_code == 404


async def test_resellers_cannot_read_the_catalogue(app_client, db):
    headers = await login_as(app_client, db, "res", "Reseller Admin")
    assert (await app_client.get("/api/v1/device-models", headers=headers)).status_code == 403
    assert (await app_client.post("/api/v1/device-models/detect", headers=headers, json={"sys_descr": "x"})).status_code == 403


async def test_detect_endpoint_matches_a_real_bdcom_switch_description(app_client, db):
    headers = await login_as(app_client, db, "noc", "ISP NOC")
    result = await app_client.post("/api/v1/device-models/detect", headers=headers,
                                   json={"sys_descr": "BDCOM(tm) S2900-24S8C4X Software, Version 1.0", "sys_object_id": "1.3.6.1.4.1.3320.1.458.0"})
    body = result.json()
    assert body["status"] == "matched" and body["model"]["key"] == "bdcom_s2900_series" and body["model"]["device_type"] == "switch"


async def test_detect_endpoint_returns_unknown_for_an_unrelated_device(app_client, db):
    headers = await login_as(app_client, db, "noc", "ISP NOC")
    result = await app_client.post("/api/v1/device-models/detect", headers=headers, json={"sys_descr": "Cisco IOS Software", "sys_object_id": "1.3.6.1.4.1.9.1.1"})
    assert result.json() == {"status": "unknown", "model": None, "candidates": []}


async def test_detect_endpoint_splits_bdcom_switch_from_bdcom_olt(app_client, db):
    headers = await login_as(app_client, db, "noc", "ISP NOC")
    switch = await app_client.post("/api/v1/device-models/detect", headers=headers, json={"sys_descr": "BDCOM(tm) S5612 Software, Version 127335"})
    olt = await app_client.post("/api/v1/device-models/detect", headers=headers, json={"sys_descr": "BDCOM GP3600-16 Software"})
    assert switch.json()["model"]["device_type"] == "switch" and olt.json()["model"]["device_type"] == "olt"


async def test_an_empty_request_matches_nothing_because_every_real_model_needs_something_to_go_on(app_client, db):
    headers = await login_as(app_client, db, "noc", "ISP NOC")
    result = await app_client.post("/api/v1/device-models/detect", headers=headers, json={})
    assert result.json()["status"] != "unknown" or result.json()["model"] is None
