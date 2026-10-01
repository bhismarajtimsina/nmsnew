/**
 * Where the device models page reads from (Plan 25): legacy `/device-model`, or with `VITE_AUTH_BACKEND=cybersathy`
 * the typed client against `/api/v1/device-models`. Both become the same row shape.
 *
 * The new catalogue is generated from the legacy model configuration and the vendor registry (Plans 5 to 8), and the
 * API offers it read-only: rows from it are not editable, and the page shows how each model is detected instead of
 * legacy's default pollers.
 */
import type { ApiClient } from '@/api/client';
import type { Id } from './deviceList';

export interface ModelRow {
  id: Id;
  key: string;
  name: string;
  type: string;
  vendor: string;
  /** Legacy only: poller name -> default interval in seconds. */
  pollers: Record<string, number> | null;
  /** New API only: what identifies the model during discovery. */
  detection: string | null;
  editable: boolean;
}

/** One legacy `/device-model` row (also the shape pushed over the legacy WebSocket). */
export interface LegacyModel {
  id: number;
  key: string;
  name: string;
  type: string;
  vendor: string;
  icon: string | null;
  pollers: Record<string, number> | null;
}

export function fromLegacyModel(m: LegacyModel): ModelRow {
  return {
    id: m.id,
    key: m.key,
    name: m.name,
    type: m.type,
    vendor: m.vendor,
    pollers: m.pollers,
    detection: null,
    editable: true,
  };
}

export async function loadLegacyModels(
  get: (path: string) => Promise<{ data: { data?: unknown } }>,
): Promise<ModelRow[]> {
  const { data } = await get('/device-model');
  return ((data.data as LegacyModel[] | undefined) ?? []).map(fromLegacyModel);
}

/** How discovery recognises the model, in words: its sysObjectID matcher, its sysDescr pattern, or both. */
export function detectionSummary(sysObjectId: string | null, sysDescr: string | null): string | null {
  const parts = [sysObjectId && `sysObjectID ${sysObjectId}`, sysDescr && `sysDescr /${sysDescr}/`].filter(Boolean);
  return parts.length ? parts.join(' · ') : null;
}

export async function loadNewModels(client: ApiClient): Promise<ModelRow[]> {
  const { data } = await client.GET('/api/v1/device-models');
  return (data ?? []).map((m) => ({
    id: m.id,
    // Catalogue entries imported from legacy keep their legacy key; anything added natively has none.
    key: m.legacy_key ?? '',
    name: m.model_name,
    type: m.device_type,
    vendor: m.vendor,
    pollers: null,
    detection: detectionSummary(m.sysobjectid_matcher, m.sysdescr_pattern),
    editable: false,
  }));
}
