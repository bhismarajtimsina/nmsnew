/**
 * Where the links page reads and writes (Plan 27): the legacy `/component/links` calls, or with
 * `VITE_AUTH_BACKEND=cybersathy` the typed client against `/api/v1/links`. Both give the page the same row shape and
 * the same operations, so the page itself does not care which API answered.
 *
 * What differs, and why:
 *   - The new API returns every link the caller may see in one call, with each end masked when it is outside the
 *     caller's scope, so filtering and paging happen here rather than on the server.
 *   - Its utilisation period is a server setting (legacy's `LINKS_UTILIZATION_CALCULATE_PERIOD`), not a choice per
 *     request, so the period is shown but cannot be changed.
 *   - "Only high utilization" means what it meant in legacy: links with an open `high_link_utilization` alarm.
 */
import { ApiError, type ApiClient } from '@/api/client';

export type Id = number | string;

export interface LinkEnd {
  /** Null when the end is a device outside the caller's scope (new API only). */
  deviceId: Id | null;
  ip: string | null;
  name: string | null;
  interfaceId: Id | null;
  interface: string | null;
  visible: boolean;
}

export interface LinkRow {
  id: Id;
  src: LinkEnd;
  dest: LinkEnd;
  /** up, down or unknown; legacy's list has no link state. */
  state: 'up' | 'down' | 'unknown' | null;
  utilization: number | null;
  utilizationMbps: number | null;
  /** Mbps. */
  speed: number | null;
  createdAt: string;
  /** Deleting needs both ends in scope. */
  editable: boolean;
}

export interface ListQuery {
  devices: Id[];
  period: string;
  highUtilization: boolean;
  page: number;
  limit: number;
}

export interface Periods {
  options: string[];
  current: string;
  /** False when the server decides the period. */
  selectable: boolean;
}

export interface Option {
  id: Id;
  name: string;
  ip: string;
}

export interface LinkInput {
  srcDevice: Id;
  destDevice: Id;
  srcIface?: Id;
  destIface?: Id;
}

export interface LinksSource {
  list(query: ListQuery): Promise<{ rows: LinkRow[]; total: number }>;
  periods(): Promise<Periods>;
  devices(): Promise<Option[]>;
  interfaces(deviceId: Id): Promise<{ id: Id; name: string }[]>;
  create(input: LinkInput): Promise<void>;
  remove(id: Id): Promise<void>;
  errorMessage(error: unknown): string;
}

/** The legacy DataService, as far as this page uses it. */
export interface LegacyHttp {
  get(path: string, params?: Record<string, unknown>): Promise<{ data: any }>;
  post(path: string, body: unknown): Promise<unknown>;
  put(path: string, body: unknown): Promise<{ data: any }>;
  delete(path: string): Promise<unknown>;
}

export function fromLegacyLink(r: any): LinkRow {
  const end = (device: any, iface: any): LinkEnd => ({
    deviceId: device.id,
    ip: device.ip,
    name: device.name,
    interfaceId: iface?.id ?? null,
    interface: iface?.name ?? null,
    visible: true,
  });
  return {
    id: r.id,
    src: end(r.src_device, r.src_iface),
    dest: end(r.dest_device, r.dest_iface),
    state: null,
    utilization: r.utilization ?? null,
    utilizationMbps: r.utilization_mbps ?? null,
    speed: r.speed ?? null,
    createdAt: r.created_at,
    editable: true,
  };
}

export function legacySource(http: LegacyHttp): LinksSource {
  return {
    async list(q) {
      const { data } = await http.put('/component/links/view/list', {
        query: {},
        limit: q.limit,
        page: q.page,
        ascending: 0,
        byColumn: 1,
        filter: { devices: q.devices.map((id) => ({ id })), period: q.period, high_utilization: q.highUtilization },
      });
      const rows = ((data.data as unknown[]) || []).map(fromLegacyLink);
      return { rows, total: data.meta?.total ?? data.meta?.total_records ?? rows.length };
    },
    async periods() {
      try {
        const { data } = await http.get('/component/links/options/configuration');
        const options = data.data?.periods || ['15m'];
        return { options, current: data.data?.calc_util_period || options[0], selectable: true };
      } catch {
        return { options: ['15m'], current: '15m', selectable: true };
      }
    },
    async devices() {
      const { data } = await http.get('/device/options');
      return (data.data || []).map((d: any) => ({ id: d.id, name: d.name, ip: d.ip }));
    },
    async interfaces(deviceId) {
      const { data } = await http.get('/device-interface', { device_id: deviceId, limit: 999999 });
      return (data.data || []).map((i: any) => ({ id: i.id, name: i.name }));
    },
    async create(input) {
      await http.post('/component/links', {
        src_device: { id: input.srcDevice },
        dest_device: { id: input.destDevice },
        src_iface: input.srcIface ? { id: input.srcIface } : undefined,
        dest_iface: input.destIface ? { id: input.destIface } : undefined,
      });
    },
    async remove(id) {
      await http.delete(`/component/links/${id}`);
    },
    errorMessage(error) {
      return (error as any)?.response?.data?.error?.description || 'Please try again.';
    },
  };
}

