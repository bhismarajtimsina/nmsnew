/**
 * Where the topology graph page reads (Plan 27): legacy's `/component/links/topology-tree` and its per-device
 * lookups, or with `VITE_AUTH_BACKEND=cybersathy` the typed client. The page draws legacy's topology shape
 * (devices with `pinger`/`model`/`design`, links with `src_device`/`src_iface`/`utilization`), so the new API's data
 * is turned into that same shape here and the drawing code stays one.
 *
 * Under the new API:
 *   - Links come from `GET /links`, utilisation from `GET /topology/links/utilization`, and devices (ping status, type,
 *     model, group, interface counts) from `GET /devices/overview`, all scoped to the viewer.
 *   - A link end outside the viewer's scope is a placeholder per link (`hidden:<link>:<side>`), as the API itself
 *     draws it: never named, never a device page, never fetched, so two links into the same hidden device do not
 *     even reveal that it is one device.
 *   - Legacy's filters are applied here, with legacy's rules (Topology.php): utilisation above a threshold, devices
 *     deeper than N levels hidden, devices without links hidden; node size grows with the number of downstream links.
 *   - Optical readings, error counters and CPU/memory are not offered by the new API yet, so those tooltip and drawer
 *     lines are simply absent. External LLDP neighbours are not drawn here (their names are set from the LLDP view).
 */
import { ApiError, type ApiClient } from '@/api/client';
import { allPages } from './linksList';

export type Id = number | string;

export interface GraphSettings {
  utilization: number;
  hideLevelsAbove: number;
  hideWithoutLinks: boolean;
}

export interface Topology {
  devices: any[];
  links: any[];
}

export interface GraphSource {
  topology(settings: GraphSettings): Promise<Topology>;
  /** The stored interface row (optical, counters) for one end; null when unknown. */
  ifaceRow(deviceId: Id | undefined, ifaceName: string | undefined): Promise<any>;
  resources(deviceId: Id): Promise<any>;
  /** What the device drawer adds to the graph's own data (description, MAC, serial, interface counts). */
  deviceExtra(deviceId: Id): Promise<any>;
  removeLink(linkId: Id): Promise<void>;
  /** Only legacy draws external neighbours, so only legacy names them. */
  saveExternalName?(id: Id, name: string): Promise<void>;
  errorMessage(error: unknown): string;
}

/** Ids that are not devices: legacy's external LLDP neighbours, and the new API's out-of-scope placeholders. */
export function isPlaceholder(id: Id | null | undefined): boolean {
  return typeof id === 'string' && (id.startsWith('ext:') || id.startsWith('hidden:'));
}

export function isExternal(id: Id | null | undefined): boolean {
  return typeof id === 'string' && id.startsWith('ext:');
}

// --- Legacy ---

export interface LegacyHttp {
  get(path: string, params?: Record<string, unknown>): Promise<{ data: any }>;
  put(path: string, body: unknown): Promise<unknown>;
  delete(path: string): Promise<unknown>;
}

export function legacySource(http: LegacyHttp): GraphSource {
  const rowCache = new Map<string, any>();
  const resourcesCache = new Map<Id, any>();
  const deviceCache = new Map<Id, any>();
  return {
    async topology(s) {
      const { data } = await http.get('/component/links/topology-tree', {
        period: '10m',
        utilization: s.utilization,
        hide_levels_above: s.hideLevelsAbove,
        hide_without_links: s.hideWithoutLinks ? 'true' : 'false',
      });
      const topo = data.data || {};
      return { devices: topo.devices || [], links: topo.links || [] };
    },
    async ifaceRow(deviceId, ifaceName) {
      // External neighbours (string ids) have no interfaces endpoint behind them.
      if (!deviceId || typeof deviceId !== 'number' || !ifaceName) return null;
      const key = `${deviceId}:${ifaceName}`;
      if (rowCache.has(key)) return rowCache.get(key);
      try {
        const { data } = await http.get(`/component/switches/interfaces/${deviceId}`, { from: 'store' });
        const row = (data.data || []).find((r: any) => r.interface?.name === ifaceName) || null;
        rowCache.set(key, row);
        return row;
      } catch {
        return null;
      }
    },
    async resources(deviceId) {
      if (isPlaceholder(deviceId)) return null;
      if (resourcesCache.has(deviceId)) return resourcesCache.get(deviceId);
      try {
        const { data } = await http.get(`/component/switches/resources/${deviceId}`, { from: 'store' });
        resourcesCache.set(deviceId, data.data);
        return data.data;
      } catch {
        resourcesCache.set(deviceId, null);
        return null;
      }
    },
    async deviceExtra(deviceId) {
      if (isPlaceholder(deviceId)) return null;
      if (deviceCache.has(deviceId)) return deviceCache.get(deviceId);
      try {
        const { data } = await http.get(`/device/${deviceId}`);
        deviceCache.set(deviceId, data.data);
        return data.data;
      } catch {
        return null;
      }
    },
    async removeLink(linkId) {
      await http.delete(`/component/links/${linkId}`);
    },
    async saveExternalName(id, name) {
      await http.put('/component/links/external-neighbor-name', { id, name });
    },
    errorMessage(error) {
      return (error as any)?.response?.data?.error?.description || 'Please try again.';
    },
  };
}

