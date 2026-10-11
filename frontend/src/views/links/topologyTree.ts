/**
 * Where the topology tree page reads (Plan 27): the legacy `/component/links/view/tree` call, or with
 * `VITE_AUTH_BACKEND=cybersathy` the typed client against `/api/v1/topology/tree/{device_id}`. Both are turned into
 * one node shape, so the page and its recursive row component do not care which API answered.
 *
 * What differs under the new API:
 *   - A device outside the viewer's scope is a placeholder: shown as such, never named, never expanded.
 *   - A device met a second time (a ring) is a leaf marked as a repeat instead of being expanded again.
 *   - The tree carries the link's state rather than each interface's status, so both interface badges show it.
 *   - "From core device" always has an answer: a device with nothing above it is its own top.
 */
import { ApiError, type ApiClient } from '@/api/client';
import { newApiSource as newLinksSource, type Id } from './linksList';

export type State = 'up' | 'down' | 'unknown';

export interface IfaceBadge {
  name: string;
  description: string | null;
  state: State;
}

export interface TreeView {
  deviceId: Id | null;
  ip: string | null;
  name: string | null;
  visible: boolean;
  state: State;
  /** The link to the parent; null at the root. */
  linkId: Id | null;
  /** The parent's interface on that link. */
  parentIface: IfaceBadge | null;
  /** This device's interface on that link. */
  ownIface: IfaceBadge | null;
  repeat: boolean;
  title: string | null;
  nodes: TreeView[];
}

export interface TreeResult {
  tree: TreeView | null;
  /** Legacy only: no core device above the chosen one. */
  rootNotFound: boolean;
  truncated: boolean;
}

export interface LinkInfo {
  utilization: number | null;
  speed: number | null;
  createdAt: string | null;
}

export interface DeviceOption {
  id: Id;
  label: string;
}

export interface TreeSource {
  devices(): Promise<DeviceOption[]>;
  tree(deviceId: Id, direction: 'up' | 'down'): Promise<TreeResult>;
  linkInfo(linkId: Id): Promise<LinkInfo | null>;
  /** Route query values come back as strings; legacy ids are numbers. */
  parseId(raw: string): Id;
  errorMessage(error: unknown): string;
}

/** Online devices and all devices below a node, as legacy's badge counted them. */
export function countBelow(node: TreeView, onlineOnly = false): number {
  let count = 0;
  for (const child of node.nodes)
    count += (onlineOnly ? (child.state === 'up' ? 1 : 0) : 1) + countBelow(child, onlineOnly);
  return count;
}

export function containsDevice(node: TreeView, deviceId: Id | null | undefined): boolean {
  if (deviceId == null) return false;
  return node.nodes.some((child) => String(child.deviceId) === String(deviceId) || containsDevice(child, deviceId));
}

export interface LegacyHttp {
  get(path: string, params?: Record<string, unknown>): Promise<{ data: any }>;
}

const legacyState = (status?: string): State => (status === 'Up' || status === 'Online' ? 'up' : 'down');

export function fromLegacyNode(n: any): TreeView {
  const badge = (name?: string, description?: string, status?: string): IfaceBadge | null =>
    name ? { name, description: description || null, state: legacyState(status) } : null;
  return {
    deviceId: n.device_id,
    ip: n.device_ip,
    name: n.name || null,
    visible: true,
    state: n.latency > 0 ? 'up' : 'down',
    linkId: n.link_id ?? null,
    parentIface: badge(n.src_iface_name, n.src_iface_description, n.src_iface_status),
    ownIface: badge(n.dest_iface_name, n.dest_iface_description, n.dest_iface_status),
    repeat: false,
    title: n.model_name || null,
    nodes: (n.nodes || []).map(fromLegacyNode),
  };
}

export function legacySource(http: LegacyHttp): TreeSource {
  return {
    async devices() {
      const { data } = await http.get('/device/options');
      return (data.data || []).map((d: any) => ({ id: d.id, label: d.display_name }));
    },
    async tree(deviceId, direction) {
      try {
        const { data } = await http.get('/component/links/view/tree', { device_id: deviceId, direction });
        return { tree: data.data?.tree ? fromLegacyNode(data.data.tree) : null, rootNotFound: false, truncated: false };
      } catch (err: any) {
        // Legacy means to answer 400 ROOT_DEVICE_NOT_FOUND when nothing is above the device, but the exception escapes
        // as a raw 500; either way "From core device" says so instead of failing.
        if (direction === 'up' && (err?.response?.status === 400 || err?.response?.status === 500)) {
          return { tree: null, rootNotFound: true, truncated: false };
        }
        throw err;
      }
    },
    async linkInfo(linkId) {
      try {
        const { data } = await http.get(`/component/links/${linkId}`, { with_stat: 'yes' });
        const link = data.data;
        return link
          ? { utilization: link.utilization ?? null, speed: link.speed ?? null, createdAt: link.created_at ?? null }
          : null;
      } catch {
        return null;
      }
    },
    parseId: (raw) => Number(raw),
    errorMessage(error) {
      return (error as any)?.response?.data?.error?.description || 'Please try again.';
    },
  };
}

type ApiNode = {
  id: string;
  name: string | null;
  ip: string | null;
  visible: boolean;
  state: State | null;
  link_id?: string | null;
  link_state?: State | null;
  uplink_interface?: string | null;
  downlink_interface?: string | null;
  repeat?: boolean;
  nodes: ApiNode[];
};

export function fromApiNode(n: ApiNode): TreeView {
  const linkState: State = n.link_state ?? 'unknown';
  const badge = (name?: string | null): IfaceBadge | null =>
    name ? { name, description: null, state: linkState } : null;
  return {
    // A hidden device's id is a per-link placeholder, not a device id.
    deviceId: n.visible ? n.id : null,
    ip: n.ip,
    name: n.name,
    visible: n.visible,
    state: n.state ?? 'unknown',
    linkId: n.link_id ?? null,
    parentIface: badge(n.downlink_interface),
    ownIface: badge(n.uplink_interface),
    repeat: n.repeat ?? false,
    title: null,
    nodes: n.nodes.map(fromApiNode),
  };
}

export function newApiSource(client: ApiClient): TreeSource {
  return {
    async devices() {
      return (await newLinksSource(client).devices()).map((d) => ({
        id: d.id,
        label: d.ip ? `${d.ip} (${d.name})` : d.name,
      }));
    },
    async tree(deviceId, direction) {
      const { data } = await client.GET('/api/v1/topology/tree/{device_id}', {
        params: { path: { device_id: String(deviceId) }, query: { direction } },
      });
      return { tree: data ? fromApiNode(data.tree) : null, rootNotFound: false, truncated: data?.truncated ?? false };
    },
    async linkInfo(linkId) {
      try {
        const { data: link } = await client.GET('/api/v1/links/{link_id}', {
          params: { path: { link_id: String(linkId) } },
        });
        if (!link) return null;
        const end = link.src.visible ? link.src : link.dest;
        const { data } = await client.GET('/api/v1/topology/links/utilization', {
          params: { query: { device_id: end.device_id ?? undefined } },
        });
        const figure = data?.items.find((f) => f.link_id === link.id);
        return { utilization: figure?.percent ?? null, speed: figure?.speed_mbps ?? null, createdAt: link.created_at };
      } catch {
        return null;
      }
    },
    parseId: (raw) => raw,
    errorMessage(error) {
      return error instanceof ApiError ? error.message : 'Please try again.';
    },
  };
}
