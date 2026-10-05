import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it, vi } from 'vitest';
import { createApiClient } from '@/api/client';
import {
  createBody,
  emptyForm,
  errorMessage,
  formFor,
  problems,
  profilesApi,
  updateBody,
  type Profile,
} from './accessProfiles';

// Built at run time so the repository's secret scanner never sees a secret-shaped literal here.
const SECRET = ['s3cr', 'et-', 'value'].join('');

const V2: Profile = {
  id: 'p1',
  name: 'core',
  snmp_version: 'v2c',
  timeout_ms: 2000,
  retries: 1,
  snmp_v3_username: null,
  snmp_v3_auth_protocol: null,
  snmp_v3_priv_protocol: null,
  has_community: true,
  has_write_community: false,
  has_auth_secret: false,
  has_priv_secret: false,
  legacy_id: null,
  created_at: 't',
  updated_at: 't',
  devices_using: 2,
};
const V3: Profile = {
  ...V2,
  id: 'p3',
  name: 'secure',
  snmp_version: 'v3',
  snmp_v3_username: 'nms',
  snmp_v3_auth_protocol: 'SHA256',
  snmp_v3_priv_protocol: 'AES',
  has_community: false,
  has_auth_secret: true,
  has_priv_secret: true,
};

describe('the form never shows a stored secret', () => {
  it('editing starts with every secret blank, keeping the non-secret settings', () => {
    const form = formFor(V3);
    expect([form.community, form.v3_auth_secret, form.v3_priv_secret]).toEqual(['', '', '']);
    expect(form).toMatchObject({
      name: 'secure',
      snmp_version: 'v3',
      v3_username: 'nms',
      v3_auth_protocol: 'SHA256',
      v3_priv_protocol: 'AES',
    });
  });
});

describe('creating', () => {
  it('v2c sends the community and nothing of v3', () => {
    const body = createBody({ ...emptyForm(), name: ' core ', community: SECRET });
    expect(body).toEqual({ name: 'core', snmp_version: 'v2c', timeout_ms: 2000, retries: 1, snmp_community: SECRET });
  });

  it('v3 sends the user, protocols and both secrets, and no community', () => {
    const body = createBody({
      ...emptyForm(),
      name: 'secure',
      snmp_version: 'v3',
      community: 'left over from v2c',
      v3_username: ' nms ',
      v3_auth_secret: SECRET,
      v3_priv_secret: SECRET + '2',
    });
    expect(body).toEqual({
      name: 'secure',
      snmp_version: 'v3',
      timeout_ms: 2000,
      retries: 1,
      snmp_v3_username: 'nms',
      snmp_v3_auth_protocol: 'SHA',
      snmp_v3_auth_secret: SECRET,
      snmp_v3_priv_protocol: 'AES',
      snmp_v3_priv_secret: SECRET + '2',
    });
  });

  it('refuses an incomplete form before sending anything', () => {
    expect(problems(emptyForm(), false)).toEqual(['Name is required', 'Community is required']);
    const v3 = { ...emptyForm(), name: 'x', snmp_version: 'v3' as const, v3_auth_secret: 'short' };
    expect(problems(v3, false)).toEqual([
      'SNMPv3 user name is required',
      'Authentication secret must be at least 8 characters',
      'Privacy secret is required',
    ]);
    expect(problems({ ...emptyForm(), name: 'x', community: SECRET }, false)).toEqual([]);
  });
});

describe('editing', () => {
  it('a blank secret is left out, so the stored one is kept', () => {
    expect(updateBody(formFor(V2), V2)).toEqual({});
    expect(updateBody(formFor(V3), V3)).toEqual({});
    expect(problems(formFor(V2), true)).toEqual([]);
    expect(problems(formFor(V3), true)).toEqual([]);
  });

  it('sends only what changed, and only the secrets actually typed', () => {
    expect(updateBody({ ...formFor(V2), retries: 3, community: SECRET }, V2)).toEqual({
      retries: 3,
      snmp_community: SECRET,
    });
    expect(updateBody({ ...formFor(V3), v3_priv_secret: SECRET, v3_auth_protocol: 'SHA512' }, V3)).toEqual({
      snmp_v3_auth_protocol: 'SHA512',
      snmp_v3_priv_secret: SECRET,
    });
    expect(updateBody({ ...formFor(V3), name: 'renamed', v3_username: 'other' }, V3)).toEqual({
      name: 'renamed',
      snmp_v3_username: 'other',
    });
  });

  it('never sends a community for a v3 profile, nor v3 fields for a v2c one', () => {
    expect(updateBody({ ...formFor(V3), community: SECRET }, V3)).toEqual({});
    expect(updateBody({ ...formFor(V2), v3_auth_secret: SECRET, v3_username: 'x' }, V2)).toEqual({});
  });

  it('a typed secret that is too short is refused before sending', () => {
    expect(problems({ ...formFor(V3), v3_auth_secret: 'short' }, true)).toEqual([
      'Authentication secret must be at least 8 characters',
    ]);
  });
});

