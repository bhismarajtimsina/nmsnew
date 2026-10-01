import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { createApiClient, AUTH_KEY, USER_KEY } from '@/api/client';
import { useAuthClient, useAuthStore } from './auth';

const USER = {
  id: 'u1', username: 'alice', display_name: 'Alice', email: null, role: 'ISP Admin', scope_mode: 'all',
  permissions: ['devices.view', 'events.view'], must_change_password: false, totp_enabled: false, auth_kind: 'session',
};

type Reply = { status: number; body: unknown };

function serve(replies: Record<string, Reply>) {
  const seen: Request[] = [];
  const fetchImpl = vi.fn(async (request: Request) => {
    seen.push(request);
    const path = new URL(request.url).pathname;
    const reply = replies[`${request.method} ${path}`] ?? { status: 404, body: { detail: 'Not found' } };
    return new Response(JSON.stringify(reply.body), { status: reply.status, headers: { 'Content-Type': 'application/json' } });
  }) as unknown as typeof fetch;
  const store = { get: (k: string) => localStorage.getItem(k), remove: (k: string) => localStorage.removeItem(k) };
  useAuthClient(createApiClient({ baseUrl: 'http://nms.test', store, onUnauthorized: () => {}, fetch: fetchImpl }));
  return seen;
}

beforeEach(() => {
  localStorage.clear();
  setActivePinia(createPinia());
});

describe('signing in', () => {
  it('stores the session under cs_* keys only and reports the user', async () => {
    const seen = serve({ 'POST /api/v1/auth/login': { status: 200, body: { token: 'css_t', token_type: 'Bearer', need_2fa: false, must_change_password: false, user: USER } } });
    localStorage.setItem('wca_auth_key', 'legacy-key');
    const auth = useAuthStore();
    const result = await auth.login({ login: 'alice', password: 'pw' });
    expect(result).toMatchObject({ ok: true });
    expect(localStorage.getItem(AUTH_KEY)).toBe('css_t');
    expect(JSON.parse(localStorage.getItem(USER_KEY)!).username).toBe('alice');
    expect(localStorage.getItem('wca_auth_key')).toBe('legacy-key'); // never read or written by this store
    expect(auth.loggedIn).toBe(true);
    expect(await seen[0].json()).toMatchObject({ login: 'alice', password: 'pw' });
  });

  it('asks for the second factor without storing anything, then completes with the code', async () => {
    serve({ 'POST /api/v1/auth/login': { status: 200, body: { need_2fa: true, token: null, user: null, token_type: 'Bearer', must_change_password: false } } });
    const auth = useAuthStore();
    expect(await auth.login({ login: 'alice', password: 'pw' })).toEqual({ ok: false, needs2fa: true });
    expect(localStorage.getItem(AUTH_KEY)).toBeNull();

    const seen = serve({ 'POST /api/v1/auth/login': { status: 200, body: { token: 'css_2', token_type: 'Bearer', need_2fa: false, must_change_password: false, user: USER } } });
    expect(await auth.login({ login: 'alice', password: 'pw', twofaPin: '123456' })).toMatchObject({ ok: true });
    expect((await seen[0].json()).twofa_pin).toBe('123456');
  });

  it('turns a rejected sign-in into a plain message', async () => {
    serve({ 'POST /api/v1/auth/login': { status: 401, body: { detail: 'Invalid credentials' } } });
    const auth = useAuthStore();
    expect(await auth.login({ login: 'alice', password: 'wrong' })).toEqual({ ok: false, needs2fa: false, error: 'Incorrect username or password.' });
    expect(auth.loggedIn).toBe(false);
  });
});

describe('the session across a refresh', () => {
  it('survives a refresh when the API still accepts the token', async () => {
    localStorage.setItem(AUTH_KEY, 'css_t');
    serve({ 'GET /api/v1/auth/session': { status: 200, body: USER } });
    const auth = useAuthStore();
    expect(await auth.checkSession()).toBe(true);
    expect(auth.loggedIn).toBe(true);
    expect(auth.displayName).toBe('Alice');
  });

  it('is cleared when the API no longer accepts the token', async () => {
    localStorage.setItem(AUTH_KEY, 'css_old');
    localStorage.setItem(USER_KEY, JSON.stringify(USER));
    serve({ 'GET /api/v1/auth/session': { status: 401, body: { detail: 'Invalid or expired credentials' } } });
    const auth = useAuthStore();
    expect(await auth.checkSession()).toBe(false);
    expect(auth.loggedIn).toBe(false);
    expect(localStorage.getItem(AUTH_KEY)).toBeNull();
    expect(localStorage.getItem(USER_KEY)).toBeNull();
  });

  it('with no stored token, does not call the API at all', async () => {
    const seen = serve({});
    expect(await useAuthStore().checkSession()).toBe(false);
    expect(seen).toHaveLength(0);
  });
});

describe('permissions and sign-out', () => {
  it('exposes the user permissions for menus', async () => {
    serve({ 'POST /api/v1/auth/login': { status: 200, body: { token: 'css_t', token_type: 'Bearer', need_2fa: false, must_change_password: false, user: USER } } });
    const auth = useAuthStore();
    await auth.login({ login: 'alice', password: 'pw' });
    expect(auth.can('devices.view')).toBe(true);
    expect(auth.can('users.manage')).toBe(false);
  });

  it('signs out locally even when the server call fails', async () => {
    localStorage.setItem(AUTH_KEY, 'css_t');
    localStorage.setItem(USER_KEY, JSON.stringify(USER));
    serve({ 'POST /api/v1/auth/logout': { status: 500, body: { detail: 'boom' } } });
    const auth = useAuthStore();
    await auth.logout();
    expect(auth.loggedIn).toBe(false);
    expect(localStorage.getItem(AUTH_KEY)).toBeNull();
  });
});
