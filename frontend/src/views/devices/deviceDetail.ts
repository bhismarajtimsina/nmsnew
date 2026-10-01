/**
 * Data for the device detail page under the new login (Plan 25). Everything here reads the CyberSathy-NMS database
 * through the API; nothing asks a device for fresh data. The legacy page (DeviceDetailPage.vue) is unchanged and
 * still serves the default build.
 *
 * Sections the user has no permission for are not requested at all, and a section that fails does not take the page
 * down with it: only the device itself is required.
 */
import { ApiError, type ApiClient } from '@/api/client';
import type { components } from '@/api/schema';

type Schemas = components['schemas'];
export type DeviceOverview = Schemas['DeviceOverviewOut'];
export type Device = Schemas['DeviceOut'];
export type Interface = Schemas['InterfaceOut'];
export type DeviceEvent = Schemas['EventOut'];
export type PollHistory = Schemas['PollHistory'];

/** One optional section: its rows, or why there are none. */
export type Section<T> =
  | { state: 'ok'; data: T }
  | { state: 'forbidden' } // the user lacks the permission; not requested
  | { state: 'error'; message: string };

export interface DeviceDetail {
  overview: DeviceOverview;
  device: Device;
}

export const SECTION_PERMISSIONS = {
  interfaces: 'interfaces.view',
  events: 'events.view',
  polls: 'pollers.view',
} as const;

export type SectionName = keyof typeof SECTION_PERMISSIONS;

/** The device and its header facts. Throws ApiError (404 when missing or out of scope), which the page shows. */
export async function loadDevice(client: ApiClient, id: string): Promise<DeviceDetail> {
  const path = { params: { path: { device_id: id } } };
  const [overview, device] = await Promise.all([
    client.GET('/api/v1/devices/{device_id}/overview', path),
    client.GET('/api/v1/devices/{device_id}', path),
  ]);
  if (!overview.data || !device.data) throw new ApiError(500, null);
  return { overview: overview.data, device: device.data };
}

async function section<T>(allowed: boolean, load: () => Promise<{ data?: T }>): Promise<Section<T>> {
  if (!allowed) return { state: 'forbidden' };
  try {
    const { data } = await load();
    if (data === undefined) return { state: 'error', message: 'The server returned nothing' };
    return { state: 'ok', data };
  } catch (error) {
    if (error instanceof ApiError && error.status === 403) return { state: 'forbidden' };
    return { state: 'error', message: error instanceof Error ? error.message : 'Could not load this section' };
  }
}

export const INTERFACE_LIMIT = 200;
export const EVENT_LIMIT = 50;
export const POLL_LIMIT = 20;

export function loadInterfaces(client: ApiClient, id: string, can: (p: string) => boolean) {
  return section(can(SECTION_PERMISSIONS.interfaces), () =>
    client.GET('/api/v1/devices/{device_id}/interfaces', {
      params: { path: { device_id: id }, query: { limit: INTERFACE_LIMIT } },
    }),
  );
}

/** Open and resolved events for this device, newest first as the API orders them. */
export function loadEvents(client: ApiClient, id: string, can: (p: string) => boolean) {
  return section(can(SECTION_PERMISSIONS.events), () =>
    client.GET('/api/v1/events', { params: { query: { device_id: id, open_only: false, limit: EVENT_LIMIT } } }),
  );
}

export function loadPolls(client: ApiClient, id: string, can: (p: string) => boolean) {
  return section(can(SECTION_PERMISSIONS.polls), () =>
    client.GET('/api/v1/devices/{device_id}/poll-history', {
      params: { path: { device_id: id }, query: { limit: POLL_LIMIT } },
    }),
  );
}

/** How the header describes the last ping. */
export function pingSummary(ping: DeviceOverview['ping']): { label: string; tone: 'ok' | 'bad' | 'unknown' } {
  if (!ping || ping.status === 'unknown') return { label: 'Not checked yet', tone: 'unknown' };
  if (ping.status === 'down') return { label: 'Not answering ping', tone: 'bad' };
  return {
    label: ping.latency_ms === null ? 'Answering ping' : `Answering ping (${ping.latency_ms.toFixed(1)} ms)`,
    tone: 'ok',
  };
}