// --- New API ---

type State = 'up' | 'down' | 'unknown';
export type ApiEnd = {
  device_id: string | null;
  name: string | null;
  ip: string | null;
  interface: string | null;
  visible: boolean;
  state: State | null;
};
export type ApiLink = { id: string; source: string; state: State; src: ApiEnd; dest: ApiEnd };
export type ApiFigure = { link_id: string; percent: number | null; mbps: number; speed_mbps: number | null };
export type ApiDevice = {
  id: string;
  name: string;
  management_ip: string;
  device_type: string;
  group: { name: string } | null;
  model: { name: string } | null;
  ping: { status: State; latency_ms: number | null; last_checked_at: string | null } | null;
  interfaces: { up: number; down: number };
};

const PING: Record<string, string | null> = { up: 'Up', down: 'Down' };
// An end's state is its interface's status where the link names one (ping first), so it is what that badge shows.
const IFACE: Record<string, string | undefined> = { up: 'Up', down: 'Down' };

/** legacy Topology.php's node size from the number of links below a device. */
export function nodeSize(children: number): number {
  if (!children) return 8;
  const size = 10 + (children / 2) * 1.5;
  if (size > 600) return 42;
  if (size > 400) return 40;
  if (size > 100) return 38;
  if (size > 36) return 36;
  return size;
}

/** Speed in Mbps as legacy wrote it: "1G", "100M". */
export function speedLabel(mbps: number | null): string | null {
  if (!mbps) return null;
  return mbps >= 1000 ? `${mbps / 1000}G` : `${mbps}M`;
}

/** Each node's depth below a root (a node with no upstream link); -1 for a node no root reaches, as legacy keeps it. */
export function levels(nodeIds: string[], links: { from: string; to: string }[]): Map<string, number> {
  const children = new Map<string, string[]>();
  const hasParent = new Set<string>();
  for (const l of links) {
    children.set(l.from, [...(children.get(l.from) ?? []), l.to]);
    hasParent.add(l.to);
  }
  const out = new Map<string, number>(nodeIds.map((id) => [id, -1]));
  const queue = nodeIds.filter((id) => !hasParent.has(id) && children.has(id));
  for (const id of queue) out.set(id, 0);
  while (queue.length) {
    const id = queue.shift()!;
    for (const child of children.get(id) ?? []) {
      if (out.get(child) === -1) {
        out.set(child, out.get(id)! + 1);
        queue.push(child);
      }
    }
  }
  return out;
}

