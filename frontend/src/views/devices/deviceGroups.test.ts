import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it, vi } from 'vitest';
import { createApiClient } from '@/api/client';
import { fromLegacyGroup, legacySource, newApiSource, type LegacyHttp } from './deviceGroups';

const GID = '11111111-2222-3333-4444-555555555555';

function newApi(reply: (request: Request) => { status: number; body: unknown }) {
  const seen: { method: string; path: string; body: unknown }[] = [];
  const fetchImpl = vi.fn(async (request: Request) => {
    const text = request.method === 'GET' || request.method === 'DELETE' ? '' : await request.clone().text();
    seen.push({ method: request.method, path: new URL(request.url).pathname, body: text ? JSON.parse(text) : null });
    const { status, body } = reply(request);
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
  }) as unknown as typeof fetch;
  const store = { get: () => 'token', remove: () => {} };
  return {
    client: createApiClient({ baseUrl: 'http://nms.test', store, onUnauthorized: () => {}, fetch: fetchImpl }),
    seen,
  };
}

function legacyHttp(rows: unknown[] = []) {
  const calls: [string, string, unknown?][] = [];
  const http: LegacyHttp = {
    get: async (path) => (calls.push(['get', path]), { data: { data: rows } }),
    post: async (path, body) => calls.push(['post', path, body]),
    put: async (path, body) => calls.push(['put', path, body]),
    delete: async (path) => calls.push(['delete', path]),
  };
  return { http, calls };
}

describe('legacy groups', () => {
  it('lists rows, marking negative ids as built in', async () => {
    const { http } = legacyHttp([
      { id: -1, name: 'Default', description: null, created_at: 't0' },
      { id: 4, name: 'Core', description: 'ring', created_at: 't1' },
    ]);
    expect(await legacySource(http).list()).toEqual([
      { id: -1, name: 'Default', description: null, created_at: 't0', builtIn: true, devices: null },
      { id: 4, name: 'Core', description: 'ring', created_at: 't1', builtIn: false, devices: null },
    ]);
  });

  it('sends exactly what the legacy page sent', async () => {
    const { http, calls } = legacyHttp();
    const source = legacySource(http);
    await source.create({ name: ' Core ', description: '' });
    await source.update(4, { name: 'Core', description: 'ring' });
    await source.remove(4);
    expect(calls).toEqual([
      ['post', '/device-group', { name: ' Core ', description: undefined }],
      ['put', '/device-group/4', { name: 'Core', description: 'ring' }],
      ['delete', '/device-group/4'],
    ]);
  });

  it('a pushed record maps the same way as a listed one', () => {
    expect(fromLegacyGroup({ id: 9, name: 'Edge', description: null, created_at: 't' }).builtIn).toBe(false);
    expect(fromLegacyGroup({ id: -2, name: 'All', description: null, created_at: 't' }).builtIn).toBe(true);
  });

  it('shows the legacy error description', () => {
    const source = legacySource(legacyHttp().http);
    expect(source.errorMessage({ response: { data: { error: { description: 'Name taken' } } } })).toBe('Name taken');
    expect(source.errorMessage(new Error('x'))).toBe('Please try again.');
  });
});

describe('new API groups', () => {
  it('lists rows with their device counts; none is built in', async () => {
    const group = {
      id: GID,
      parent_id: null,
      name: 'Core',
      description: null,
      legacy_id: null,
      created_at: 't',
      devices: 3,
    };
    const { client } = newApi(() => ({ status: 200, body: [group] }));
    expect(await newApiSource(client).list()).toEqual([
      { id: GID, name: 'Core', description: null, created_at: 't', builtIn: false, devices: 3 },
    ]);
  });

  it('creates, updates and deletes with trimmed names and a null for no description', async () => {
    const { client, seen } = newApi(() => ({ status: 200, body: { status: 'deleted' } }));
    const source = newApiSource(client);
    await source.create({ name: ' Core ', description: '  ' });
    await source.update(GID, { name: 'Core ring', description: 'east' });
    await source.remove(GID);
    expect(seen).toEqual([
      { method: 'POST', path: '/api/v1/device-groups', body: { name: 'Core', description: null } },
      { method: 'PATCH', path: `/api/v1/device-groups/${GID}`, body: { name: 'Core ring', description: 'east' } },
      { method: 'DELETE', path: `/api/v1/device-groups/${GID}`, body: null },
    ]);
  });

  it("a refused delete shows the API's own reason", async () => {
    const { client } = newApi(() => ({ status: 409, body: { detail: 'Group still has devices' } }));
    const source = newApiSource(client);
    const error = await source.remove(GID).then(
      () => null,
      (e) => e,
    );
    expect(source.errorMessage(error)).toBe('Group still has devices');
    expect(source.errorMessage('not an ApiError')).toBe('Please try again.');
  });
});

describe('the groups page', () => {
  const page = readFileSync(resolve(__dirname, 'DeviceGroupsPage.vue'), 'utf8');

  it('uses the new API only under the new login', () => {
    expect(page).toMatch(/const source = newApi \? newApiSource\(api\) : legacySource\(/);
    expect(page).toMatch(/const newApi = authBackend\(\) === 'cybersathy';/);
  });

  it('keeps the legacy live merge, and reloads on the data-free notice under the new login', () => {
    expect(page).toMatch(
      /newApi \? noop : wsClient\.subscribe\('event:storage:device_groups:added', \(msg\) => mergeById\(rows, fromLegacyGroup/,
    );
    expect(page).toMatch(/const unsubChanges = newApi \? watchDeviceChanges\(/);
    expect(page).toMatch(/onBeforeUnmount\(\(\) => \{\s+unsubChanges\(\);/);
  });
});
