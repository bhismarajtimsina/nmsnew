import { describe as group, expect, it, vi } from 'vitest';
import { ApiError, AUTH_KEY, USER_KEY, createApiClient, describe, type KeyStore } from './client';

function memoryStore(initial: Record<string, string> = {}): KeyStore & { data: Record<string, string> } {
  const data = { ...initial };
  return { data, get: (k) => data[k] ?? null, remove: (k) => void delete data[k] };
}

function fakeFetch(status: number, body: unknown) {
  const calls: Request[] = [];
  const impl = vi.fn(async (input: Request) => {
    calls.push(input);
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
  });
  return { calls, impl: impl as unknown as typeof fetch };
}

function client(store: KeyStore, fetchImpl: typeof fetch, onUnauthorized = vi.fn()) {
  return { api: createApiClient({ baseUrl: 'http://nms.test', store, onUnauthorized, fetch: fetchImpl }), onUnauthorized };
}

group('the typed API client', () => {
  it('sends the stored token as a Bearer header on every request', async () => {
    const f = fakeFetch(200, { items: [], total: 0, limit: 50, offset: 0 });
    const { api } = client(memoryStore({ [AUTH_KEY]: 'css_abc' }), f.impl);
    await api.GET('/api/v1/events', { params: { query: { open_only: true } } });
    await api.GET('/api/v1/maintenance-windows');
    expect(f.calls.map((r) => r.headers.get('Authorization'))).toEqual(['Bearer css_abc', 'Bearer css_abc']);
    expect(f.calls[0].url).toBe('http://nms.test/api/v1/events?open_only=true');
  });

  it('reads the token at request time, so a new login takes effect without rebuilding the client', async () => {
    const f = fakeFetch(200, {});
    const store = memoryStore();
    const { api } = client(store, f.impl);
    await api.GET('/api/v1/auth/session');
    store.data[AUTH_KEY] = 'css_new';
    await api.GET('/api/v1/auth/session');
    expect(f.calls.map((r) => r.headers.get('Authorization'))).toEqual([null, 'Bearer css_new']);
  });

  it('never sends the legacy X-Auth-Key header', async () => {
    const f = fakeFetch(200, {});
    const { api } = client(memoryStore({ [AUTH_KEY]: 'css_abc', wca_auth_key: 'legacy' }), f.impl);
    await api.GET('/api/v1/auth/session');
    expect(f.calls[0].headers.get('X-Auth-Key')).toBeNull();
  });

  it('on a 401 clears the stored credential and hands over to the login redirect', async () => {
    const f = fakeFetch(401, { detail: 'Invalid or expired credentials' });
    const store = memoryStore({ [AUTH_KEY]: 'css_old', [USER_KEY]: '{"id":"u1"}', unrelated: 'kept' });
    const { api, onUnauthorized } = client(store, f.impl);
    await expect(api.GET('/api/v1/auth/session')).rejects.toMatchObject({ status: 401, message: 'Invalid or expired credentials' });
    expect(store.data).toEqual({ unrelated: 'kept' });
    expect(onUnauthorized).toHaveBeenCalledTimes(1);
  });

  it('a 403 keeps the session: it is a missing permission, not a lost login', async () => {
    const f = fakeFetch(403, { detail: 'Missing permission: maintenance.manage' });
    const store = memoryStore({ [AUTH_KEY]: 'css_abc' });
    const { api, onUnauthorized } = client(store, f.impl);
    const failure = api.POST('/api/v1/maintenance-windows', {
      body: { device_id: 'd1', ends_at: '2030-01-01T00:00:00Z', reason: 'work' },
    });
    await expect(failure).rejects.toBeInstanceOf(ApiError);
    await expect(failure).rejects.toMatchObject({ status: 403, message: 'Missing permission: maintenance.manage' });
    expect(store.data[AUTH_KEY]).toBe('css_abc');
    expect(onUnauthorized).not.toHaveBeenCalled();
  });
});

group('error messages', () => {
  it('uses the API detail string as is', () => {
    expect(describe(409, 'Already resolved')).toBe('Already resolved');
  });

  it('turns a validation error list into one readable line', () => {
    const detail = [
      { loc: ['body', 'reason'], msg: 'String should have at least 1 character' },
      { loc: ['body', 'ends_at'], msg: 'Field required' },
    ];
    expect(describe(422, detail)).toBe('reason: String should have at least 1 character; ends_at: Field required');
  });

  it('falls back to a plain message when the API gave none', () => {
    expect(describe(404, null)).toBe('Not found');
    expect(describe(502, undefined)).toBe('The server failed to handle the request');
    expect(describe(418, null)).toBe('Request failed (418)');
  });
});
