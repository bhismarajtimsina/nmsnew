"""Macros and ONU-registration templates (Plan 38): CRUD gated by the edit permission and audited, templates and
parameter declarations checked when saved, concurrent edits refused, and previews that render sample data without
touching a device."""
import json

import pytest

from tests.helpers import bearer, make_user

M = "/api/v1/macros"
R = "/api/v1/onu-registration-templates"

DELETE_ONU = {
    "name": "Delete ONU",
    "description": "Removes one ONU from its PON port",
    "template": "interface gpon {{ iface.frame }}/{{ iface.slot }}\nont delete {{ iface.port }} {{ params.onu }}\nquit",
    "parameters": [{"key": "onu", "type": "input_string", "pattern": "[0-9]{1,3}"}],
    "display_output": "last",
}
SAMPLE = {"iface": {"frame": 0, "slot": 1, "port": 7}}


async def login_as(app_client, db, name, role):
    await make_user(db, name, role)
    headers = await bearer(app_client, name)
    app_client.cookies.clear()
    return headers


async def create(app_client, headers, body=None, base=M, expect=201):
    response = await app_client.post(base, headers=headers, json=body or DELETE_ONU)
    assert response.status_code == expect, response.text
    return response.json()


async def test_an_editor_creates_reads_lists_and_the_create_is_audited(app_client, db):
    headers = await login_as(app_client, db, "root", "Super Admin")
    made = await create(app_client, headers)
    assert made["version"] == 1 and made["kind"] == "macro" and made["parameters"][0]["pattern"] == "[0-9]{1,3}"
    assert (await app_client.get(f"{M}/{made['id']}", headers=headers)).json()["template"] == DELETE_ONU["template"]
    assert [m["name"] for m in (await app_client.get(M, headers=headers)).json()["items"]] == ["Delete ONU"]
    audit = await db.fetchrow("select action, resource_id, after from audit_logs where action = 'macro.create'")
    assert str(audit["resource_id"]) == made["id"] and json.loads(audit["after"])["template"] == DELETE_ONU["template"]


async def test_the_two_kinds_are_separate(app_client, db):
    headers = await login_as(app_client, db, "root", "Super Admin")
    macro = await create(app_client, headers)
    reg = await create(app_client, headers, base=R)                     # the same name is fine in the other kind
    assert reg["kind"] == "onu_registration"
    assert (await app_client.get(f"{R}/{macro['id']}", headers=headers)).status_code == 404
    assert (await app_client.get(f"{M}/{reg['id']}", headers=headers)).status_code == 404
    await create(app_client, headers, expect=409)                         # but not twice in one kind


@pytest.mark.parametrize("change, message", [
    ({"template": "{% include '/etc/passwd' %}"}, "not allowed"),
    ({"template": "{% for x in %}"}, "parse"),
    ({"parameters": [{"key": "onu", "type": "input_string", "pattern": "([0-9"}]}, "invalid pattern"),
    ({"parameters": [{"key": "Bad Key", "type": "input_string"}]}, "invalid"),
    ({"parameters": [{"key": "speed", "type": "select_predefined", "variants": []}]}, "variants"),
])
async def test_a_bad_template_or_declaration_is_refused_when_saved(app_client, db, change, message):
    headers = await login_as(app_client, db, "root", "Super Admin")
    body = await create(app_client, headers, {**DELETE_ONU, **change}, expect=422)
    assert message in body["detail"]
    assert await db.fetchval("select count(*) from macros") == 0


async def test_an_edit_needs_the_current_version_and_is_audited_with_before_and_after(app_client, db):
    headers = await login_as(app_client, db, "root", "Super Admin")
    made = await create(app_client, headers)
    first = await app_client.put(f"{M}/{made['id']}", headers=headers, json={**DELETE_ONU, "name": "Delete ONU v2", "version": 1})
    assert first.status_code == 200 and first.json()["version"] == 2
    stale = await app_client.put(f"{M}/{made['id']}", headers=headers, json={**DELETE_ONU, "name": "Lost edit", "version": 1})
    assert stale.status_code == 409 and "someone else" in stale.json()["detail"]
    assert (await app_client.get(f"{M}/{made['id']}", headers=headers)).json()["name"] == "Delete ONU v2"
    audit = await db.fetchrow("select before, after from audit_logs where action = 'macro.update'")
    assert json.loads(audit["before"])["name"] == "Delete ONU" and json.loads(audit["after"])["name"] == "Delete ONU v2"


