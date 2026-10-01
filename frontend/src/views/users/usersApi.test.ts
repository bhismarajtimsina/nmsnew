import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it, vi } from 'vitest';
import { ApiError, createApiClient } from '@/api/client';
import {
  emptyUserForm,
  userErrorMessage,
  userFormFor,
  userProblems,
  userUpdateBody,
  usersApi,
  type Role,
  type User,
} from './usersApi';

const ROLES: Role[] = [
  { id: 'r-noc', name: 'ISP NOC', description: null, is_system: true, scope_mode: 'all', permissions: [] },
  { id: 'r-admin', name: 'ISP Admin', description: null, is_system: true, scope_mode: 'all', permissions: [] },
];
const USER: User = {
  id: 'u1',
  username: 'noc1',
  display_name: 'NOC One',
  email: null,
  role: 'ISP NOC',
  is_active: true,
  totp_enabled: true,
  strict_ip_enabled: false,
  allowed_ips: [],
  last_login_at: null,
  reseller: null,
};

function serve(reply: (request: Request) => { status: number; body: unknown }) {
  const seen: { method: string; path: string; body: unknown }[] = [];
  const fetchImpl = vi.fn(async (request: Request) => {
    const text = request.method === 'GET' ? '' : await request.clone().text();
    seen.push({ method: request.method, path: new URL(request.url).pathname, body: text ? JSON.parse(text) : null });
    const { status, body } = reply(request);
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
  }) as unknown as typeof fetch;
  const store = { get: () => 'token', remove: () => {} };
  return {
    users: usersApi(createApiClient({ baseUrl: 'http://nms.test', store, onUnauthorized: () => {}, fetch: fetchImpl })),
    seen,
  };
}

describe('the form', () => {
  it('editing finds the role id from the role name the API returns', () => {
    expect(userFormFor(USER, ROLES)).toEqual({
      username: 'noc1',
      display_name: 'NOC One',
      email: '',
      role_id: 'r-noc',
      is_active: true,
    });
  });

  it('checks the login only when creating, with the API’s own pattern', () => {
    expect(userProblems(emptyUserForm(), false)).toEqual(['Login is required', 'Name is required', 'Choose a role']);
    expect(userProblems({ ...emptyUserForm(), username: 'has space', display_name: 'X', role_id: 'r' }, false)).toEqual(
      ['Login may only use letters, digits and . _ @ -'],
    );
    expect(userProblems({ ...emptyUserForm(), username: '', display_name: 'X', role_id: 'r' }, true)).toEqual([]);
  });
});

describe('editing sends only what changed', () => {
  it('nothing changed, nothing sent', () => {
    expect(userUpdateBody(userFormFor(USER, ROLES), USER, ROLES)).toEqual({});
  });

  it('role by id, disabling, and a cleared email as null', () => {
    const form = { ...userFormFor(USER, ROLES), role_id: 'r-admin', is_active: false };
    expect(userUpdateBody(form, USER, ROLES)).toEqual({ role_id: 'r-admin', is_active: false });
    const withEmail = { ...USER, email: 'a@b.c' };
    expect(userUpdateBody({ ...userFormFor(withEmail, ROLES), email: '  ' }, withEmail, ROLES)).toEqual({
      email: null,
    });
    expect(userUpdateBody({ ...userFormFor(USER, ROLES), display_name: ' New name ' }, USER, ROLES)).toEqual({
      display_name: 'New name',
    });
  });
});

describe('the API calls', () => {
  it('creating returns the generated password once and sends no password', async () => {
    const { users, seen } = serve(() => ({ status: 201, body: { ...USER, generated_password: 'gen-pass-1' } }));
    const password = await users.create({
      username: ' noc1 ',
      display_name: 'NOC One',
      email: '',
      role_id: 'r-noc',
      is_active: true,
    });
    expect(password).toBe('gen-pass-1');
    expect(seen[0]).toEqual({
      method: 'POST',
      path: '/api/v1/users',
      body: { username: 'noc1', display_name: 'NOC One', email: null, role_id: 'r-noc' },
    });
  });

  it('an unchanged edit sends nothing; a change sends a PATCH', async () => {
    const { users, seen } = serve(() => ({ status: 200, body: USER }));
    expect(await users.update(USER, userFormFor(USER, ROLES), ROLES)).toBe(false);
    expect(seen).toEqual([]);
    expect(await users.update(USER, { ...userFormFor(USER, ROLES), is_active: false }, ROLES)).toBe(true);
    expect(seen[0]).toEqual({ method: 'PATCH', path: '/api/v1/users/u1', body: { is_active: false } });
  });

  it('password and 2FA resets go to their own endpoints', async () => {
    const { users, seen } = serve((r) => ({
      status: 200,
      body: r.url.endsWith('/password')
        ? { status: 'password_reset', generated_password: 'gen-pass-2' }
        : { status: 'reset' },
    }));
    expect(await users.resetPassword('u1')).toBe('gen-pass-2');
    await users.resetTwoFactor('u1');
    expect(seen.map((s) => `${s.method} ${s.path}`)).toEqual([
      'POST /api/v1/users/u1/password',
      'POST /api/v1/users/u1/2fa/reset',
    ]);
    expect(seen[0].body).toEqual({});
  });

  it('roles come from the access API; without roles.view the list is empty, other errors still raise', async () => {
    const ok = serve(() => ({ status: 200, body: ROLES }));
    expect(await ok.users.roles()).toEqual(ROLES);
    expect(ok.seen[0].path).toBe('/api/v1/access/roles');
    expect(await serve(() => ({ status: 403, body: { detail: 'no' } })).users.roles()).toEqual([]);
    await expect(serve(() => ({ status: 500, body: { detail: 'boom' } })).users.roles()).rejects.toThrow('boom');
  });
});

describe('error messages', () => {
  it('shows the API’s reason, and spells out password-rule problems', () => {
    expect(userErrorMessage(new ApiError(409, 'The last active Super Admin cannot be demoted or disabled'))).toBe(
      'The last active Super Admin cannot be demoted or disabled',
    );
    expect(userErrorMessage(new ApiError(422, { password_problems: ['TOO_SHORT', 'NEEDS_MIXED_CHARACTERS'] }))).toBe(
      'The password does not meet the rules: TOO_SHORT, NEEDS_MIXED_CHARACTERS',
    );
    expect(userErrorMessage(new Error('x'))).toBe('Please try again.');
  });
});

describe('routing and the page', () => {
  const routes = readFileSync(resolve(__dirname, '../../router/wcaRoutes.ts'), 'utf8');
  const page = readFileSync(resolve(__dirname, 'UsersNewPage.vue'), 'utf8');

  it('the new login gets the new page for the list and for both legacy form routes', () => {
    expect(
      routes.match(/authBackend\(\) === 'cybersathy' \? import\('@\/views\/users\/UsersNewPage\.vue'\)/g),
    ).toHaveLength(3);
  });

  it('change controls need users.manage, and a generated password is dropped once acknowledged', () => {
    expect(page).toMatch(/const canManage = computed\(\(\) => auth\.can\('users\.manage'\)\);/);
    expect(page).toMatch(/<a-table-column v-if="canManage" title="" :width="130">/);
    expect(page).toMatch(/function closePassword\(\) \{\s+shownPassword\.value = null;/);
    expect(page).not.toMatch(/DataService|wsClient|\.delete\(/);
  });
});
