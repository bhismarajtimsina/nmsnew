import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it, vi } from 'vitest';
import { createApiClient } from '@/api/client';
import { errorMessage, loadDashboards, loadWidget, pickDashboard, supported, type DashboardInfo } from './dashboards';

function client(routes: Record<string, (url: URL) => unknown>, status = 200) {
  const seen: string[] = [];
  const fetchImpl = vi.fn(async (request: Request) => {
    const url = new URL(request.url);
    seen.push(url.pathname + url.search);
    const handler = routes[url.pathname] as ((url: URL) => unknown) | undefined;
    return new Response(JSON.stringify(handler ? handler(url) : { detail: 'You do not have permission to do this' }), {
      status: handler ? status : 403,
      headers: { 'Content-Type': 'application/json' },
    });
  }) as unknown as typeof fetch;
  return {
    api: createApiClient({
      baseUrl: 'http://nms.test',
      store: { get: () => 't', remove: () => {} },
      onUnauthorized: () => {},
      fetch: fetchImpl,
    }),
    seen,
  };
}

const W = '/api/v1/dashboards/widgets';
const device = { id: 'd1', name: 'sw-1', ip: '10.0.0.1' };

describe('loading', () => {
  it('maps the dashboards and their widgets', async () => {
    const { api } = client({
      '/api/v1/dashboards': () => ({
        default: 'noc',
        dashboards: [
          {
            key: 'noc',
            title: 'Network operations',
            noc_wide: true,
            widgets: [
              {
                key: 'device_status',
                title: 'Device reachability',
                state: 'live',
                endpoint: `${W}/device-status`,
                pending_reason: null,
              },
              {
                key: 'ont_statuses',
                title: 'ONU statuses',
                state: 'pending',
                endpoint: null,
                pending_reason: 'Plan 14',
              },
            ],
          },
        ],
      }),
    });
    expect(await loadDashboards(api)).toEqual({
      default: 'noc',
      dashboards: [
        {
          key: 'noc',
          title: 'Network operations',
          widgets: [
            {
              key: 'device_status',
              title: 'Device reachability',
              state: 'live',
              endpoint: `${W}/device-status`,
              pendingReason: null,
            },
            { key: 'ont_statuses', title: 'ONU statuses', state: 'pending', endpoint: null, pendingReason: 'Plan 14' },
          ],
        },
      ],
    });
  });

  it('picks the dashboard asked for, else the default, else the first', () => {
    const list = ['a', 'b', 'c'].map((key) => ({ key, title: key, widgets: [] })) as DashboardInfo[];
    expect(pickDashboard(list, 'c', 'b')?.key).toBe('c');
    expect(pickDashboard(list, 'zzz', 'b')?.key).toBe('b');
    expect(pickDashboard(list, null, null)?.key).toBe('a');
    expect(pickDashboard([], 'a', 'a')).toBeNull();
  });
});

