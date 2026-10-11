import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it, vi } from 'vitest';
import { createApiClient } from '@/api/client';
import {
  containsDevice,
  countBelow,
  fromApiNode,
  fromLegacyNode,
  legacySource,
  newApiSource,
  type LegacyHttp,
  type TreeView,
} from './topologyTree';

const A = '11111111-0000-0000-0000-00000000000a';
const B = '11111111-0000-0000-0000-00000000000b';

function newApi(routes: Record<string, (url: URL) => unknown>) {
  const seen: { path: string; query: string }[] = [];
  const fetchImpl = vi.fn(async (request: Request) => {
    const url = new URL(request.url);
    seen.push({ path: url.pathname, query: url.search });
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

const API_TREE = {
  id: A,
  name: 'core',
  ip: '10.0.0.1',
  visible: true,
  state: 'up',
  nodes: [
    {
      id: B,
      name: 'agg',
      ip: '10.0.0.2',
      visible: true,
      state: 'down',
      link_id: 'L1',
      link_state: 'down',
      uplink_interface: 'Gi0/24',
      downlink_interface: 'Gi0/1',
      repeat: false,
      nodes: [
        {
          id: A,
          name: 'core',
          ip: '10.0.0.1',
          visible: true,
          state: 'up',
          link_id: 'L2',
          link_state: null,
          repeat: true,
          nodes: [],
        },
      ],
    },
    {
      id: 'hidden:L3:dest',
      name: null,
      ip: null,
      visible: false,
      state: 'up',
      link_id: 'L3',
      link_state: 'up',
      uplink_interface: null,
      downlink_interface: 'Gi0/2',
      nodes: [],
    },
  ],
};

describe('new API tree', () => {
  it('maps parent and own interfaces, coloured by the link state', () => {
    const tree = fromApiNode(API_TREE as any);
    expect(tree).toMatchObject({
      deviceId: A,
      linkId: null,
      parentIface: null,
      ownIface: null,
      state: 'up',
      repeat: false,
    });
    expect(tree.nodes[0]).toMatchObject({
      deviceId: B,
      state: 'down',
      linkId: 'L1',
      parentIface: { name: 'Gi0/1', description: null, state: 'down' },
      ownIface: { name: 'Gi0/24', description: null, state: 'down' },
    });
  });

  it('marks a ring repeat and leaves a missing link state unknown', () => {
    const repeat = fromApiNode(API_TREE as any).nodes[0].nodes[0];
    expect([repeat.repeat, repeat.parentIface, repeat.state]).toEqual([true, null, 'up']);
    const unnamed = fromApiNode({ ...API_TREE, state: null, link_state: null, downlink_interface: 'x' } as any);
    expect([unnamed.state, unnamed.parentIface?.state]).toEqual(['unknown', 'unknown']);
  });

  it('never treats a placeholder id as a device id', () => {
    const hidden = fromApiNode(API_TREE as any).nodes[1];
    expect([hidden.deviceId, hidden.visible, hidden.name]).toEqual([null, false, null]);
  });

  it('asks for the direction and reports a truncated tree', async () => {
    const { client, seen } = newApi({
      [`GET /api/v1/topology/tree/${A}`]: () => ({
        direction: 'up',
        searched_device: A,
        build_from: A,
        is_top: true,
        truncated: true,
        tree: API_TREE,
      }),
    });
    const result = await newApiSource(client).tree(A, 'up');
    expect([result.truncated, result.rootNotFound, result.tree?.nodes.length]).toEqual([true, false, 2]);
    expect(new URLSearchParams(seen[0].query).get('direction')).toBe('up');
  });

  it('reads link info from the link and its visible end, and gives up quietly', async () => {
    const link = {
      id: 'L1',
      source: 'manual',
      description: null,
      state: 'up',
      created_at: 't0',
      updated_at: 't0',
      src: { device_id: null, name: null, ip: null, interface_id: null, interface: null, visible: false, state: null },
      dest: {
        device_id: B,
        name: 'agg',
        ip: '10.0.0.2',
        interface_id: null,
        interface: null,
        visible: true,
        state: 'up',
      },
    };
    const { client, seen } = newApi({
      'GET /api/v1/links/L1': () => link,
      'GET /api/v1/topology/links/utilization': () => ({
        minutes: 15,
        items: [{ link_id: 'L1', percent: 12.5, mbps: 125, speed_mbps: 1000, side: 'dest', direction: 'in' }],
      }),
    });
    const source = newApiSource(client);
    expect(await source.linkInfo('L1')).toEqual({ utilization: 12.5, speed: 1000, createdAt: 't0' });
    expect(new URLSearchParams(seen[1].query).get('device_id')).toBe(B);
    expect(await source.linkInfo('L9')).toBeNull();
  });

  it('keeps route ids as strings and labels devices by address and name', async () => {
    const { client } = newApi({
      'GET /api/v1/devices': () => ({
        items: [
          { id: A, name: 'core', management_ip: '10.0.0.1' },
          { id: B, name: 'agg', management_ip: null },
        ],
        total: 2,
      }),
    });
    const source = newApiSource(client);
    expect(source.parseId(A)).toBe(A);
    expect(await source.devices()).toEqual([
      { id: A, label: '10.0.0.1 (core)' },
      { id: B, label: 'agg' },
    ]);
  });
});

function legacyHttp(reply: (path: string, params?: Record<string, unknown>) => unknown) {
  const calls: [string, unknown][] = [];
  const http: LegacyHttp = {
    get: async (path, params) => {
      calls.push([path, params]);
      return { data: await reply(path, params) };
    },
  };
  return { http, calls };
}

const LEGACY_TREE = {
  device_id: 1,
  device_ip: '10.0.0.1',
  name: 'core',
  model_name: 'S5612',
  latency: 1.2,
  nodes: [
    {
      device_id: 2,
      device_ip: '10.0.0.2',
      name: '',
      latency: 0,
      link_id: 5,
      src_iface_name: 'Gi0/1',
      src_iface_description: 'up',
      src_iface_status: 'Up',
      dest_iface_name: 'Gi0/24',
      dest_iface_status: 'Down',
      nodes: [],
    },
  ],
};

describe('legacy tree', () => {
  it('maps latency to state and each interface status to its badge', () => {
    const tree = fromLegacyNode(LEGACY_TREE);
    expect(tree).toMatchObject({
      deviceId: 1,
      ip: '10.0.0.1',
      state: 'up',
      title: 'S5612',
      visible: true,
      repeat: false,
    });
    expect([tree.parentIface, tree.ownIface]).toEqual([null, null]); // the root has no link above it
    expect(fromLegacyNode({ ...LEGACY_TREE.nodes[0], dest_iface_status: 'Online' }).ownIface?.state).toBe('up'); // an ONU's word for up
    expect(tree.nodes[0]).toMatchObject({
      name: null,
      state: 'down',
      linkId: 5,
      parentIface: { name: 'Gi0/1', description: 'up', state: 'up' },
      ownIface: { name: 'Gi0/24', description: null, state: 'down' },
    });
  });

  it('sends what the legacy page sent and turns a missing core into a message, only going up', async () => {
    const { http, calls } = legacyHttp(() => ({ data: { tree: LEGACY_TREE } }));
    expect((await legacySource(http).tree(7, 'down')).tree?.nodes).toHaveLength(1);
    expect(calls[0]).toEqual(['/component/links/view/tree', { device_id: 7, direction: 'down' }]);
    const failing = (status: number) => legacyHttp(() => Promise.reject({ response: { status } })).http;
    expect(await legacySource(failing(500)).tree(7, 'up')).toEqual({
      tree: null,
      rootNotFound: true,
      truncated: false,
    });
    expect(await legacySource(failing(400)).tree(7, 'up')).toEqual({
      tree: null,
      rootNotFound: true,
      truncated: false,
    });
    await expect(legacySource(failing(500)).tree(7, 'down')).rejects.toBeTruthy();
    await expect(legacySource(failing(403)).tree(7, 'up')).rejects.toBeTruthy();
  });

  it('reads link info with stats, and numbers from the route', async () => {
    const { http, calls } = legacyHttp(() => ({ data: { utilization: 3, speed: 1000, created_at: 't0' } }));
    const source = legacySource(http);
    expect(await source.linkInfo(5)).toEqual({ utilization: 3, speed: 1000, createdAt: 't0' });
    expect(calls[0]).toEqual(['/component/links/5', { with_stat: 'yes' }]);
    expect(source.parseId('7')).toBe(7);
  });
});

describe('counting and highlighting', () => {
  const leaf = (id: string, state: TreeView['state']): TreeView => ({
    deviceId: id,
    ip: null,
    name: null,
    visible: true,
    state,
    linkId: 'l',
    parentIface: null,
    ownIface: null,
    repeat: false,
    title: null,
    nodes: [],
  });
  const tree: TreeView = {
    ...leaf('root', 'up'),
    nodes: [{ ...leaf('a', 'down'), nodes: [leaf('b', 'up'), leaf('c', 'unknown')] }],
  };

  it('counts every device below and the online ones', () => {
    expect([countBelow(tree), countBelow(tree, true)]).toEqual([3, 1]);
  });

  it('finds a device anywhere below, comparing ids as text, but not the node itself', () => {
    expect([containsDevice(tree, 'c'), containsDevice(tree, 'root'), containsDevice(tree, null)]).toEqual([
      true,
      false,
      false,
    ]);
    expect(containsDevice({ ...leaf('r', 'up'), nodes: [leaf('7', 'up')] }, 7)).toBe(true);
    // A hidden device's id is null too; nothing to highlight must not match it.
    expect(
      containsDevice({ ...leaf('r', 'up'), nodes: [{ ...leaf('x', 'up'), deviceId: null, visible: false }] }, null),
    ).toBe(false);
  });
});

describe('the row component', () => {
  const source = readFileSync(resolve(__dirname, '../../components/topology/TopologyTreeNode.vue'), 'utf8');

  it('links only to visible devices and never shows a hidden one by name', () => {
    expect(source).toMatch(/<router-link\s+v-if="node.visible"/);
    expect(source).toContain('<span v-if="node.visible" class="topo-node__name">');
  });
});
