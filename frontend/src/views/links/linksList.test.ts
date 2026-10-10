import { describe, expect, it, vi } from 'vitest';
import { createApiClient } from '@/api/client';
import { allPages, fromLegacyLink, legacySource, newApiSource, periodLabel, type LegacyHttp } from './linksList';

const A = '11111111-0000-0000-0000-00000000000a';
const B = '11111111-0000-0000-0000-00000000000b';
const C = '11111111-0000-0000-0000-00000000000c';

function end(device: string | null, name: string | null, visible = true, iface: string | null = null) {
  return {
    device_id: device,
    name,
    ip: device ? '10.0.0.1' : null,
    interface_id: iface ? `${iface}-id` : null,
    interface: iface,
    visible,
    state: 'up',
  };
}

function link(id: string, src: ReturnType<typeof end>, dest: ReturnType<typeof end>) {
  return { id, source: 'manual', description: null, src, dest, state: 'up', created_at: 't0', updated_at: 't0' };
}

function newApi(routes: Record<string, (url: URL, request: Request) => unknown>) {
  const seen: { method: string; path: string; query: string; body: unknown }[] = [];
  const fetchImpl = vi.fn(async (request: Request) => {
    const url = new URL(request.url);
    const text = request.method === 'GET' || request.method === 'DELETE' ? '' : await request.clone().text();
    seen.push({ method: request.method, path: url.pathname, query: url.search, body: text ? JSON.parse(text) : null });
    const handler = routes[`${request.method} ${url.pathname}`] as
      | ((url: URL, request: Request) => unknown)
      | undefined;
    const body = handler ? handler(url, request) : { detail: 'Not found' };
    return new Response(JSON.stringify(body), {
      status: handler ? 200 : 404,
      headers: { 'Content-Type': 'application/json' },
    });
  }) as unknown as typeof fetch;
  const store = { get: () => 'token', remove: () => {} };
  return {
    client: createApiClient({ baseUrl: 'http://nms.test', store, onUnauthorized: () => {}, fetch: fetchImpl }),
    seen,
  };
}

const LINKS = {
  items: [
    link('L1', end(A, 'acc-1', true, 'Gi0/1'), end(B, 'agg-1', true, 'Gi0/24')),
    link('L2', end(B, 'agg-1'), end(null, null, false)),
    link('L3', end(C, 'core'), end(B, 'agg-1')),
  ],
  truncated: false,
};
const FIGURES = {
  minutes: 60,
  items: [{ link_id: 'L1', percent: 42.5, mbps: 425, speed_mbps: 1000, side: 'src', direction: 'in' }],
};
const base = { 'GET /api/v1/links': () => LINKS, 'GET /api/v1/topology/links/utilization': () => FIGURES };

