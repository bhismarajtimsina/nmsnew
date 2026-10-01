import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it, vi } from 'vitest';
import { createApiClient } from '@/api/client';
import {
  EVENT_LIMIT,
  POLL_LIMIT,
  loadDevice,
  loadEvents,
  loadInterfaces,
  loadPolls,
  pingSummary,
} from './deviceDetail';

const ID = '11111111-2222-3333-4444-555555555555';
const OVERVIEW = {
  id: ID,
  name: 'sw-1',
  management_ip: '10.0.0.1',
  polling_enabled: true,
  group: null,
  model: null,
  ping: null,
  interfaces: { up: 1, down: 0 },
};
const DEVICE = { id: ID, name: 'sw-1', device_type: 'switch', status: 'active' };

type Reply = { status: number; body: unknown };

function client(replies: Record<string, Reply>) {
  const seen: URL[] = [];
  const fetchImpl = vi.fn(async (request: Request) => {
    const url = new URL(request.url);
    seen.push(url);
    const reply = replies[url.pathname] ?? { status: 404, body: { detail: 'Not found' } };
    return new Response(JSON.stringify(reply.body), {
      status: reply.status,
      headers: { 'Content-Type': 'application/json' },
    });
  }) as unknown as typeof fetch;
  const store = { get: () => 'token', remove: () => {} };
  return {
    api: createApiClient({ baseUrl: 'http://nms.test', store, onUnauthorized: () => {}, fetch: fetchImpl }),
    seen,
  };
}

const everything = () => true;
const nothing = () => false;

describe('loadDevice', () => {
  it('reads the overview and the device record', async () => {
    const { api, seen } = client({
      [`/api/v1/devices/${ID}/overview`]: { status: 200, body: OVERVIEW },
      [`/api/v1/devices/${ID}`]: { status: 200, body: DEVICE },
    });
    expect(await loadDevice(api, ID)).toEqual({ overview: OVERVIEW, device: DEVICE });
    expect(seen.map((u) => u.pathname).sort()).toEqual([`/api/v1/devices/${ID}`, `/api/v1/devices/${ID}/overview`]);
  });

  it('a missing or out-of-scope device fails with the 404, for the page to explain', async () => {
    const { api } = client({});
    await expect(loadDevice(api, ID)).rejects.toMatchObject({ status: 404 });
  });
});

describe('sections', () => {
  it('are not requested at all without their permission', async () => {
    const { api, seen } = client({});
    expect(await loadInterfaces(api, ID, nothing)).toEqual({ state: 'forbidden' });
    expect(await loadEvents(api, ID, nothing)).toEqual({ state: 'forbidden' });
    expect(await loadPolls(api, ID, nothing)).toEqual({ state: 'forbidden' });
    expect(seen).toEqual([]);
  });

  it('each asks for its own permission', async () => {
    const { api, seen } = client({});
    const only = (p: string) => (q: string) => q === p;
    await loadInterfaces(api, ID, only('interfaces.view'));
    await loadEvents(api, ID, only('events.view'));
    await loadPolls(api, ID, only('pollers.view'));
    expect(seen).toHaveLength(3);
    await loadInterfaces(api, ID, only('events.view'));
    await loadEvents(api, ID, only('pollers.view'));
    await loadPolls(api, ID, only('interfaces.view'));
    expect(seen).toHaveLength(3);
  });

  it('request this device only, with resolved events included and bounded sizes', async () => {
    const { api, seen } = client({});
    await loadInterfaces(api, ID, everything);
    await loadEvents(api, ID, everything);
    await loadPolls(api, ID, everything);
    const [ifaces, events, polls] = seen;
    expect(ifaces.pathname).toBe(`/api/v1/devices/${ID}/interfaces`);
    expect(events.pathname).toBe('/api/v1/events');
    expect(Object.fromEntries(events.searchParams)).toEqual({
      device_id: ID,
      open_only: 'false',
      limit: String(EVENT_LIMIT),
    });
    expect(polls.pathname).toBe(`/api/v1/devices/${ID}/poll-history`);
    expect(polls.searchParams.get('limit')).toBe(String(POLL_LIMIT));
  });

  it('carry their data, or an error message, without throwing', async () => {
    const page = { items: [], total: 0, limit: 200, offset: 0 };
    const { api } = client({
      [`/api/v1/devices/${ID}/interfaces`]: { status: 200, body: page },
      '/api/v1/events': { status: 500, body: { detail: 'database unavailable' } },
      [`/api/v1/devices/${ID}/poll-history`]: { status: 403, body: { detail: 'Missing permission' } },
    });
    expect(await loadInterfaces(api, ID, everything)).toEqual({ state: 'ok', data: page });
    expect(await loadEvents(api, ID, everything)).toEqual({ state: 'error', message: 'database unavailable' });
    // The server refusing counts as no permission, not as a failure.
    expect(await loadPolls(api, ID, everything)).toEqual({ state: 'forbidden' });
  });
});

describe('pingSummary', () => {
  it('describes each ping state', () => {
    expect(pingSummary(null)).toEqual({ label: 'Not checked yet', tone: 'unknown' });
    expect(pingSummary({ status: 'unknown', latency_ms: null, last_checked_at: null }).tone).toBe('unknown');
    expect(pingSummary({ status: 'down', latency_ms: null, last_checked_at: 'x' })).toEqual({
      label: 'Not answering ping',
      tone: 'bad',
    });
    expect(pingSummary({ status: 'up', latency_ms: 1.234, last_checked_at: 'x' })).toEqual({
      label: 'Answering ping (1.2 ms)',
      tone: 'ok',
    });
    expect(pingSummary({ status: 'up', latency_ms: null, last_checked_at: 'x' })).toEqual({
      label: 'Answering ping',
      tone: 'ok',
    });
  });
});

describe('routing', () => {
  it('only the new login gets the new page; the legacy build keeps the legacy one', () => {
    const routes = readFileSync(resolve(__dirname, '../../router/wcaRoutes.ts'), 'utf8');
    expect(routes).toMatch(
      /authBackend\(\) === 'cybersathy' \? import\('@\/views\/devices\/DeviceDetailNewPage\.vue'\) : import\('@\/views\/devices\/DeviceDetailPage\.vue'\)/,
    );
  });

  it('the new page asks nothing of a device: no legacy or device-reaching call', () => {
    const page = readFileSync(resolve(__dirname, 'DeviceDetailNewPage.vue'), 'utf8');
    const data = readFileSync(resolve(__dirname, 'deviceDetail.ts'), 'utf8');
    for (const source of [page, data]) {
      expect(source).not.toMatch(/DataService|switcher-core|compare-model|wsClient|ttyd/);
    }
  });
});
