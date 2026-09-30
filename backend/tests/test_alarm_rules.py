"""The real, production alarm rule set (29 rules, frozen from a live export) and the Prometheus rule-file generator."""
import yaml

from app.alerting.rulefile import AlarmRule, render
from app.registry.alarm_rule_data import ALARM_RULES
from app.seed import seed


def test_the_real_rule_count_and_a_few_known_rules_by_name():
    assert len(ALARM_RULES) == 29
    by_name = {r[1]: r for r in ALARM_RULES}
    assert set(by_name) >= {"interface_is_down", "pon_mass_onts_down", "link_down", "high_memory_load", "bgp_session_down"}
    interface_is_down = by_name["interface_is_down"]
    assert interface_is_down[4] == "info" and interface_is_down[5] == "isp" and interface_is_down[3] == "5m"


def test_every_rule_has_a_unique_name_and_legacy_id_and_a_valid_severity_and_focus():
    names = [r[1] for r in ALARM_RULES]
    legacy_ids = [r[12] for r in ALARM_RULES]
    assert len(names) == len(set(names)) and len(legacy_ids) == len(set(legacy_ids))
    for group, name, expr, for_d, severity, audience, isp_focus, reseller_focus, summary, description, enabled, internal, legacy_id in ALARM_RULES:
        assert severity in ("info", "warning", "critical"), name
        assert isp_focus in ("primary", "secondary", "muted") and reseller_focus in ("primary", "secondary", "muted"), name
        assert audience in (None, "isp", "reseller", "both", "operator"), name
        assert expr.strip() and summary.strip() and description.strip(), name


def test_the_ont_outage_rule_keeps_its_real_production_promql_character_for_character():
    # The exact expression from migration 069_outage_sees_los, verified against the real production database.
    by_name = {r[1]: r for r in ALARM_RULES}
    expr = by_name["pon_mass_onts_down"][2]
    assert 'device_interface_status{iface_name=~".*:.*"}' in expr
    assert "== 0" in expr and "== -2" in expr and ">= 3" in expr
    assert by_name["pon_mass_onts_power_loss"][2].count("== -1") == 1  # power loss is the separate, distinct rule


async def test_reseeding_neither_duplicates_rules_nor_reverts_an_operators_edit(db):
    before = await db.fetchval("select count(*) from alarm_rules")
    await db.execute("update alarm_rules set enabled = false, expression = 'up == 0' where alert_name = 'pon_mass_onts_down'")
    await seed(db, admin_username="boot", admin_password="Boot-Strap-Password-1!")
    assert await db.fetchval("select count(*) from alarm_rules") == before
    row = await db.fetchrow("select enabled, expression from alarm_rules where alert_name = 'pon_mass_onts_down'")
    assert row["enabled"] is False and row["expression"] == "up == 0"  # operator's edit stands


def _rule(**overrides) -> AlarmRule:
    base = dict(group_name="g", alert_name="a", expression="up == 0", for_duration="5m", severity="warning",
               audience="isp", isp_focus="primary", reseller_focus="muted", annotation_summary="s",
               annotation_description="d", enabled=True)
    base.update(overrides)
    return AlarmRule(**base)


def test_rendered_output_is_valid_yaml_grouped_by_group_name():
    text = render([_rule(group_name="g1", alert_name="a1"), _rule(group_name="g1", alert_name="a2"), _rule(group_name="g2", alert_name="a3")])
    doc = yaml.safe_load(text)
    groups = {g["name"]: g["rules"] for g in doc["groups"]}
    assert set(groups) == {"g1", "g2"} and [r["alert"] for r in groups["g1"]] == ["a1", "a2"]


def test_a_disabled_rule_is_left_out_entirely_not_emitted_and_silenced():
    text = render([_rule(alert_name="on", enabled=True), _rule(alert_name="off", enabled=False)])
    doc = yaml.safe_load(text)
    names = {r["alert"] for g in doc["groups"] for r in g["rules"]}
    assert names == {"on"}


def test_rendered_rule_carries_expression_for_labels_and_annotations_unchanged():
    rule = _rule(expression='device_interface_status{iface_type=~"ETH|FE"} == 0', for_duration="5m", severity="critical", audience="both")
    doc = yaml.safe_load(render([rule]))
    entry = doc["groups"][0]["rules"][0]
    assert entry["expr"] == rule.expression and entry["for"] == "5m"
    assert entry["labels"] == {"severity": "critical", "isp_focus": "primary", "reseller_focus": "muted", "audience": "both"}
    assert entry["annotations"] == {"summary": "s", "description": "d"}


def test_a_rule_with_no_audience_omits_the_label_rather_than_writing_null():
    doc = yaml.safe_load(render([_rule(audience=None)]))
    assert "audience" not in doc["groups"][0]["rules"][0]["labels"]


def test_rendering_every_real_rule_produces_one_valid_document():
    rules = [AlarmRule(group_name=g, alert_name=n, expression=e, for_duration=f, severity=s, audience=a,
                       isp_focus=isp, reseller_focus=res, annotation_summary=summ, annotation_description=desc, enabled=en)
             for g, n, e, f, s, a, isp, res, summ, desc, en, internal, legacy_id in ALARM_RULES]
    doc = yaml.safe_load(render(rules))
    all_names = {r["alert"] for g in doc["groups"] for r in g["rules"]}
    assert all_names == {r.alert_name for r in rules}
