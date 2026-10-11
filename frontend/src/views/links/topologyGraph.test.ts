import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it, vi } from 'vitest';
import { createApiClient } from '@/api/client';
import {
  buildTopology,
  isExternal,
  isPlaceholder,
  legacySource,
  levels,
  newApiSource,
  nodeSize,
  speedLabel,
  type ApiDevice,
  type ApiEnd,
  type ApiLink,
  type LegacyHttp,
} from './topologyGraph';

const ALL = { utilization: 0, hideLevelsAbove: 0, hideWithoutLinks: false };

function device(id: string, ping: 'up' | 'down' | 'unknown' | null = 'up'): ApiDevice {
  return {
    id,
    name: `dev-${id}`,
    management_ip: `10.0.0.${id.length}`,
    device_type: 'switch',
    group: { name: 'Core' },
    model: { name: 'S5612' },
    ping: ping ? { status: ping, latency_ms: 1.5, last_checked_at: null } : null,
    interfaces: { up: 3, down: 1 },
  };
}

function end(id: string | null, iface: string | null = null, state: ApiEnd['state'] = 'up'): ApiEnd {
  return id
    ? { device_id: id, name: `dev-${id}`, ip: '10.0.0.9', interface: iface, visible: true, state }
    : { device_id: null, name: null, ip: null, interface: null, visible: false, state: 'up' };
}

function link(id: string, src: ApiEnd, dest: ApiEnd, state: ApiLink['state'] = 'up'): ApiLink {
  return { id, source: 'manual', state, src, dest };
}

describe('building the topology', () => {
  it('turns overview devices into legacy nodes and links into legacy links', () => {
    const topo = buildTopology(
      [link('L1', end('a', 'Gi0/1'), end('b', 'Gi0/24', 'down'), 'down')],
      [{ link_id: 'L1', percent: 40, mbps: 400, speed_mbps: 1000 }],
      [device('a'), device('b', 'down')],
      ALL,
    );
    expect(topo.devices.find((d) => d.id === 'a')).toMatchObject({
      name: 'dev-a',
      ip: '10.0.0.1',
      model: { name: 'S5612', type: 'switch' },
      group: { name: 'Core' },
      pinger: { status: 'Up', latency: 1.5 },
      ifaces_stat: { up: 3, down: 1 },
      hidden: false,
      level: 0,
      design: { size: 10.75 }, // one link below: 10 + 1 / 2 * 1.5
    });
    expect(topo.devices.find((d) => d.id === 'b')).toMatchObject({
      pinger: { status: 'Down' },
      level: 1,
      design: { size: 8 },
    });
    expect(topo.links[0]).toMatchObject({
      id: 'L1',
      state: 'down',
      src_device: { id: 'a', name: 'dev-a' },
      dest_device: { id: 'b' },
      src_iface: { name: 'Gi0/1', status: 'Up' },
      dest_iface: { name: 'Gi0/24', status: 'Down' },
      utilization: 40,
      utilization_mbps: 400,
      speed: 1000,
      speed_humanize: '1G',
      editable: true,
    });
  });

  it('leaves an unknown ping and an unknown interface state without a status', () => {
    const topo = buildTopology(
      [link('L1', end('a', 'Gi0/1', 'unknown'), end('b'))],
      [],
      [device('a', 'unknown'), device('b', null)],
      ALL,
    );
    expect(topo.devices.map((d) => d.pinger.status)).toEqual([null, null]);
    expect([topo.links[0].src_iface.status, topo.links[0].dest_iface]).toEqual([undefined, null]);
    expect([topo.links[0].utilization, topo.links[0].speed_humanize]).toEqual([null, null]);
  });

  it('gives each hidden end its own unnamed placeholder and keeps the link from being edited', () => {
    const topo = buildTopology(
      [link('L1', end('a'), end(null)), link('L2', end('a'), end(null))],
      [],
      [device('a')],
      ALL,
    );
    const hidden = topo.devices.filter((d) => d.hidden);
    expect(hidden.map((d) => d.id)).toEqual(['hidden:L1:dest', 'hidden:L2:dest']);
    expect(hidden[0]).toMatchObject({ name: 'Outside your scope', ip: null, pinger: { status: null } });
    expect(topo.links[0]).toMatchObject({
      dest_device: { id: 'hidden:L1:dest', name: 'Outside your scope' },
      editable: false,
    });
  });

  it('masks a hidden source end too, and never trusts a device id on a hidden end', () => {
    const leaky = { ...end('secret'), visible: false, name: 'secret-name' };
    const topo = buildTopology([link('L1', leaky, end('a'))], [], [device('a')], ALL);
    expect(topo.links[0].src_device).toEqual({ id: 'hidden:L1:src', ip: '10.0.0.9', name: 'Outside your scope' });
    expect(topo.devices.map((d) => d.id)).not.toContain('secret');
  });

  it('draws a linked device the overview did not return under its own name', () => {
    const topo = buildTopology([link('L1', end('a'), end('z'))], [], [device('a')], ALL);
    expect(topo.devices.find((d) => d.id === 'z')).toMatchObject({ name: 'dev-z', ip: '10.0.0.9', hidden: false });
  });

  it('shows devices without links unless told to hide them', () => {
    const links = [link('L1', end('a'), end('b'))];
    expect(buildTopology(links, [], [device('a'), device('b'), device('lonely')], ALL).devices).toHaveLength(3);
    const hidden = buildTopology(links, [], [device('a'), device('b'), device('lonely')], {
      ...ALL,
      hideWithoutLinks: true,
    });
    expect(hidden.devices.map((d) => d.id)).toEqual(['a', 'b']);
  });

  it('keeps only links busier than the threshold, as legacy did', () => {
    const links = [link('L1', end('a'), end('b')), link('L2', end('a'), end('c')), link('L3', end('a'), end('d'))];
    const figures = [
      { link_id: 'L1', percent: 50, mbps: 1, speed_mbps: 1 },
      { link_id: 'L2', percent: 20, mbps: 1, speed_mbps: 1 },
    ];
    const topo = buildTopology(links, figures, [], { ...ALL, utilization: 20, hideWithoutLinks: true });
    expect(topo.links.map((l) => l.id)).toEqual(['L1']);
    expect(topo.devices.map((d) => d.id)).toEqual(['a', 'b']);
  });

  it('hides devices deeper than the chosen level, and their links', () => {
    const links = [link('L1', end('a'), end('b')), link('L2', end('b'), end('c'))];
    const topo = buildTopology(links, [], [device('a'), device('b'), device('c'), device('lonely')], {
      ...ALL,
      hideLevelsAbove: 1,
    });
    expect(topo.devices.map((d) => d.id).sort()).toEqual(['a', 'b', 'lonely']); // an unlinked device is level -1 and stays
    expect(topo.links.map((l) => l.id)).toEqual(['L1']);
  });
});