async def test_delete_is_audited_with_what_was_removed(app_client, db):
    headers = await login_as(app_client, db, "root", "Super Admin")
    made = await create(app_client, headers)
    assert (await app_client.delete(f"{M}/{made['id']}", headers=headers)).status_code == 204
    assert (await app_client.delete(f"{M}/{made['id']}", headers=headers)).status_code == 404
    audit = await db.fetchrow("select before from audit_logs where action = 'macro.delete'")
    assert json.loads(audit["before"])["template"] == DELETE_ONU["template"]


async def test_editing_needs_the_edit_permission_and_viewing_the_view_permission(app_client, db):
    root = await login_as(app_client, db, "root", "Super Admin")
    made = await create(app_client, root)
    reg = await create(app_client, root, base=R)
    viewer = await login_as(app_client, db, "res", "Reseller Operator")   # onus.registration.preview, no macros.*
    assert (await app_client.get(R, headers=viewer)).status_code == 200
    assert (await app_client.post(f"{R}/{reg['id']}/preview", headers=viewer, json={"params": {"onu": "5"}, "variables": SAMPLE})).status_code == 200
    assert (await app_client.post(R, headers=viewer, json=DELETE_ONU)).status_code == 403
    assert (await app_client.delete(f"{R}/{reg['id']}", headers=viewer)).status_code == 403
    assert (await app_client.post(f"{R}/preview", headers=viewer, json={"template": "x"})).status_code == 403
    assert (await app_client.get(M, headers=viewer)).status_code == 403
    assert (await app_client.get(f"{M}/{made['id']}")).status_code == 401


async def test_a_saved_template_previews_against_sample_data(app_client, db):
    headers = await login_as(app_client, db, "root", "Super Admin")
    made = await create(app_client, headers)
    body = (await app_client.post(f"{M}/{made['id']}/preview", headers=headers, json={"params": {"onu": "5"}, "variables": SAMPLE})).json()
    assert body == {"commands": ["interface gpon 0/1", "ont delete 7 5", "quit"], "aborted": None}


@pytest.mark.parametrize("params", [{"onu": "5 ; reboot"}, {"onu": "5\nreboot"}, {}, {"onu": "5", "extra": "1"}])
async def test_a_preview_refuses_parameters_that_could_add_a_command(app_client, db, params):
    headers = await login_as(app_client, db, "root", "Super Admin")
    made = await create(app_client, headers)
    response = await app_client.post(f"{M}/{made['id']}/preview", headers=headers, json={"params": params, "variables": SAMPLE})
    assert response.status_code == 422


async def test_a_draft_previews_before_saving_and_reports_its_own_exception_line(app_client, db):
    headers = await login_as(app_client, db, "root", "Super Admin")
    draft = {"template": "{% if not iface.free %}<exception 'no free ONT'>{% endif %}\nont add {{ iface.free }}",
             "parameters": [], "params": {}, "variables": {"iface": {"free": 0}}}
    assert (await app_client.post(f"{M}/preview", headers=headers, json=draft)).json() == {"commands": [], "aborted": "no free ONT"}
    draft["variables"] = {"iface": {"free": 4}}
    assert (await app_client.post(f"{M}/preview", headers=headers, json=draft)).json() == {"commands": ["ont add 4"], "aborted": None}
    hostile = {**draft, "template": "{{ ''.__class__.__mro__ }}"}
    assert (await app_client.post(f"{M}/preview", headers=headers, json=hostile)).status_code == 422
    assert await db.fetchval("select count(*) from macros") == 0


async def test_secrets_in_sample_variables_never_reach_the_rendered_output(app_client, db):
    headers = await login_as(app_client, db, "root", "Super Admin")
    draft = {"template": "{{ device.community }}", "parameters": [], "params": {},
             "variables": {"device": {"community": "public-secret"}}}
    response = await app_client.post(f"{M}/preview", headers=headers, json=draft)
    assert response.status_code == 422 and "public-secret" not in response.text


async def test_a_selection_cannot_take_its_values_from_a_secret_in_the_sample_data(app_client, db):
    headers = await login_as(app_client, db, "root", "Super Admin")
    draft = {"template": "use {{ params.key }}", "params": {"key": "hunter2"},
             "parameters": [{"key": "key", "type": "select_from_variable", "source": "device.tokens"}],
             "variables": {"device": {"tokens": ["hunter2"]}}}
    response = await app_client.post(f"{M}/preview", headers=headers, json=draft)
    assert response.status_code == 422 and "hunter2" not in response.text
