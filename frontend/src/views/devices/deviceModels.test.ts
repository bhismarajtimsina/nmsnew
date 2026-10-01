import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it, vi } from 'vitest';
import { createApiClient } from '@/api/client';
import { detectionSummary, fromLegacyModel, loadLegacyModels, loadNewModels } from './deviceModels';

const LEGACY = {
  id: 3,
  key: 'bdcom_s5612',
  name: 'S5612',
  type: 'SWITCH',
  vendor: 'BDCOM',
  icon: null,
  pollers: { system: 300 },
};

function client(body: unknown) {
  const seen: string[] = [];
  const fetchImpl = vi.fn(async (request: Request) => {
    seen.push(new URL(request.url).pathname);
    return new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } });
  }) as unknown as typeof fetch;
  const store = { get: () => 'token', remove: () => {} };
  return {
    api: createApiClient({ baseUrl: 'http://nms.test', store, onUnauthorized: () => {}, fetch: fetchImpl }),
    seen,
  };
}

describe('legacy models', () => {
  it('keep their pollers and stay editable', async () => {
    const get = vi.fn(async () => ({ data: { data: [LEGACY] } }));
    expect(await loadLegacyModels(get)).toEqual([
      {
        id: 3,
        key: 'bdcom_s5612',
        name: 'S5612',
        type: 'SWITCH',
        vendor: 'BDCOM',
        pollers: { system: 300 },
        detection: null,
        editable: true,
      },
    ]);
    expect(get).toHaveBeenCalledWith('/device-model');
    expect(fromLegacyModel(LEGACY).editable).toBe(true);
  });

  it('an empty answer is an empty list', async () => {
    expect(await loadLegacyModels(async () => ({ data: {} }))).toEqual([]);
  });
});

describe('new catalogue', () => {
  const model = {
    id: 'm1',
    vendor: 'bdcom',
    family_slug: 'bdcom-switch',
    legacy_key: 'bdcom_s5612',
    model_name: 'BDCOM S5612',
    device_type: 'switch',
    sysobjectid_matcher: '1.3.6.1.4.1.3320.1.458',
    sysdescr_pattern: 'S5612',
    priority: 100,
    source_note: null,
    legacy_id: null,
    created_at: 't',
  };

  it('is read-only and shows how each model is detected', async () => {
    const { api, seen } = client([model, { ...model, id: 'm2', legacy_key: null, sysdescr_pattern: null }]);
    const rows = await loadNewModels(api);
    expect(seen).toEqual(['/api/v1/device-models']);
    expect(rows[0]).toEqual({
      id: 'm1',
      key: 'bdcom_s5612',
      name: 'BDCOM S5612',
      type: 'switch',
      vendor: 'bdcom',
      pollers: null,
      detection: 'sysObjectID 1.3.6.1.4.1.3320.1.458 · sysDescr /S5612/',
      editable: false,
    });
    expect(rows[1].key).toBe('');
    expect(rows[1].detection).toBe('sysObjectID 1.3.6.1.4.1.3320.1.458');
  });

  it('describes detection by either rule, both, or neither', () => {
    expect(detectionSummary(null, 'OLT')).toBe('sysDescr /OLT/');
    expect(detectionSummary(null, null)).toBeNull();
  });
});

describe('the models page', () => {
  const page = readFileSync(resolve(__dirname, 'DeviceModelsPage.vue'), 'utf8');

  it('reads the new catalogue only under the new login and never offers editing there', () => {
    expect(page).toMatch(/rows\.value = newApi \? await loadNewModels\(api\) : await loadLegacyModels\(/);
    expect(page).toMatch(/if \(!row\.editable\) return;/);
    expect(page).toMatch(/<a-table-column v-if="!newApi" title="" :width="90">/);
  });

  it('keeps the legacy live merge', () => {
    expect(page).toMatch(
      /newApi \? noop : wsClient\.subscribe\('event:storage:device_models:added', \(msg\) => mergeById\(rows, fromLegacyModel/,
    );
  });
});
