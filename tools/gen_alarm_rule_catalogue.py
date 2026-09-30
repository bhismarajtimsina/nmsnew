#!/usr/bin/env python3
"""Regenerate backend/app/registry/alarm_rule_data.py from a live export of the production alarm rule table.

Unlike the other generators in this folder, the source here is not a static file checked into the repository — it is
the live database's own current state, read once and then frozen as checked-in reference data (offline from then on;
re-running this script and re-committing the result is how a future change to the live rules gets carried over).

Produce the export with (read-only, never touches a device):

    docker exec wca-db mysql -uroot -p"$DATABASE_PASSWD" -D "$DATABASE_NAME" --batch --raw -e "
        select group_name, alert_name, expression, \`for\`, severity, audience, isp_focus, reseller_focus,
               annotation_summary, annotation_description, enabled, internal, id
        from c_events_alertmanager_rules order by id" > rules.tsv

Then:  python3 tools/gen_alarm_rule_catalogue.py rules.tsv
"""
from __future__ import annotations

import csv
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "backend/app/registry/alarm_rule_data.py"
REQUIRED = ["group_name", "alert_name", "expression", "for", "severity", "audience", "isp_focus",
            "reseller_focus", "annotation_summary", "annotation_description", "enabled", "internal", "id"]


class SourceError(ValueError):
    pass


def parse(path: Path) -> list[dict]:
    with path.open(newline="", encoding="utf-8") as handle:
        reader = csv.DictReader(handle, delimiter="\t")
        missing = [c for c in REQUIRED if c not in (reader.fieldnames or [])]
        if missing:
            raise SourceError(f"missing column(s): {', '.join(missing)}")
        rows = list(reader)
    if not rows:
        raise SourceError("no rows in the export")
    return rows


def main() -> int:
    if len(sys.argv) != 2:
        print("usage: gen_alarm_rule_catalogue.py <rules.tsv>", file=sys.stderr)
        return 2
    try:
        rows = parse(Path(sys.argv[1]))
    except (OSError, SourceError) as exc:
        print(exc, file=sys.stderr)
        return 1

    out = [
        '"""The real, production alarm rule set, frozen from a live export (see tools/gen_alarm_rule_catalogue.py).',
        "",
        "29 rules, read directly from c_events_alertmanager_rules on 2026-09-29. Every expression is the real PromQL",
        "already running in production, character for character, not retyped from memory. Do not edit by hand;",
        'regenerate from a fresh export if the live rules ever change."""',
        "from __future__ import annotations",
        "",
        "# (group_name, alert_name, expression, for_duration, severity, audience, isp_focus, reseller_focus,",
        "#  annotation_summary, annotation_description, enabled, internal, legacy_id)",
        "ALARM_RULES: list[tuple[str, str, str, str, str, str | None, str, str, str, str, bool, bool, int]] = [",
    ]
    for r in rows:
        audience = r["audience"] or None
        row = (
            r["group_name"], r["alert_name"], r["expression"], r["for"], r["severity"], audience,
            r["isp_focus"], r["reseller_focus"], r["annotation_summary"], r["annotation_description"],
            r["enabled"] == "1", r["internal"] == "1", int(r["id"]),
        )
        out.append(f"    {row!r},")
    out.append("]")
    OUT.write_text("\n".join(out) + "\n")
    print(f"wrote {OUT.relative_to(ROOT)}: {len(rows)} rule(s)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
