import { describe, expect, it, vi } from 'vitest';
import { createApiClient } from '@/api/client';
import {
  MAX_PAGES,
  fromLegacy,
  legacyPatch,
  loadGroupOptions,
  loadOverview,
  modelOptionsFrom,
  type LegacyRow,
} from './deviceList';

const LEGACY: LegacyRow = {
  id: 7,
  ip: '10.0.0.7',
  name: 'sw-7',
  enabled: false,
  model: { id: 3, name: 'S5612', vendor: 'BDCOM', model: 'S5612', icon: '/icons/sw.png' },
  group: { id: 2, name: 'Core' },
  pinger: { latency: 1.2 },
  ifaces_stat: { up: 10, down: 2 },
};

function overviewItem(n: number, extra: Record<string, unknown> = {}) {
  return {
    id: `uuid-${n}`,
    name: `dev-${n}`,
    management_ip: `10.1.0.${n}`,
    polling_enabled: true,
    group: null,
    model: null,
    ping: null,
    interfaces: { up: 0, down: 0 },
    ...extra,
  };
}

function client(handler: (url: URL) => { status: number; body: unknown }) {
  const seen: URL[] = [];
  const fetchImpl = vi.fn(async (request: Request) => {
    const url = new URL(request.url);
    seen.push(url);
    const { status, body } = handler(url);
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
  }) as unknown as typeof fetch;
  const store = { get: () => 'token', remove: () => {} };
  return {
    api: createApiClient({ baseUrl: 'http://nms.test', store, onUnauthorized: () => {}, fetch: fetchImpl }),
    seen,
  };
}

describe('legacy rows', () => {
  it('map onto the card shape; online means a latency above zero', () => {
    expect(fromLegacy(LEGACY)).toEqual({
      id: 7,
      name: 'sw-7',
      ip: '10.0.0.7',
      enabled: false,
      online: true,
      model: { id: 3, name: 'S5612', vendor: 'BDCOM', icon: '/icons/sw.png' },
      group: { id: 2, name: 'Core' },
      ifaces: { up: 10, down: 2 },
    });
    expect(fromLegacy({ ...LEGACY, pinger: { latency: 0 } }).online).toBe(false);
    expect(fromLegacy({ ...LEGACY, pinger: { latency: -1 } }).online).toBe(false);
    expect(fromLegacy({ ...LEGACY, pinger: null, ifaces_stat: null, model: null, group: null })).toMatchObject({
      online: false,
      ifaces: null,
      model: null,
      group: null,
    });
  });

  it('a pushed record patches only the fields it carries, keeping online state and interface counts', () => {
    const patch = legacyPatch({ id: 7, name: 'renamed', enabled: true, group: { id: 4, name: 'Edge' } });
    expect(patch).toEqual({ id: 7, name: 'renamed', enabled: true, group: { id: 4, name: 'Edge' } });
    expect(legacyPatch({ id: 7, model: null })).toEqual({ id: 7, model: null });
    expect(legacyPatch({ id: 7, pinger: { latency: 3 } })).toEqual({ id: 7, online: true });
  });
});