export function buildTopology(
  apiLinks: ApiLink[],
  figures: ApiFigure[],
  devices: ApiDevice[],
  s: GraphSettings,
): Topology {
  const byFigure = new Map(figures.map((f) => [f.link_id, f]));
  const nodes = new Map<string, any>();
  for (const d of devices) {
    nodes.set(d.id, {
      id: d.id,
      name: d.name,
      ip: d.management_ip,
      model: { name: d.model?.name ?? null, type: d.device_type },
      group: d.group ? { name: d.group.name } : null,
      pinger: { status: PING[d.ping?.status ?? ''] ?? null, latency: d.ping?.latency_ms ?? null, last_change: null },
      ifaces_stat: d.interfaces,
      hidden: false,
    });
  }
  const endId = (l: ApiLink, side: 'src' | 'dest') =>
    l[side].visible && l[side].device_id ? l[side].device_id! : `hidden:${l.id}:${side}`;
  let links = apiLinks.map((l) => {
    for (const side of ['src', 'dest'] as const) {
      const id = endId(l, side);
      if (!nodes.has(id)) {
        // A hidden end, or a visible device the overview did not return (beyond its page cap).
        const end = l[side];
        nodes.set(id, {
          id,
          name: end.visible ? end.name : 'Outside your scope',
          ip: end.visible ? end.ip : null,
          model: { name: null, type: null },
          group: null,
          pinger: { status: null, latency: null, last_change: null },
          hidden: !end.visible,
        });
      }
    }
    const figure = byFigure.get(l.id);
    const iface = (end: ApiEnd) =>
      end.interface
        ? { name: end.interface, status: IFACE[end.state ?? ''], description: null, utilization: null }
        : null;
    return {
      id: l.id,
      source: l.source,
      state: l.state,
      src_device: { id: endId(l, 'src'), ip: l.src.ip, name: l.src.visible ? l.src.name : 'Outside your scope' },
      dest_device: { id: endId(l, 'dest'), ip: l.dest.ip, name: l.dest.visible ? l.dest.name : 'Outside your scope' },
      src_iface: iface(l.src),
      dest_iface: iface(l.dest),
      utilization: figure?.percent ?? null,
      utilization_mbps: figure?.mbps ?? null,
      speed: figure?.speed_mbps ?? null,
      speed_humanize: speedLabel(figure?.speed_mbps ?? null),
      editable: l.src.visible && l.dest.visible,
    };
  });

  const depth = levels(
    [...nodes.keys()],
    links.map((l) => ({ from: l.src_device.id, to: l.dest_device.id })),
  );
  let kept = [...nodes.values()];
  if (s.hideLevelsAbove > 0) kept = kept.filter((d) => depth.get(d.id)! <= s.hideLevelsAbove);
  const keptIds = new Set(kept.map((d) => d.id));
  links = links.filter((l) => keptIds.has(l.src_device.id) && keptIds.has(l.dest_device.id));
  if (s.utilization > 0) links = links.filter((l) => l.utilization != null && l.utilization > s.utilization);
  if (s.hideWithoutLinks) {
    const linked = new Set(links.flatMap((l) => [l.src_device.id, l.dest_device.id]));
    kept = kept.filter((d) => linked.has(d.id));
  }
  const childCount = new Map<string, number>();
  for (const l of links) childCount.set(l.src_device.id, (childCount.get(l.src_device.id) ?? 0) + 1);
  return {
    devices: kept.map((d) => ({ ...d, level: depth.get(d.id), design: { size: nodeSize(childCount.get(d.id) ?? 0) } })),
    links,
  };
}

export function newApiSource(client: ApiClient): GraphSource {
  let devices = new Map<string, ApiDevice>();
  return {
    async topology(s) {
      const [links, figures, overview] = await Promise.all([
        client.GET('/api/v1/links'),
        client.GET('/api/v1/topology/links/utilization'),
        allPages(async (offset) => {
          const { data } = await client.GET('/api/v1/devices/overview', { params: { query: { limit: 200, offset } } });
          return data?.items ?? [];
        }),
      ]);
      devices = new Map(overview.map((d) => [d.id, d]));
      return buildTopology(links.data?.items ?? [], figures.data?.items ?? [], overview, s);
    },
    async ifaceRow() {
      return null;
    },
    async resources() {
      return null;
    },
    async deviceExtra(deviceId) {
      const d = devices.get(String(deviceId));
      return d ? { ifaces_stat: d.interfaces } : null;
    },
    async removeLink(linkId) {
      await client.DELETE('/api/v1/links/{link_id}', { params: { path: { link_id: String(linkId) } } });
    },
    errorMessage(error) {
      return error instanceof ApiError ? error.message : 'Please try again.';
    },
  };
}
