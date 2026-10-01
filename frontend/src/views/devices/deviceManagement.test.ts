import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it, vi } from 'vitest';
import { createApiClient } from '@/api/client';
import {
  createDeviceBody,
  deviceErrorMessage,
  deviceFormFor,
  deviceProblems,
  devicesApi,
  discoveryMessage,
  emptyDeviceForm,
  updateDeviceBody,
  type Device,
} from './deviceManagement';

const DEVICE: Device = {
  id: 'd1',
  name: 'sw-1',
  hostname: null,
  management_ip: '10.10.0.11',
  device_type: 'switch',
  vendor: 'bdcom',
  status: 'active',
  group_id: 'g1',
  model_id: null,
  polling_enabled: false,
  polling_owner: 'legacy',
  legacy_id: null,
  created_at: 't',
  updated_at: 't',
};

function serve(reply: (url: URL, method: string) => { status: number; body: unknown }) {
  const seen: { method: string; url: URL; body: unknown }[] = [];
  const fetchImpl = vi.fn(async (request: Request) => {
    const url = new URL(request.url);
    const text = ['GET', 'DELETE'].includes(request.method) ? '' : await request.clone().text();
    seen.push({ method: request.method, url, body: text ? JSON.parse(text) : null });
    const { status, body } = reply(url, request.method);
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
  }) as unknown as typeof fetch;
  const store = { get: () => 'token', remove: () => {} };
  return {
    devices: devicesApi(
      createApiClient({ baseUrl: 'http://nms.test', store, onUnauthorized: () => {}, fetch: fetchImpl }),
    ),
    seen,
  };
}

describe('polling is never switched on from this page', () => {
  it('neither creating nor editing ever sends polling_enabled', () => {
    expect(createDeviceBody({ ...emptyDeviceForm(), name: 'x', management_ip: '10.0.0.1' })).not.toHaveProperty(
      'polling_enabled',
    );
    const everything = {
      ...deviceFormFor(DEVICE),
      name: 'y',
      vendor_slug: 'bdcom',
      family_slug: 'bdcom-switch',
      access_profile_id: 'p',
    };
    expect(updateDeviceBody(everything, DEVICE)).not.toHaveProperty('polling_enabled');
  });

  it('the page has no polling control, only a read-only tag', () => {
    const page = readFileSync(resolve(__dirname, 'DeviceManagementNewPage.vue'), 'utf8');
    expect(page).not.toMatch(/polling_enabled\s*[:=]|v-model[^>]*polling/);
    expect(page).toMatch(/record\.polling_enabled \? 'on' : 'off'/);
  });
});

describe('creating', () => {
  it('trims, and sends empty choices as null', () => {
    expect(
      createDeviceBody({ ...emptyDeviceForm(), name: ' sw-1 ', management_ip: ' 10.10.0.11 ', group_id: 'g1' }),
    ).toEqual({
      name: 'sw-1',
      hostname: null,
      management_ip: '10.10.0.11',
      device_type: 'switch',
      vendor_slug: null,
      family_slug: null,
      group_id: 'g1',
      access_profile_id: null,
    });
  });

  it('checks the form before sending', () => {
    expect(deviceProblems(emptyDeviceForm())).toEqual(['Name is required', 'Management IP is required']);
    expect(deviceProblems({ ...emptyDeviceForm(), name: 'x', management_ip: '1', family_slug: 'f' })).toEqual([
      'Choose the vendor of that model family',
    ]);
  });

  it('tells the user what discovery did', () => {
    expect(discoveryMessage({ status: 'queued', reason: null })).toMatch(/safe discovery is queued/);
    expect(discoveryMessage({ status: 'skipped', reason: 'no access profile' })).toBe(
      'No discovery was queued: no access profile. The device starts with polling off.',
    );
  });
});

describe('editing', () => {
  it('nothing changed, nothing sent', () => {
    expect(updateDeviceBody(deviceFormFor(DEVICE), DEVICE)).toEqual({});
  });

  it('sends changed fields; clearing the group sends null', () => {
    const form = { ...deviceFormFor(DEVICE), name: 'sw-2', group_id: '', hostname: 'sw2.local' };
    expect(updateDeviceBody(form, DEVICE)).toEqual({ name: 'sw-2', group_id: null, hostname: 'sw2.local' });
  });

  it('an empty vendor, family or access profile keeps the current one; a chosen one is sent', () => {
    expect(updateDeviceBody({ ...deviceFormFor(DEVICE) }, DEVICE)).toEqual({});
    expect(
      updateDeviceBody({ ...deviceFormFor(DEVICE), access_profile_id: 'p2', vendor_slug: 'bdcom' }, DEVICE),
    ).toEqual({
      access_profile_id: 'p2',
      vendor_slug: 'bdcom',
    });
  });
});