describe('levels, sizes and speeds', () => {
  it('measures depth from the roots and leaves a rootless ring at -1', () => {
    const depth = levels(
      ['a', 'b', 'c', 'x', 'y', 'solo'],
      [
        { from: 'a', to: 'b' },
        { from: 'b', to: 'c' },
        { from: 'a', to: 'c' },
        { from: 'x', to: 'y' },
        { from: 'y', to: 'x' },
      ],
    );
    expect(Object.fromEntries(depth)).toEqual({ a: 0, b: 1, c: 1, x: -1, y: -1, solo: -1 });
  });

  it("follows legacy's size steps", () => {
    expect([nodeSize(0), nodeSize(2), nodeSize(35), nodeSize(40), nodeSize(200), nodeSize(600), nodeSize(800)]).toEqual(
      [
        8,
        11.5,
        36,
        36,
        38,
        40,
        42, // 35 links: 36.25, capped
      ],
    );
  });

  it('writes speeds as legacy did', () => {
    expect([speedLabel(null), speedLabel(0), speedLabel(100), speedLabel(1000), speedLabel(10000)]).toEqual([
      null,
      null,
      '100M',
      '1G',
      '10G',
    ]);
  });

  it('tells placeholders apart from devices', () => {
    expect([
      isPlaceholder('hidden:L1:src'),
      isPlaceholder('ext:1:aa'),
      isPlaceholder('dev'),
      isPlaceholder(5),
      isPlaceholder(null),
    ]).toEqual([true, true, false, false, false]);
    expect([isExternal('ext:1:aa'), isExternal('hidden:L1:src')]).toEqual([true, false]);
  });
});

