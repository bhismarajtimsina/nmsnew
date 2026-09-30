"""Build a dashboard layout per role from the widgets that already exist.

The grid is 24 columns wide at lg, 12 at md, 8 at sm, 6 at xs, 8 at xxs, which
is what config/default_dashboard.json uses. Widgets are laid out in reading
order for each breakpoint so the first thing on the screen is the first thing
that matters to that console.
"""
import json

WIDTHS = {'lg': 24, 'md': 12, 'sm': 8, 'xs': 6, 'xss': 6, 'xxs': 8}

def layout(rows):
    """rows: list of (widget, height, share) where share is a fraction of the row."""
    out = {}
    for bp, total in WIDTHS.items():
        items, y, i = [], 0, 1
        for row in rows:
            x = 0
            for widget, h, share in row:
                # Narrow screens stack one widget per row.
                w = total if total <= 8 else max(1, round(total * share))
                if x + w > total:
                    w = total - x
                if w <= 0:
                    continue
                items.append({'x': x, 'y': y, 'w': w, 'h': h, 'i': i, 'widget': widget, 'moved': False})
                i += 1
                if total <= 8:
                    y += h
                else:
                    x += w
            if total > 8:
                y += max(h for _, h, _ in row)
        out[bp] = items
    return out

# ISP: device down, then ports and links, then the second panel. No ONT optical.
isp = layout([
    [('pinger_stat', 12, 0.5), ('device_status_chart', 12, 0.5)],
    [('events_table', 26, 0.6), ('events_stat', 26, 0.4)],
    [('high_link_utilization', 14, 0.34), ('events_stat_by_name', 14, 0.33), ('device_calling_errors_stat', 14, 0.33)],
    [('system_stat', 11, 1.0)],
])

# Reseller: their subscribers first, then what to do today. No transport.
reseller = layout([
    [('ont_statuses_pie', 12, 0.5), ('online_onts_chart', 12, 0.5)],
    [('bad_ont_signal', 14, 0.34), ('ont_signal_bar', 14, 0.66)],
    [('unregistered_onts', 14, 0.5), ('events_table', 14, 0.5)],
])

json.dump({'ISP support': isp, 'Reseller': reseller}, open('/tmp/claude-0/-root-nms/51c32123-f17c-4dfd-89e3-fffe7f6f4126/scratchpad/layouts.json', 'w'))
for name, l in (('ISP support', isp), ('Reseller', reseller)):
    print(f"{name}: {len(l['lg'])} widgets at lg, {len(l['xxs'])} at xxs")
    for it in sorted(l['lg'], key=lambda z: (z['y'], z['x'])):
        print(f"   x{it['x']:>2} y{it['y']:>2} w{it['w']:>2} h{it['h']:>2}  {it['widget']}")