describe('the API calls', () => {
  it('lists every page of devices', async () => {
    const total = 450;
    const { devices, seen } = serve((url) => {
      const offset = Number(url.searchParams.get('offset'));
      const items = Array.from({ length: Math.min(200, total - offset) }, (_, i) => ({
        ...DEVICE,
        id: `d${offset + i}`,
      }));
      return { status: 200, body: { items, total, limit: 200, offset } };
    });
    expect(await devices.list()).toHaveLength(total);
    expect(seen.map((s) => s.url.searchParams.get('offset'))).toEqual(['0', '200', '400']);
  });

  it('options the viewer may not read come back empty; other failures still raise', async () => {
    const forbidden = serve(() => ({ status: 403, body: { detail: 'no' } }));
    expect(await forbidden.devices.vendors()).toEqual([]);
    expect(await forbidden.devices.accessProfiles()).toEqual([]);
    await expect(serve(() => ({ status: 500, body: { detail: 'boom' } })).devices.groups()).rejects.toThrow('boom');
  });

  it('maps options to value and label', async () => {
    const { devices } = serve((url) =>
      url.pathname.endsWith('/device-access-profiles')
        ? { status: 200, body: [{ id: 'p1', name: 'core', snmp_version: 'v2c' }] }
        : { status: 200, body: { model_families: [{ slug: 'bdcom-switch', name: 'BDCOM switch' }] } },
    );
    expect(await devices.accessProfiles()).toEqual([{ value: 'p1', label: 'core (v2c)' }]);
    expect(await devices.families('bdcom')).toEqual([{ value: 'bdcom-switch', label: 'BDCOM switch' }]);
  });

  it('create returns the discovery outcome; an unchanged edit sends nothing', async () => {
    const { devices, seen } = serve(() => ({
      status: 201,
      body: { device: DEVICE, discovery: { job_id: null, status: 'skipped', reason: 'no access profile' } },
    }));
    expect(await devices.create({ ...emptyDeviceForm(), name: 'sw-1', management_ip: '10.10.0.11' })).toEqual({
      status: 'skipped',
      reason: 'no access profile',
    });
    expect(await devices.update(DEVICE, deviceFormFor(DEVICE))).toBe(false);
    expect(seen).toHaveLength(1);
  });

  it('delete sends the typed name as the confirmation, and shows a refusal as the API words it', async () => {
    const { devices, seen } = serve(() => ({
      status: 422,
      body: { detail: 'Confirmation does not match the device name' },
    }));
    const error = await devices.remove(DEVICE, 'sw-1').then(
      () => null,
      (e) => e,
    );
    expect(seen[0].method).toBe('DELETE');
    expect(seen[0].url.pathname).toBe('/api/v1/devices/d1');
    expect(seen[0].url.searchParams.get('confirm')).toBe('sw-1');
    expect(deviceErrorMessage(error)).toBe('Confirmation does not match the device name');
  });
});

describe('routing and permissions on the page', () => {
  it('the new login gets the new page for the list and both legacy form routes', () => {
    const routes = readFileSync(resolve(__dirname, '../../router/wcaRoutes.ts'), 'utf8');
    expect(
      routes.match(/authBackend\(\) === 'cybersathy' \? import\('@\/views\/devices\/DeviceManagementNewPage\.vue'\)/g),
    ).toHaveLength(3);
  });

  it('delete needs both permissions and the exact typed name', () => {
    const page = readFileSync(resolve(__dirname, 'DeviceManagementNewPage.vue'), 'utf8');
    expect(page).toMatch(
      /const canDelete = computed\(\(\) => auth\.can\('devices\.delete'\) && auth\.can\('dangerous_actions\.execute'\)\);/,
    );
    expect(page).toMatch(/if \(!device \|\| typedName\.value !== device\.name\) return;/);
    expect(page).toMatch(/:disabled="!deleting \|\| typedName !== deleting\.name"/);
  });
});