function newApi(routes: Record<string, (url: URL) => unknown>) {
  const seen: { method: string; path: string; query: string }[] = [];
  const fetchImpl = vi.fn(async (request: Request) => {
    const url = new URL(request.url);
    seen.push({ method: request.method, path: url.pathname, query: url.search });
    const handler = routes[`${request.method} ${url.pathname}`] as ((url: URL) => unknown) | undefined;
    return new Response(JSON.stringify(handler ? handler(url) : { detail: 'Not found' }), {
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

describe('new API source', () => {
  const routes = {
    'GET /api/v1/links': () => ({
      items: [{ ...link('L1', end('a', 'Gi0/1'), end('b')), description: null, created_at: 't', updated_at: 't' }],
      truncated: false,
    }),
    'GET /api/v1/topology/links/utilization': () => ({ minutes: 15, items: [] }),
    'GET /api/v1/devices/overview': () => ({ items: [device('a'), device('b')], total: 2, limit: 200, offset: 0 }),
    'DELETE /api/v1/links/L1': () => ({ status: 'deleted' }),
  };

  it('reads links, utilisation and the overview, and answers the drawer from the overview', async () => {
    const { client, seen } = newApi(routes);
    const source = newApiSource(client);
    const topo = await source.topology(ALL);
    expect([topo.devices.length, topo.links.length]).toEqual([2, 1]);
    expect(new URLSearchParams(seen.find((s) => s.path === '/api/v1/devices/overview')!.query).get('limit')).toBe(
      '200',
    );
    expect(await source.deviceExtra('a')).toEqual({ ifaces_stat: { up: 3, down: 1 } });
    expect(await source.deviceExtra('hidden:L1:dest')).toBeNull();
  });

  it('offers no optical, counter or resource data, and no external names', async () => {
    const source = newApiSource(newApi(routes).client);
    expect([await source.ifaceRow('a', 'Gi0/1'), await source.resources('a'), source.saveExternalName]).toEqual([
      null,
      null,
      undefined,
    ]);
  });

  it("deletes by id and reports the API's own words", async () => {
    const { client, seen } = newApi(routes);
    const source = newApiSource(client);
    await source.removeLink('L1');
    expect(seen.at(-1)).toMatchObject({ method: 'DELETE', path: '/api/v1/links/L1' });
    expect(source.errorMessage(await source.removeLink('L9').catch((e) => e))).toBe('Not found');
    expect(source.errorMessage(new Error('x'))).toBe('Please try again.');
  });
});

function legacyHttp(reply: (path: string) => unknown) {
  const calls: [string, string, unknown?][] = [];
  const http: LegacyHttp = {
    get: async (path, params) => (calls.push(['get', path, params]), { data: await reply(path) }),
    put: async (path, body) => calls.push(['put', path, body]),
    delete: async (path) => calls.push(['delete', path]),
  };
  return { http, calls };
}

describe('legacy source', () => {
  it('sends the settings as legacy did and caches per-device lookups', async () => {
    const { http, calls } = legacyHttp((path) =>
      path.includes('interfaces')
        ? { data: [{ interface: { name: 'Gi0/1' }, optical: { rx_power: -5 } }] }
        : { data: { devices: [1], links: [] } },
    );
    const source = legacySource(http);
    expect(await source.topology({ utilization: 30, hideLevelsAbove: 5, hideWithoutLinks: true })).toEqual({
      devices: [1],
      links: [],
    });
    expect(calls[0]).toEqual([
      'get',
      '/component/links/topology-tree',
      { period: '10m', utilization: 30, hide_levels_above: 5, hide_without_links: 'true' },
    ]);
    expect(await source.ifaceRow(3, 'Gi0/1')).toEqual({ interface: { name: 'Gi0/1' }, optical: { rx_power: -5 } });
    await source.ifaceRow(3, 'Gi0/1');
    await source.resources(3);
    await source.resources(3);
    await source.deviceExtra(3);
    await source.deviceExtra(3);
    expect(calls.map((c) => c[1])).toEqual([
      '/component/links/topology-tree',
      '/component/switches/interfaces/3',
      '/component/switches/resources/3',
      '/device/3',
    ]);
  });

  it('never looks up a placeholder', async () => {
    const { http, calls } = legacyHttp(() => ({ data: {} }));
    const source = legacySource(http);
    expect([
      await source.ifaceRow('ext:1:aa', 'x'),
      await source.resources('ext:1:aa'),
      await source.deviceExtra('ext:1:aa'),
      await source.ifaceRow(3, undefined),
    ]).toEqual([null, null, null, null]);
    expect(calls).toEqual([]);
  });

  it('deletes and names external neighbours through the legacy calls', async () => {
    const { http, calls } = legacyHttp(() => ({}));
    const source = legacySource(http);
    await source.removeLink(7);
    await source.saveExternalName!('ext:1:aa', 'ISP-X');
    expect(calls).toEqual([
      ['delete', '/component/links/7'],
      ['put', '/component/links/external-neighbor-name', { id: 'ext:1:aa', name: 'ISP-X' }],
    ]);
  });
});

describe('the graph page', () => {
  const page = readFileSync(resolve(__dirname, 'TopologyGraphPage.vue'), 'utf8');

  it('never opens a device page for a placeholder and offers delete only for an editable link', () => {
    expect(page).toContain('if (params.nodes?.length && !isPlaceholder(params.nodes[0])) {');
    expect(page).toContain('<a-button v-if="selectedLink.editable !== false" danger block');
    expect(page).toContain('<a-button v-if="!isPlaceholder(selectedDevice.id)" type="primary" block');
    expect(page).toMatch(/<span v-if="isPlaceholder\(selectedLink.src_device\?.id\)"/);
  });
});