describe('new API links', () => {
  it('merges utilisation into the links and marks only fully visible links editable', async () => {
    const { client } = newApi(base);
    const { rows, total } = await newApiSource(client).list({
      devices: [],
      period: '1h',
      highUtilization: false,
      page: 1,
      limit: 50,
    });
    expect(total).toBe(3);
    expect(rows[0]).toEqual({
      id: 'L1',
      src: { deviceId: A, ip: '10.0.0.1', name: 'acc-1', interfaceId: 'Gi0/1-id', interface: 'Gi0/1', visible: true },
      dest: {
        deviceId: B,
        ip: '10.0.0.1',
        name: 'agg-1',
        interfaceId: 'Gi0/24-id',
        interface: 'Gi0/24',
        visible: true,
      },
      state: 'up',
      utilization: 42.5,
      utilizationMbps: 425,
      speed: 1000,
      createdAt: 't0',
      editable: true,
    });
    expect(rows[1].editable).toBe(false);
    expect(rows[1].dest).toEqual({
      deviceId: null,
      ip: null,
      name: null,
      interfaceId: null,
      interface: null,
      visible: false,
    });
    expect(rows[2].utilization).toBeNull();
  });

  it('filters by any chosen device at either end and pages locally', async () => {
    const { client } = newApi(base);
    const source = newApiSource(client);
    const picked = await source.list({ devices: [C, A], period: '1h', highUtilization: false, page: 1, limit: 50 });
    expect(picked.rows.map((r) => r.id)).toEqual(['L1', 'L3']);
    const atEitherEnd = await source.list({ devices: [B], period: '1h', highUtilization: false, page: 1, limit: 50 });
    expect(atEitherEnd.rows.map((r) => r.id)).toEqual(['L1', 'L2', 'L3']); // agg-1 is the destination of L1 and L3
    const second = await source.list({ devices: [], period: '1h', highUtilization: false, page: 2, limit: 2 });
    expect([second.rows.map((r) => r.id), second.total]).toEqual([['L3'], 3]);
  });

  it('keeps only links with an open high utilisation alarm, as legacy did', async () => {
    const { client, seen } = newApi({
      ...base,
      'GET /api/v1/events': () => ({
        items: [{ labels: { link_id: 'L3' } }, { labels: {} }, { labels: null }],
        total: 3,
      }),
    });
    const { rows } = await newApiSource(client).list({
      devices: [],
      period: '1h',
      highUtilization: true,
      page: 1,
      limit: 50,
    });
    expect(rows.map((r) => r.id)).toEqual(['L3']);
    const query = new URLSearchParams(seen.find((s) => s.path === '/api/v1/events')!.query);
    expect([query.get('name'), query.get('open_only')]).toEqual(['high_link_utilization', 'true']);
  });

  it('shows the server period without offering a choice', async () => {
    const { client, seen } = newApi(base);
    const source = newApiSource(client);
    expect(await source.periods()).toEqual({ options: ['1h'], current: '1h', selectable: false });
    expect(seen).toHaveLength(1);
    const listed = newApi(base);
    const afterList = newApiSource(listed.client);
    await afterList.list({ devices: [], period: '1h', highUtilization: false, page: 1, limit: 50 });
    expect((await afterList.periods()).current).toBe('1h');
    // The list already told it the period, so asking again costs nothing.
    expect(listed.seen.filter((s) => s.path === '/api/v1/topology/links/utilization')).toHaveLength(1);
  });

  it('creates with ids as strings and empty interfaces as null, and deletes by id', async () => {
    const { client, seen } = newApi({
      'POST /api/v1/links': () => LINKS.items[0],
      'DELETE /api/v1/links/L1': () => ({ status: 'deleted' }),
    });
    const source = newApiSource(client);
    await source.create({ srcDevice: A, destDevice: B, srcIface: 'i1' });
    await source.remove('L1');
    await source.create({ srcDevice: A, destDevice: B, destIface: 'i2' });
    expect(seen[2].body).toEqual({
      src_device_id: A,
      dest_device_id: B,
      src_interface_id: null,
      dest_interface_id: 'i2',
    });
    expect(seen[0].body).toEqual({
      src_device_id: A,
      dest_device_id: B,
      src_interface_id: 'i1',
      dest_interface_id: null,
    });
    expect([seen[1].method, seen[1].path]).toEqual(['DELETE', '/api/v1/links/L1']);
  });

  it('reads every page of devices and interfaces', async () => {
    const page = (offset: number, n: number) =>
      Array.from({ length: n }, (_, i) => ({ id: `d${offset + i}`, name: `n${offset + i}`, management_ip: null }));
    const { client, seen } = newApi({
      'GET /api/v1/devices': (url) => ({
        items: Number(url.searchParams.get('offset')) === 0 ? page(0, 200) : page(200, 3),
        total: 203,
      }),
      [`GET /api/v1/devices/${A}/interfaces`]: () => ({ items: [{ id: 'i1', name: 'Gi0/1' }], total: 1 }),
    });
    const source = newApiSource(client);
    const devices = await source.devices();
    expect([devices.length, devices[202]]).toEqual([203, { id: 'd202', name: 'n202', ip: '' }]);
    expect(await source.interfaces(A)).toEqual([{ id: 'i1', name: 'Gi0/1' }]);
    expect(
      seen.filter((s) => s.path === '/api/v1/devices').map((s) => new URLSearchParams(s.query).get('offset')),
    ).toEqual(['0', '200']);
  });

  it("reports the API's own words for a failure", async () => {
    const { client } = newApi({});
    const source = newApiSource(client);
    const error = await source.remove('L9').catch((e) => e);
    expect(source.errorMessage(error)).toBe('Not found');
    expect(source.errorMessage(new Error('x'))).toBe('Please try again.');
  });
});

