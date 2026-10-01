/**
 * Where the device groups page reads and writes (Plan 25): the legacy `/device-group` calls, or with
 * `VITE_AUTH_BACKEND=cybersathy` the typed client against `/api/v1/device-groups`. Both give the page the same row
 * shape and the same four operations, so the page itself does not care which API answered.
 */
import { ApiError, type ApiClient } from '@/api/client';
import type { Id } from './deviceList';

export interface GroupRow {
  id: Id;
  name: string;
  description: string | null;
  created_at: string;
  /** Legacy's built-in groups (negative ids) cannot be edited or deleted. The new API has none. */
  builtIn: boolean;
  /** Devices in the group; only the new API reports it. */
  devices: number | null;
}

export interface GroupInput {
  name: string;
  description: string;
}

export interface GroupsSource {
  list(): Promise<GroupRow[]>;
  create(input: GroupInput): Promise<void>;
  update(id: Id, input: GroupInput): Promise<void>;
  remove(id: Id): Promise<void>;
  /** The message to show for a failed save or delete. */
  errorMessage(error: unknown): string;
}

/** The legacy DataService, as far as this page uses it. */
export interface LegacyHttp {
  get(path: string): Promise<{ data: { data?: unknown } }>;
  post(path: string, body: unknown): Promise<unknown>;
  put(path: string, body: unknown): Promise<unknown>;
  delete(path: string): Promise<unknown>;
}

export interface LegacyGroup {
  id: number;
  name: string;
  description: string | null;
  created_at: string;
}

// Exactly what the legacy page sent: untrimmed, and an empty description left out.
const body = (input: GroupInput) => ({ name: input.name, description: input.description || undefined });

/** A legacy group row, from the list or pushed over the legacy WebSocket. */
export function fromLegacyGroup(g: LegacyGroup): GroupRow {
  return {
    id: g.id,
    name: g.name,
    description: g.description,
    created_at: g.created_at,
    builtIn: g.id < 0,
    devices: null,
  };
}

export function legacySource(http: LegacyHttp): GroupsSource {
  return {
    async list() {
      const { data } = await http.get('/device-group');
      return ((data.data as LegacyGroup[] | undefined) ?? []).map(fromLegacyGroup);
    },
    async create(input) {
      await http.post('/device-group', body(input));
    },
    async update(id, input) {
      await http.put(`/device-group/${id}`, body(input));
    },
    async remove(id) {
      await http.delete(`/device-group/${id}`);
    },
    errorMessage(error) {
      return (error as any)?.response?.data?.error?.description || 'Please try again.';
    },
  };
}

export function newApiSource(client: ApiClient): GroupsSource {
  const path = (id: Id) => ({ params: { path: { group_id: String(id) } } });
  return {
    async list() {
      const { data } = await client.GET('/api/v1/device-groups');
      return (data ?? []).map((g) => ({
        id: g.id,
        name: g.name,
        description: g.description,
        created_at: g.created_at,
        builtIn: false,
        devices: g.devices,
      }));
    },
    async create(input) {
      // An empty description is sent as null: the new API stores "no description", not an empty string.
      await client.POST('/api/v1/device-groups', {
        body: { name: input.name.trim(), description: input.description.trim() || null },
      });
    },
    async update(id, input) {
      await client.PATCH('/api/v1/device-groups/{group_id}', {
        ...path(id),
        body: { name: input.name.trim(), description: input.description.trim() || null },
      });
    },
    async remove(id) {
      await client.DELETE('/api/v1/device-groups/{group_id}', path(id));
    },
    errorMessage(error) {
      // A 409 carries the reason in the API's own words, e.g. a group that still has devices.
      return error instanceof ApiError ? error.message : 'Please try again.';
    },
  };
}