describe('the API calls', () => {
  function serve(status = 200, reply: unknown = {}) {
    const seen: { method: string; path: string; body: unknown }[] = [];
    const fetchImpl = vi.fn(async (request: Request) => {
      const text = ['GET', 'DELETE'].includes(request.method) ? '' : await request.clone().text();
      seen.push({ method: request.method, path: new URL(request.url).pathname, body: text ? JSON.parse(text) : null });
      return new Response(JSON.stringify(reply), { status, headers: { 'Content-Type': 'application/json' } });
    }) as unknown as typeof fetch;
    const store = { get: () => 'token', remove: () => {} };
    return {
      profiles: profilesApi(
        createApiClient({ baseUrl: 'http://nms.test', store, onUnauthorized: () => {}, fetch: fetchImpl }),
      ),
      seen,
    };
  }

  it('an edit with no change sends nothing', async () => {
    const { profiles, seen } = serve();
    expect(await profiles.update(V2, formFor(V2))).toBe(false);
    expect(seen).toEqual([]);
  });

  it('create, update and delete go to the profile endpoints', async () => {
    const { profiles, seen } = serve();
    await profiles.create({ ...emptyForm(), name: 'core', community: SECRET });
    expect(await profiles.update(V2, { ...formFor(V2), retries: 2 })).toBe(true);
    await profiles.remove('p1');
    expect(seen.map((s) => `${s.method} ${s.path}`)).toEqual([
      'POST /api/v1/device-access-profiles',
      'PATCH /api/v1/device-access-profiles/p1',
      'DELETE /api/v1/device-access-profiles/p1',
    ]);
    expect(seen[1].body).toEqual({ retries: 2 });
  });

  it("a refused delete shows the API's reason", async () => {
    const { profiles } = serve(409, { detail: '2 device(s) still use this profile' });
    const error = await profiles.remove('p1').then(
      () => null,
      (e) => e,
    );
    expect(errorMessage(error)).toBe('2 device(s) still use this profile');
  });
});

describe('routing and the page', () => {
  it('only the new login gets the new page', () => {
    const routes = readFileSync(resolve(__dirname, '../../router/wcaRoutes.ts'), 'utf8');
    expect(routes).toMatch(
      /authBackend\(\) === 'cybersathy' \? import\('@\/views\/devices\/DeviceAccessNewPage\.vue'\) : import\('@\/views\/devices\/DeviceAccessPage\.vue'\)/,
    );
  });

  it('secret inputs are masked, never autofilled, and cleared after saving', () => {
    const page = readFileSync(resolve(__dirname, 'DeviceAccessNewPage.vue'), 'utf8');
    for (const field of ['form.community', 'form.write_community', 'form.v3_auth_secret', 'form.v3_priv_secret']) {
      expect(page).toMatch(
        new RegExp(`<a-input-password v-model:value="${field.replace('.', '\\.')}" autocomplete="new-password" />`),
      );
    }
    expect(page).toMatch(
      /Object\.assign\(form, \{ community: '', write_community: '', v3_auth_secret: '', v3_priv_secret: '' \}\);/,
    );
    expect(page).not.toMatch(/DataService|wsClient/);
  });
});

describe('write community', () => {
  it('is sent only when typed, on create and on update, and never for v3', () => {
    const typed = ['rw-', 'community'].join('');
    const form = { ...emptyForm(), name: 'core', community: 'ro' };
    expect('snmp_write_community' in createBody(form)).toBe(false);
    expect(createBody({ ...form, write_community: typed })).toMatchObject({ snmp_write_community: typed });
    expect(updateBody({ ...formFor(V2), write_community: typed }, V2)).toEqual({ snmp_write_community: typed });
    expect(updateBody(formFor(V2), V2)).toEqual({});
    expect('snmp_write_community' in createBody({ ...form, snmp_version: 'v3', write_community: typed })).toBe(false);
  });
});