describe('widget displays', () => {
  it('device reachability is four stats, down marked bad only when there are some', async () => {
    const { api } = client({ [`${W}/device-status`]: () => ({ up: 5, down: 2, never_checked: 1, total: 8 }) });
    expect(await loadWidget(api, 'device_status')).toEqual({
      kind: 'stats',
      stats: [
        { label: 'Up', value: 5, tone: 'good' },
        { label: 'Down', value: 2, tone: 'bad' },
        { label: 'Never checked', value: 1 },
        { label: 'Total', value: 8 },
      ],
    });
    const quiet = client({ [`${W}/device-status`]: () => ({ up: 1, down: 0, never_checked: 0, total: 1 }) });
    expect(((await loadWidget(quiet.api, 'device_status')) as any).stats[1].tone).toBeUndefined();
  });

  it('event counts are bars', async () => {
    const { api } = client({
      [`${W}/events-by-severity`]: () => ({ items: [{ severity: 'critical', count: 3 }] }),
      [`${W}/events-by-name`]: () => ({ items: [{ name: 'pinger_host_down', count: 7 }] }),
    });
    expect(await loadWidget(api, 'events_by_severity')).toEqual({
      kind: 'bars',
      items: [{ label: 'critical', value: 3 }],
    });
    expect(await loadWidget(api, 'events_by_name')).toEqual({
      kind: 'bars',
      items: [{ label: 'pinger_host_down', value: 7 }],
    });
  });

  it('the events table asks for the eight latest open events', async () => {
    const { api, seen } = client({
      '/api/v1/events': () => ({
        items: [{ name: 'link_down', severity: 'warning', device_name: null, occurred_at: '2026-10-17T10:00:00.123Z' }],
        total: 1,
      }),
    });
    expect(await loadWidget(api, 'events_table')).toEqual({
      kind: 'table',
      columns: ['Event', 'Severity', 'Device', 'Since'],
      rows: [['link_down', 'warning', '—', '2026-10-17 10:00:00']],
    });
    const query = new URLSearchParams(seen[0].split('?')[1]);
    expect([query.get('open_only'), query.get('limit')]).toEqual(['true', '8']);
  });

  it('ports down say when only part of the list is shown', async () => {
    const port = { id: 'i1', name: 'Gi0/1', alias: 'uplink', oper_status: 'down', down_since: null, device };
    const { api } = client({ [`${W}/ports-down`]: () => ({ items: [port], total: 3, limit: 1 }) });
    expect(await loadWidget(api, 'ports_down')).toEqual({
      kind: 'table',
      columns: ['Port', 'Device', 'Status', 'Down since'],
      rows: [['Gi0/1 (uplink)', 'sw-1 (10.0.0.1)', 'down', '—']],
      note: '1 of 3 shown',
    });
    const all = client({
      [`${W}/ports-down`]: () => ({ items: [{ ...port, alias: null, oper_status: null }], total: 1, limit: 50 }),
    });
    expect(await loadWidget(all.api, 'ports_down')).toMatchObject({
      rows: [['Gi0/1', 'sw-1 (10.0.0.1)', '—', '—']],
      note: undefined,
    });
  });

  it('poller health and polling errors carry their totals in a note', async () => {
    const { api } = client({
      [`${W}/poller-health`]: () => ({
        window_hours: 24,
        ok: 10,
        error: 2,
        timeout: 1,
        skipped: 0,
        truncated: 3,
        failing: [
          {
            device: { id: 'd2', name: 'olt', ip: null },
            profile: 'system_basic',
            failed_runs: 4,
            last_failure: '2026-10-17T09:00:00Z',
          },
        ],
      }),
      [`${W}/error-calling-by-device`]: () => ({
        errors: 5,
        not_responding: 2,
        limit: 30,
        devices: [{ device, errors: 5, not_responding: 2 }],
      }),
    });
    expect(await loadWidget(api, 'poller_health')).toEqual({
      kind: 'table',
      columns: ['Device', 'Profile', 'Failed runs', 'Last failure'],
      rows: [['olt', 'system_basic', 4, '2026-10-17 09:00:00']],
      note: 'Last 24 h: 10 ok, 2 errors, 1 timeouts, 0 skipped, 3 truncated',
    });
    expect(await loadWidget(api, 'error_calling_by_device')).toEqual({
      kind: 'table',
      columns: ['Device', 'Errors', 'Not responding'],
      rows: [['sw-1 (10.0.0.1)', 5, 2]],
      note: '5 errors, 2 not responding',
    });
  });

  it('the system summary shows users and roles only when the server gives them', async () => {
    const full = client({
      [`${W}/system-stat`]: () => ({ devices: 9, interfaces: 90, device_groups: 3, users: 4, roles: 7 }),
    });
    expect(((await loadWidget(full.api, 'system_stat')) as any).stats.map((s: any) => s.label)).toEqual([
      'Devices',
      'Interfaces',
      'Device groups',
      'Users',
      'Roles',
    ]);
    const reseller = client({
      [`${W}/system-stat`]: () => ({ devices: 2, interfaces: 20, device_groups: 1, users: null, roles: null }),
    });
    expect(((await loadWidget(reseller.api, 'system_stat')) as any).stats.map((s: any) => s.label)).toEqual([
      'Devices',
      'Interfaces',
      'Device groups',
    ]);
    const zero = client({
      [`${W}/system-stat`]: () => ({ devices: 0, interfaces: 0, device_groups: 0, users: 0, roles: 0 }),
    });
    expect(((await loadWidget(zero.api, 'system_stat')) as any).stats).toHaveLength(5); // zero is a number, not absent
  });

  it('actions and user activity are tables, with the system named when no one acted', async () => {
    const { api } = client({
      [`${W}/latest-system-actions`]: () => ({
        items: [
          {
            id: 'a',
            action: 'link.create',
            actor: null,
            resource_type: null,
            resource_id: null,
            occurred_at: '2026-10-17T08:00:00Z',
          },
        ],
      }),
      [`${W}/last-user-activity`]: () => ({
        items: [
          { id: 'u', username: 'op', display_name: '', role: 'ISP NOC', last_login_at: null, last_activity_at: null },
        ],
      }),
    });
    expect(await loadWidget(api, 'latest_system_actions')).toMatchObject({
      rows: [['link.create', 'system', '—', '2026-10-17 08:00:00']],
    });
    expect(await loadWidget(api, 'last_user_activity')).toMatchObject({ rows: [['op', 'ISP NOC', '—']] });
  });

  it('busiest links never name an end outside the scope', async () => {
    const end = (visible: boolean, name: string | null, iface: string | null) => ({
      device_id: visible ? 'd' : null,
      name,
      ip: null,
      interface: iface,
      visible,
    });
    const { api } = client({
      [`${W}/high-link-utilization`]: () => ({
        alarmed: 1,
        minutes: 15,
        items: [
          {
            link_id: 'L1',
            percent: 91.5,
            mbps: 915,
            speed_mbps: 1000,
            src: end(true, 'acc-1', 'Gi0/1'),
            dest: end(false, null, null),
          },
        ],
      }),
    });
    expect(await loadWidget(api, 'high_link_utilization')).toEqual({
      kind: 'table',
      columns: ['From', 'To', 'Utilisation', 'Mbps'],
      rows: [['acc-1 Gi0/1', 'Outside your scope', '91.5%', 915]],
      note: '1 link with an open high-utilisation alarm · last 15 min',
    });
    const two = client({ [`${W}/high-link-utilization`]: () => ({ alarmed: 2, minutes: 60, items: [] }) });
    expect(((await loadWidget(two.api, 'high_link_utilization')) as any).note).toBe(
      '2 links with an open high-utilisation alarm · last 60 min',
    );
  });

  it('an unknown widget is unsupported and a refused one reports the reason', async () => {
    const { api } = client({});
    expect([supported('device_status'), supported('ont_statuses')]).toEqual([true, false]);
    await expect(loadWidget(api, 'ont_statuses')).rejects.toThrow('not supported');
    expect(errorMessage(await loadWidget(api, 'device_status').catch((e) => e))).toBe(
      'You do not have permission to do this',
    );
    expect(errorMessage('weird')).toBe('Could not load this widget');
    expect(errorMessage(new Error('This widget is not supported by this page yet'))).toBe(
      'This widget is not supported by this page yet',
    );
  });
});

describe('the page', () => {
  const routes = readFileSync(resolve(__dirname, '../router/wcaRoutes.ts'), 'utf8');
  const page = readFileSync(resolve(__dirname, 'DashboardNewPage.vue'), 'utf8');

  it('is what the new login gets for the dashboard route; legacy keeps its page', () => {
    expect(routes).toContain(
      "authBackend() === 'cybersathy' ? import('@/views/DashboardNewPage.vue') : import('@/views/DashboardPage.vue')",
    );
  });

  it('loads only live, supported widgets and shows a pending reason instead', () => {
    expect(page).toContain(".filter((w) => w.state === 'live' && supported(w.key))");
    expect(page).toContain('Coming later: {{ w.pendingReason }}');
  });
});
