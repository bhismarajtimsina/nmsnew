/**
 * The role dashboards for the new login (Plans 23 and 25): which dashboards the viewer has, each widget's data from
 * its own scoped endpoint, and one display shape per widget so the page renders them all the same way.
 *
 * The server decides everything that matters for access: which dashboards and widgets a viewer gets, and what each
 * widget counts inside their scope. This module only fetches and shapes. A widget the server lists as pending shows
 * its reason; a widget whose endpoint fails shows that on its own card, never failing the page.
 */
import type { ApiClient } from '@/api/client';

export interface WidgetInfo {
  key: string;
  title: string;
  state: string; // live | pending
  endpoint: string | null;
  pendingReason: string | null;
}

export interface DashboardInfo {
  key: string;
  title: string;
  widgets: WidgetInfo[];
}

export interface Stat {
  label: string;
  value: number | string;
  tone?: 'good' | 'bad' | 'warn';
}

export type Display =
  | { kind: 'stats'; stats: Stat[] }
  | { kind: 'bars'; items: { label: string; value: number }[] }
  | { kind: 'table'; columns: string[]; rows: (string | number)[][]; note?: string };

export async function loadDashboards(
  client: ApiClient,
): Promise<{ default: string | null; dashboards: DashboardInfo[] }> {
  const { data } = await client.GET('/api/v1/dashboards');
  return {
    default: data?.default ?? null,
    dashboards: (data?.dashboards ?? []).map((d) => ({
      key: d.key,
      title: d.title,
      widgets: d.widgets.map((w) => ({
        key: w.key,
        title: w.title,
        state: w.state,
        endpoint: w.endpoint,
        pendingReason: w.pending_reason,
      })),
    })),
  };
}

const dev = (d: { name: string; ip?: string | null } | null | undefined) =>
  d ? (d.ip ? `${d.name} (${d.ip})` : d.name) : '—';
const when = (iso: string | null | undefined) => (iso ? iso.replace('T', ' ').slice(0, 19) : '—');

