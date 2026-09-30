"""Render alarm_rules rows into a Prometheus rule file (decision D-24: Prometheus/Alertmanager stay the rule engine for
metric alarms; PostgreSQL stores the definitions and this renders them). Pure string/YAML generation — no live
Prometheus or Alertmanager is contacted, and nothing here reaches a device.
"""
from __future__ import annotations

from dataclasses import dataclass

import yaml


@dataclass(frozen=True)
class AlarmRule:
    group_name: str
    alert_name: str
    expression: str
    for_duration: str
    severity: str
    audience: str | None
    isp_focus: str
    reseller_focus: str
    annotation_summary: str
    annotation_description: str
    enabled: bool


def render(rules: list[AlarmRule]) -> str:
    """A standard Prometheus rule file: one group per distinct `group_name`, rules in the order given within a group.
    A disabled rule is left out entirely, not emitted-but-silenced, so it truly never evaluates."""
    groups: dict[str, list[dict]] = {}
    for rule in rules:
        if not rule.enabled:
            continue
        labels = {"severity": rule.severity, "isp_focus": rule.isp_focus, "reseller_focus": rule.reseller_focus}
        if rule.audience:
            labels["audience"] = rule.audience
        groups.setdefault(rule.group_name, []).append({
            "alert": rule.alert_name,
            "expr": rule.expression,
            "for": rule.for_duration,
            "labels": labels,
            "annotations": {"summary": rule.annotation_summary, "description": rule.annotation_description},
        })
    document = {"groups": [{"name": name, "rules": entries} for name, entries in groups.items()]}
    return yaml.safe_dump(document, sort_keys=False, default_flow_style=False, width=1000)