describe('paging and periods', () => {
  it('stops at a short page and at the row cap', async () => {
    const calls: number[] = [];
    expect(await allPages(async (o) => (calls.push(o), o < 400 ? Array(200).fill(o) : [1]))).toHaveLength(401);
    expect(calls).toEqual([0, 200, 400]);
    const capped: number[] = [];
    expect(await allPages(async (o) => (capped.push(o), Array(200).fill(0)))).toHaveLength(5000);
    expect(capped.at(-1)).toBe(4800);
  });

  it('writes periods the way legacy did', () => {
    expect([periodLabel(10), periodLabel(15), periodLabel(60), periodLabel(360)]).toEqual(['10m', '15m', '1h', '6h']);
  });
});

function legacyHttp(reply: unknown = {}) {
  const calls: [string, string, unknown?][] = [];
  const http: LegacyHttp = {
    get: async (path, params) => (calls.push(['get', path, params]), { data: reply }),
    post: async (path, body) => calls.push(['post', path, body]),
    put: async (path, body) => (calls.push(['put', path, body]), { data: reply }),
    delete: async (path) => calls.push(['delete', path]),
  };
  return { http, calls };
}

describe('legacy links', () => {
  const row = {
    id: 7,
    src_device: { id: 1, ip: '10.0.0.1', name: 'a' },
    dest_device: { id: 2, ip: '10.0.0.2', name: 'b' },
    src_iface: { id: 11, name: 'Gi0/1' },
    dest_iface: null,
    utilization: 12.5,
    utilization_mbps: 125,
    speed: 1000,
    created_at: 't0',
  };

  it('maps a row with every end visible and editable, and no link state', () => {
    expect(fromLegacyLink(row)).toEqual({
      id: 7,
      src: { deviceId: 1, ip: '10.0.0.1', name: 'a', interfaceId: 11, interface: 'Gi0/1', visible: true },
      dest: { deviceId: 2, ip: '10.0.0.2', name: 'b', interfaceId: null, interface: null, visible: true },
      state: null,
      utilization: 12.5,
      utilizationMbps: 125,
      speed: 1000,
      createdAt: 't0',
      editable: true,
    });
  });

  it('sends exactly what the legacy page sent', async () => {
    const { http, calls } = legacyHttp({ data: [row], meta: { total: 9 } });
    const source = legacySource(http);
    expect((await source.list({ devices: [1], period: '30m', highUtilization: true, page: 2, limit: 20 })).total).toBe(
      9,
    );
    await source.create({ srcDevice: 1, destDevice: 2, destIface: 22 });
    await source.remove(7);
    await source.interfaces(1);
    expect(calls).toEqual([
      [
        'put',
        '/component/links/view/list',
        {
          query: {},
          limit: 20,
          page: 2,
          ascending: 0,
          byColumn: 1,
          filter: { devices: [{ id: 1 }], period: '30m', high_utilization: true },
        },
      ],
      [
        'post',
        '/component/links',
        { src_device: { id: 1 }, dest_device: { id: 2 }, src_iface: undefined, dest_iface: { id: 22 } },
      ],
      ['delete', '/component/links/7'],
      ['get', '/device-interface', { device_id: 1, limit: 999999 }],
    ]);
  });

  it('offers the configured periods, falling back to 15m', async () => {
    expect(
      await legacySource(legacyHttp({ data: { periods: ['15m', '1h'], calc_util_period: '1h' } }).http).periods(),
    ).toEqual({
      options: ['15m', '1h'],
      current: '1h',
      selectable: true,
    });
    const failing = { ...legacyHttp().http, get: async () => Promise.reject(new Error('down')) };
    expect(await legacySource(failing).periods()).toEqual({ options: ['15m'], current: '15m', selectable: true });
  });
});
