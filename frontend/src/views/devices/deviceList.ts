/**
 * Where the device list page gets its data (Plan 25): the legacy `/dev-dashboard/*` calls, or with
 * `VITE_AUTH_BACKEND=cybersathy` the typed client against `GET /api/v1/devices/overview` and `/api/v1/device-groups`.
 * Both are turned into the same card shape, so the page's filtering and grouping do not care which API answered.
 */
import type { ApiClient } from '@/api/client';
import type { components } from '@/api/schema';

export type Id = number | string;

export interface DeviceCard {
  id: Id;
  name: string;
  ip: string;
  enabled: boolean;
  /** Legacy: the pinger's last latency is above zero. New API: the pinger's last result is `up`. */
  online: boolean;
  model: { id: Id; name: string; vendor: string; icon: string | null } | null;
  group: { id: Id; name: string } | null;
  ifaces: { up: number; down: number } | null;
}

export interface Option {
  id: Id;
  label: string;
  description: string;
}

export type SortKey = 'ip' | 'name' | 'location';

/** One legacy `/dev-dashboard/devices` row. */
export interface LegacyRow {
  id: number;
  ip: string;
  name: string;
  enabled: boolean;
  model: { id: number; name: string; vendor: string; model?: string; icon: string | null } | null;
  group: { id: number; name: string } | null;
  pinger: { latency: number } | null;
  ifaces_stat: { up: number; down: number } | null;
}

type OverviewItem = components['schemas']['DeviceOverviewOut'];

export function fromLegacy(row: LegacyRow): DeviceCard {
  return {
    id: row.id,
    name: row.name,
    ip: row.ip,
    enabled: row.enabled,
    online: !!row.pinger && row.pinger.latency > 0,
    model: row.model
      ? { id: row.model.id, name: row.model.name, vendor: row.model.vendor, icon: row.model.icon }
      : null,
    group: row.group ? { id: row.group.id, name: row.group.name } : null,
    ifaces: row.ifaces_stat ? { up: row.ifaces_stat.up, down: row.ifaces_stat.down } : null,
  };
}

/**
 * A device record pushed over the legacy WebSocket, as a patch for an existing card: only the fields the record
 * actually carries. The push never includes the poller-derived fields (pinger, ifaces_stat), so a merge keeps the
 * card's current online state and interface counts instead of blanking them.
 */
export function legacyPatch(record: Partial<LegacyRow> & { id: number }): Partial<DeviceCard> & { id: Id } {
  const card = fromLegacy({ pinger: null, ifaces_stat: null, model: null, group: null, ...record } as LegacyRow);
  const patch: Partial<DeviceCard> & { id: Id } = { id: record.id };
  if ('name' in record) patch.name = card.name;
  if ('ip' in record) patch.ip = card.ip;
  if ('enabled' in record) patch.enabled = card.enabled;
  if ('model' in record) patch.model = card.model;
  if ('group' in record) patch.group = card.group;
  if ('pinger' in record) patch.online = card.online;
  if ('ifaces_stat' in record) patch.ifaces = card.ifaces;
  return patch;
}

export function fromOverview(item: OverviewItem): DeviceCard {
  return {
    id: item.id,
    name: item.name,
    ip: item.management_ip,
    enabled: item.polling_enabled,
    online: item.ping?.status === 'up',
    model: item.model ? { ...item.model } : null,
    group: item.group ? { ...item.group } : null,
    ifaces: { ...item.interfaces },
  };
}

/** Upper bound on pages fetched for one list, so a server that keeps answering full pages cannot loop forever. */
export const MAX_PAGES = 50;
const PAGE_SIZE = 1000;

/** Every visible device, newest API. The legacy page loaded the whole list in one call; this pages through it. */
export async function loadOverview(client: ApiClient, sort: SortKey, query: string): Promise<DeviceCard[]> {
  const cards: DeviceCard[] = [];
  for (let page = 0; page < MAX_PAGES; page++) {
    const { data } = await client.GET('/api/v1/devices/overview', {
      params: {
        query: {
          limit: PAGE_SIZE,
          offset: page * PAGE_SIZE,
          // The new API has no location field; sort by name instead.
          sort: sort === 'ip' ? 'ip' : 'name',
          search: query || undefined,
        },
      },
    });
    if (!data) break;
    cards.push(...data.items.map(fromOverview));
    if (!data.items.length || cards.length >= data.total) break;
  }
  return cards;
}

export async function loadGroupOptions(client: ApiClient): Promise<Option[]> {
  const { data } = await client.GET('/api/v1/device-groups');
  return (data ?? []).map((g) => ({ id: g.id, label: g.name, description: g.description ?? '' }));
}

/** The models actually in use, from the loaded devices. Needs no permission beyond seeing the devices. */
export function modelOptionsFrom(cards: DeviceCard[]): Option[] {
  const seen = new Map<Id, Option>();
  for (const card of cards) {
    // The new catalogue's model names already carry the vendor ("BDCOM S5612").
    if (card.model) seen.set(card.model.id, { id: card.model.id, label: card.model.name, description: '' });
  }
  return [...seen.values()].sort((a, b) => a.label.localeCompare(b.label));
}