/** Each live widget's typed call and its display shape. A widget not listed here is shown as not supported yet. */
const WIDGETS: Record<string, (client: ApiClient) => Promise<Display>> = {
  async device_status(client) {
    const { data } = await client.GET('/api/v1/dashboards/widgets/device-status');
    const d = data!;
    return {
      kind: 'stats',
      stats: [
        { label: 'Up', value: d.up, tone: 'good' },
        { label: 'Down', value: d.down, tone: d.down ? 'bad' : undefined },
        { label: 'Never checked', value: d.never_checked },
        { label: 'Total', value: d.total },
      ],
    };
  },
  async events_by_severity(client) {
    const { data } = await client.GET('/api/v1/dashboards/widgets/events-by-severity');
    return { kind: 'bars', items: (data?.items ?? []).map((i) => ({ label: i.severity, value: i.count })) };
  },
  async events_by_name(client) {
    const { data } = await client.GET('/api/v1/dashboards/widgets/events-by-name');
    return { kind: 'bars', items: (data?.items ?? []).map((i) => ({ label: i.name, value: i.count })) };
  },
  async events_table(client) {
    const { data } = await client.GET('/api/v1/events', { params: { query: { open_only: true, limit: 8 } } });
    return {
      kind: 'table',
      columns: ['Event', 'Severity', 'Device', 'Since'],
      rows: (data?.items ?? []).map((e) => [e.name, e.severity, e.device_name ?? '—', when(e.occurred_at)]),
    };
  },
  async ports_down(client) {
    const { data } = await client.GET('/api/v1/dashboards/widgets/ports-down');
    const d = data!;
    return {
      kind: 'table',
      columns: ['Port', 'Device', 'Status', 'Down since'],
      rows: d.items.map((p) => [
        p.alias ? `${p.name} (${p.alias})` : p.name,
        dev(p.device),
        p.oper_status ?? '—',
        when(p.down_since),
      ]),
      note: d.total > d.items.length ? `${d.items.length} of ${d.total} shown` : undefined,
    };
  },
  async poller_health(client) {
    const { data } = await client.GET('/api/v1/dashboards/widgets/poller-health');
    const d = data!;
    return {
      kind: 'table',
      columns: ['Device', 'Profile', 'Failed runs', 'Last failure'],
      rows: d.failing.map((f) => [dev(f.device), f.profile, f.failed_runs, when(f.last_failure)]),
      note: `Last ${d.window_hours} h: ${d.ok} ok, ${d.error} errors, ${d.timeout} timeouts, ${d.skipped} skipped, ${d.truncated} truncated`,
    };
  },
  async error_calling_by_device(client) {
    const { data } = await client.GET('/api/v1/dashboards/widgets/error-calling-by-device');
    const d = data!;
    return {
      kind: 'table',
      columns: ['Device', 'Errors', 'Not responding'],
      rows: d.devices.map((x) => [dev(x.device), x.errors, x.not_responding]),
      note: `${d.errors} errors, ${d.not_responding} not responding`,
    };
  },
  async system_stat(client) {
    const { data } = await client.GET('/api/v1/dashboards/widgets/system-stat');
    const d = data!;
    const stats: Stat[] = [
      { label: 'Devices', value: d.devices },
      { label: 'Interfaces', value: d.interfaces },
      { label: 'Device groups', value: d.device_groups },
    ];
    // Users and roles come back only for a viewer who may see them; absent is not zero.
    if (d.users != null) stats.push({ label: 'Users', value: d.users });
    if (d.roles != null) stats.push({ label: 'Roles', value: d.roles });
    return { kind: 'stats', stats };
  },
  async latest_system_actions(client) {
    const { data } = await client.GET('/api/v1/dashboards/widgets/latest-system-actions');
    return {
      kind: 'table',
      columns: ['Action', 'By', 'On', 'When'],
      rows: (data?.items ?? []).map((a) => [
        a.action,
        a.actor ?? 'system',
        a.resource_type ?? '—',
        when(a.occurred_at),
      ]),
    };
  },
  async last_user_activity(client) {
    const { data } = await client.GET('/api/v1/dashboards/widgets/last-user-activity');
    return {
      kind: 'table',
      columns: ['User', 'Role', 'Last active'],
      rows: (data?.items ?? []).map((u) => [u.display_name || u.username, u.role, when(u.last_activity_at)]),
    };
  },
  async high_link_utilization(client) {
    const { data } = await client.GET('/api/v1/dashboards/widgets/high-link-utilization');
    const d = data!;
    const end = (e: { visible: boolean; name: string | null; interface: string | null }) =>
      e.visible ? `${e.name ?? '?'}${e.interface ? ` ${e.interface}` : ''}` : 'Outside your scope';
    return {
      kind: 'table',
      columns: ['From', 'To', 'Utilisation', 'Mbps'],
      rows: d.items.map((l) => [end(l.src), end(l.dest), `${l.percent}%`, l.mbps]),
      note: `${d.alarmed} link${d.alarmed === 1 ? '' : 's'} with an open high-utilisation alarm · last ${
        d.minutes
      } min`,
    };
  },
};

export function supported(key: string): boolean {
  return key in WIDGETS;
}

export async function loadWidget(client: ApiClient, key: string): Promise<Display> {
  const load = WIDGETS[key];
  if (!load) throw new Error('This widget is not supported by this page yet');
  return load(client);
}

export function errorMessage(error: unknown): string {
  return error instanceof Error ? error.message : 'Could not load this widget'; // ApiError carries the API's own words
}

/** The dashboard to open: the one asked for if the viewer has it, else the server's default, else the first. */
export function pickDashboard(
  dashboards: DashboardInfo[],
  wanted: string | null | undefined,
  fallback: string | null,
): DashboardInfo | null {
  return (
    dashboards.find((d) => d.key === wanted) ?? dashboards.find((d) => d.key === fallback) ?? dashboards[0] ?? null
  );
}