describe('new API', () => {
  it('maps an overview item; online means the last ping was up', async () => {
    const item = overviewItem(1, {
      polling_enabled: false,
      group: { id: 'g1', name: 'Core' },
      model: { id: 'm1', name: 'BDCOM S5612', vendor: 'bdcom', icon: null },
      ping: { status: 'up', latency_ms: 0.4, last_checked_at: '2026-10-01T00:00:00Z' },
      interfaces: { up: 3, down: 1 },
    });
    const { api } = client(() => ({ status: 200, body: { items: [item], total: 1, limit: 1000, offset: 0 } }));
    const [card] = await loadOverview(api, 'name', '');
    expect(card).toEqual({
      id: 'uuid-1',
      name: 'dev-1',
      ip: '10.1.0.1',
      enabled: false,
      online: true,
      model: { id: 'm1', name: 'BDCOM S5612', vendor: 'bdcom', icon: null },
      group: { id: 'g1', name: 'Core' },
      ifaces: { up: 3, down: 1 },
    });
  });

  it.each(['down', 'unknown'])('a last ping of %s is offline, as is never pinged', async (status) => {
    const items = [overviewItem(1, { ping: { status, latency_ms: null, last_checked_at: null } }), overviewItem(2)];
    const { api } = client(() => ({ status: 200, body: { items, total: 2, limit: 1000, offset: 0 } }));
    expect((await loadOverview(api, 'name', '')).map((c) => c.online)).toEqual([false, false]);
  });

  it('sends sort and search, and sorts by name where the new API has no location', async () => {
    const { api, seen } = client(() => ({ status: 200, body: { items: [], total: 0, limit: 1000, offset: 0 } }));
    await loadOverview(api, 'ip', 'olt');
    await loadOverview(api, 'location', '');
    expect(seen[0].pathname).toBe('/api/v1/devices/overview');
    expect(Object.fromEntries(seen[0].searchParams)).toEqual({ limit: '1000', offset: '0', sort: 'ip', search: 'olt' });
    expect(seen[1].searchParams.get('sort')).toBe('name');
    expect(seen[1].searchParams.has('search')).toBe(false);
  });

  it('pages through the whole list and stops at the total', async () => {
    const total = 2500;
    const { api, seen } = client((url) => {
      const offset = Number(url.searchParams.get('offset'));
      const count = Math.min(1000, total - offset);
      const items = Array.from({ length: count }, (_, i) => overviewItem(offset + i));
      return { status: 200, body: { items, total, limit: 1000, offset } };
    });
    const cards = await loadOverview(api, 'name', '');
    expect(cards).toHaveLength(total);
    expect(seen.map((u) => u.searchParams.get('offset'))).toEqual(['0', '1000', '2000']);
  });

  it('stops on an empty page and never loops past the page cap', async () => {
    const empty = client(() => ({ status: 200, body: { items: [], total: 5, limit: 1000, offset: 0 } }));
    expect(await loadOverview(empty.api, 'name', '')).toEqual([]);
    expect(empty.seen).toHaveLength(1);

    // A server that keeps claiming more than it ever sends.
    const lying = client(() => ({
      status: 200,
      body: { items: [overviewItem(1)], total: 1_000_000, limit: 1000, offset: 0 },
    }));
    expect(await loadOverview(lying.api, 'name', '')).toHaveLength(MAX_PAGES);
    expect(lying.seen).toHaveLength(MAX_PAGES);
  });

  it('a failed call raises, so the page shows its error', async () => {
    const { api } = client(() => ({ status: 403, body: { detail: 'Missing permission: devices.view' } }));
    await expect(loadOverview(api, 'name', '')).rejects.toThrow('Missing permission: devices.view');
  });

  it('group options come from the device-groups list', async () => {
    const groups = [
      { id: 'g1', parent_id: null, name: 'Core', description: null, legacy_id: null, created_at: 'x', devices: 1 },
    ];
    const { api } = client(() => ({ status: 200, body: groups }));
    expect(await loadGroupOptions(api)).toEqual([{ id: 'g1', label: 'Core', description: '' }]);
  });

  it('model options are the models in use, once each, sorted', () => {
    const model = (id: string, name: string) => ({ id, name, vendor: 'v', icon: null });
    const cards = [
      { ...fromLegacy(LEGACY), model: model('m2', 'Zeta') },
      { ...fromLegacy(LEGACY), model: model('m1', 'Alpha') },
      { ...fromLegacy(LEGACY), model: model('m2', 'Zeta') },
      { ...fromLegacy(LEGACY), model: null },
    ];
    expect(modelOptionsFrom(cards)).toEqual([
      { id: 'm1', label: 'Alpha', description: '' },
      { id: 'm2', label: 'Zeta', description: '' },
    ]);
  });
});
