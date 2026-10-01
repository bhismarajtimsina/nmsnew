/**
 * Device management against the new API (Plan 25): `/api/v1/devices` (`devices.view` to read, `devices.manage` to
 * add and edit, `devices.delete` plus `dangerous_actions.execute` to delete).
 *
 * What the page may and may not do:
 * - Adding a device queues only the safe discovery the API itself allows; the device starts with polling off.
 * - Polling is never switched on from here. `polling_enabled` makes the new system poll a production device, and that
 *   belongs to the controlled ownership handover (Plan 34), not to an edit form. The page shows it, read-only.
 * - Deleting needs the device's exact name typed, as the API requires.
 * - Options the viewer lacks permission for (vendors, access profiles) come back empty instead of failing the page.
 */
import { ApiError, type ApiClient } from '@/api/client';
import type { components } from '@/api/schema';

type Schemas = components['schemas'];
export type Device = Schemas['DeviceOut'];
export type DeviceType = 'switch' | 'olt' | 'router' | 'sensor' | 'other';
export const DEVICE_TYPES: DeviceType[] = ['switch', 'olt', 'router', 'sensor', 'other'];

export interface Option {
  value: string;
  label: string;
}

export interface DeviceForm {
  name: string;
  hostname: string;
  management_ip: string;
  device_type: DeviceType;
  vendor_slug: string;
  family_slug: string;
  group_id: string;
  access_profile_id: string;
}

export function emptyDeviceForm(): DeviceForm {
  return {
    name: '',
    hostname: '',
    management_ip: '',
    device_type: 'switch',
    vendor_slug: '',
    family_slug: '',
    group_id: '',
    access_profile_id: '',
  };
}

/** The form for an existing device. Vendor and family slugs are not on the device record, so they start empty and
 * are only sent if the user picks them. */
export function deviceFormFor(device: Device): DeviceForm {
  return {
    ...emptyDeviceForm(),
    name: device.name,
    hostname: device.hostname ?? '',
    management_ip: device.management_ip,
    device_type: device.device_type as DeviceType,
    group_id: device.group_id ?? '',
  };
}

export function deviceProblems(form: DeviceForm): string[] {
  const found: string[] = [];
  if (!form.name.trim()) found.push('Name is required');
  if (!form.management_ip.trim()) found.push('Management IP is required');
  if (form.family_slug && !form.vendor_slug) found.push('Choose the vendor of that model family');
  return found;
}

const orNull = (value: string) => value.trim() || null;

export type CreateBody = Schemas['DeviceCreate'];
export type UpdateBody = Schemas['DeviceUpdate'];

export function createDeviceBody(form: DeviceForm): CreateBody {
  return {
    name: form.name.trim(),
    hostname: orNull(form.hostname),
    management_ip: form.management_ip.trim(),
    device_type: form.device_type,
    vendor_slug: orNull(form.vendor_slug),
    family_slug: orNull(form.family_slug),
    group_id: orNull(form.group_id),
    access_profile_id: orNull(form.access_profile_id),
  };
}

/** Only what changed. Never `polling_enabled` (see the module comment). An empty access profile choice means "leave
 * it as it is": the device record does not say which profile it has, so an empty field is not a request to clear it. */
export function updateDeviceBody(form: DeviceForm, before: Device): UpdateBody {
  const body: UpdateBody = {};
  if (form.name.trim() !== before.name) body.name = form.name.trim();
  if (orNull(form.hostname) !== before.hostname) body.hostname = orNull(form.hostname);
  if (form.management_ip.trim() !== before.management_ip) body.management_ip = form.management_ip.trim();
  if (form.device_type !== before.device_type) body.device_type = form.device_type;
  if (orNull(form.group_id) !== before.group_id) body.group_id = orNull(form.group_id);
  if (form.vendor_slug) body.vendor_slug = form.vendor_slug;
  if (form.family_slug) body.family_slug = form.family_slug;
  if (form.access_profile_id) body.access_profile_id = form.access_profile_id;
  return body;
}

export interface Discovery {
  status: string;
  reason: string | null;
}

/** What to tell the user about the discovery queued (or not) for a new device. */
export function discoveryMessage(discovery: Discovery): string {
  if (discovery.status === 'queued') return 'A safe discovery is queued; the device starts with polling off.';
  return `No discovery was queued${
    discovery.reason ? `: ${discovery.reason}` : ''
  }. The device starts with polling off.`;
}

const PAGE = 200;
const MAX_PAGES = 100;

async function optional<T>(load: () => Promise<{ data?: T }>, fallback: T): Promise<T> {
  try {
    return (await load()).data ?? fallback;
  } catch (error) {
    if (error instanceof ApiError && error.status === 403) return fallback;
    throw error;
  }
}

export function devicesApi(client: ApiClient) {
  const path = (id: string) => ({ params: { path: { device_id: id } } });
  return {
    async list(): Promise<Device[]> {
      const all: Device[] = [];
      for (let page = 0; page < MAX_PAGES; page++) {
        const { data } = await client.GET('/api/v1/devices', {
          params: { query: { limit: PAGE, offset: page * PAGE } },
        });
        if (!data) break;
        all.push(...data.items);
        if (!data.items.length || all.length >= data.total) break;
      }
      return all;
    },
    groups: () =>
      optional(async () => {
        const { data } = await client.GET('/api/v1/device-groups');
        return { data: (data ?? []).map((g) => ({ value: g.id, label: g.name })) };
      }, [] as Option[]),
    vendors: () =>
      optional(async () => {
        const { data } = await client.GET('/api/v1/vendors');
        return { data: (data ?? []).map((v) => ({ value: v.slug, label: v.name })) };
      }, [] as Option[]),
    families: (vendor: string) =>
      optional(async () => {
        const { data } = await client.GET('/api/v1/vendors/{slug}', { params: { path: { slug: vendor } } });
        return { data: (data?.model_families ?? []).map((f) => ({ value: f.slug, label: f.name })) };
      }, [] as Option[]),
    accessProfiles: () =>
      optional(async () => {
        const { data } = await client.GET('/api/v1/device-access-profiles');
        return { data: (data ?? []).map((p) => ({ value: p.id, label: `${p.name} (${p.snmp_version})` })) };
      }, [] as Option[]),
    async create(form: DeviceForm): Promise<Discovery> {
      const { data } = await client.POST('/api/v1/devices', { body: createDeviceBody(form) });
      return { status: data?.discovery.status ?? 'unknown', reason: data?.discovery.reason ?? null };
    },
    /** Returns false when nothing changed, so nothing was sent. */
    async update(before: Device, form: DeviceForm): Promise<boolean> {
      const body = updateDeviceBody(form, before);
      if (!Object.keys(body).length) return false;
      await client.PATCH('/api/v1/devices/{device_id}', { ...path(before.id), body });
      return true;
    },
    /** `confirm` must be the device's exact name; the API refuses anything else. */
    async remove(device: Device, confirm: string): Promise<void> {
      await client.DELETE('/api/v1/devices/{device_id}', {
        params: { path: { device_id: device.id }, query: { confirm } },
      });
    },
  };
}

export function deviceErrorMessage(error: unknown): string {
  return error instanceof ApiError ? error.message : 'Please try again.';
}