const PAGE = 200; // the new API's largest page for devices and interfaces
const MAX_ROWS = 5000; // the same cap the links list itself has

/** Every page of a paged list, up to MAX_ROWS. */
export async function allPages<T>(fetchPage: (offset: number) => Promise<T[]>): Promise<T[]> {
  const out: T[] = [];
  for (let offset = 0; offset < MAX_ROWS; offset += PAGE) {
    const page = await fetchPage(offset);
    out.push(...page);
    if (page.length < PAGE) break;
  }
  return out;
}

type ApiLink = { id: string; state: 'up' | 'down' | 'unknown'; created_at: string; src: ApiEnd; dest: ApiEnd };
type ApiEnd = {
  device_id: string | null;
  name: string | null;
  ip: string | null;
  interface_id: string | null;
  interface: string | null;
  visible: boolean;
};
type ApiFigure = { link_id: string; percent: number | null; mbps: number; speed_mbps: number | null };

export function fromApiLink(link: ApiLink, figure?: ApiFigure): LinkRow {
  const end = (e: ApiEnd): LinkEnd => ({
    deviceId: e.device_id,
    ip: e.ip,
    name: e.name,
    interfaceId: e.interface_id,
    interface: e.interface,
    visible: e.visible,
  });
  return {
    id: link.id,
    src: end(link.src),
    dest: end(link.dest),
    state: link.state,
    utilization: figure?.percent ?? null,
    utilizationMbps: figure?.mbps ?? null,
    speed: figure?.speed_mbps ?? null,
    createdAt: link.created_at,
    editable: link.src.visible && link.dest.visible,
  };
}

/** "15m", "1h": how legacy wrote a period, from the minutes the new API reports. */
export function periodLabel(minutes: number): string {
  return minutes % 60 === 0 ? `${minutes / 60}h` : `${minutes}m`;
}

export function newApiSource(client: ApiClient): LinksSource {
  let minutes: number | null = null;
  return {
    async list(q) {
      const [links, figures] = await Promise.all([
        client.GET('/api/v1/links'),
        client.GET('/api/v1/topology/links/utilization'),
      ]);
      minutes = figures.data?.minutes ?? minutes;
      const byLink = new Map((figures.data?.items ?? []).map((f) => [f.link_id, f]));
      let rows = (links.data?.items ?? []).map((l) => fromApiLink(l, byLink.get(l.id)));
      if (q.devices.length) {
        const wanted = new Set(q.devices.map(String));
        rows = rows.filter((r) => wanted.has(String(r.src.deviceId)) || wanted.has(String(r.dest.deviceId)));
      }
      if (q.highUtilization) {
        const alarmed = await openUtilizationAlarms(client);
        rows = rows.filter((r) => alarmed.has(String(r.id)));
      }
      const start = (q.page - 1) * q.limit;
      return { rows: rows.slice(start, start + q.limit), total: rows.length };
    },
    async periods() {
      if (minutes === null) {
        const { data } = await client.GET('/api/v1/topology/links/utilization');
        minutes = data?.minutes ?? 15;
      }
      const label = periodLabel(minutes);
      return { options: [label], current: label, selectable: false };
    },
    async devices() {
      return allPages(async (offset) => {
        const { data } = await client.GET('/api/v1/devices', { params: { query: { limit: PAGE, offset } } });
        return (data?.items ?? []).map((d) => ({ id: d.id, name: d.name, ip: d.management_ip ?? '' }));
      });
    },
    async interfaces(deviceId) {
      return allPages(async (offset) => {
        const { data } = await client.GET('/api/v1/devices/{device_id}/interfaces', {
          params: { path: { device_id: String(deviceId) }, query: { limit: PAGE, offset } },
        });
        return (data?.items ?? []).map((i) => ({ id: i.id, name: i.name }));
      });
    },
    async create(input) {
      await client.POST('/api/v1/links', {
        body: {
          src_device_id: String(input.srcDevice),
          dest_device_id: String(input.destDevice),
          src_interface_id: input.srcIface ? String(input.srcIface) : null,
          dest_interface_id: input.destIface ? String(input.destIface) : null,
        },
      });
    },
    async remove(id) {
      await client.DELETE('/api/v1/links/{link_id}', { params: { path: { link_id: String(id) } } });
    },
    errorMessage(error) {
      return error instanceof ApiError ? error.message : 'Please try again.';
    },
  };
}

/** Link ids with an open high_link_utilization alarm, as legacy's filter used. */
async function openUtilizationAlarms(client: ApiClient): Promise<Set<unknown>> {
  const events = await allPages(async (offset) => {
    const { data } = await client.GET('/api/v1/events', {
      params: { query: { name: 'high_link_utilization', open_only: true, limit: PAGE, offset } },
    });
    return data?.items ?? [];
  });
  // An event without a link_id label adds undefined, which no link id equals.
  return new Set(events.map((e) => (e.labels as Record<string, unknown> | null)?.link_id));
}
