/**
 * The typed client for the CyberSathy-NMS API (Plan 24).
 *
 * Request and response types come from `schema.d.ts`, generated from `openapi.json`, which the backend writes and
 * checks (`python -m app.cli openapi --write`, `tests/test_openapi_snapshot.py`). So a backend change that breaks a
 * call here fails the type-check instead of failing in a user's browser.
 *
 * One place for what every call needs:
 *   - the `Authorization: Bearer <token>` header, read from `cs_auth_key` (D-26) on every request;
 *   - error messages: a failed call becomes an `ApiError` whose message is the API's own `detail`;
 *   - 401: the stored credential is cleared and the user is sent to the login page.
 *
 * The legacy `config/dataService/dataService.ts` (X-Auth-Key, `wca_*` keys) keeps serving pages not yet moved; a
 * page moves to this client as a whole, never half and half.
 */
import createClient, { type Middleware } from 'openapi-fetch';
import type { paths } from './schema';

export const AUTH_KEY = 'cs_auth_key';
export const USER_KEY = 'cs_user';

export interface KeyStore {
  get(key: string): string | null;
  remove(key: string): void;
}

export class ApiError extends Error {
  readonly status: number;
  readonly detail: unknown;

  constructor(status: number, detail: unknown) {
    super(describe(status, detail));
    this.name = 'ApiError';
    this.status = status;
    this.detail = detail;
  }
}

/** The API's own words where it gives some: FastAPI sends `{detail: "..."}`, or a list of field errors for a 422. */
export function describe(status: number, detail: unknown): string {
  if (typeof detail === 'string' && detail) return detail;
  if (Array.isArray(detail) && detail.length) {
    return detail
      .map((item: { loc?: unknown[]; msg?: string }) => {
        const field = Array.isArray(item?.loc) ? item.loc.filter((part) => part !== 'body').join('.') : '';
        return field ? `${field}: ${item?.msg ?? 'invalid'}` : item?.msg ?? 'invalid';
      })
      .join('; ');
  }
  if (status === 0) return 'The server could not be reached';
  if (status === 403) return 'You do not have permission to do this';
  if (status === 404) return 'Not found';
  if (status >= 500) return 'The server failed to handle the request';
  return `Request failed (${status})`;
}

export interface ClientOptions {
  baseUrl: string;
  store: KeyStore;
  /** Called after a 401 has cleared the stored credential: typically, go to the login page. */
  onUnauthorized: () => void;
  fetch?: typeof fetch;
}

export function createApiClient({ baseUrl, store, onUnauthorized, fetch: fetchImpl }: ClientOptions) {
  const auth: Middleware = {
    onRequest({ request }) {
      const token = store.get(AUTH_KEY);
      if (token) request.headers.set('Authorization', `Bearer ${token}`);
      return request;
    },
    async onResponse({ response }) {
      if (response.ok) return response;
      if (response.status === 401) {
        store.remove(AUTH_KEY);
        store.remove(USER_KEY);
        onUnauthorized();
      }
      let detail: unknown = null;
      try {
        detail = (await response.clone().json())?.detail ?? null;
      } catch {
        detail = null;
      }
      throw new ApiError(response.status, detail);
    },
  };
  const client = createClient<paths>({ baseUrl, ...(fetchImpl ? { fetch: fetchImpl } : {}) });
  client.use(auth);
  return client;
}

export type ApiClient = ReturnType<typeof createApiClient>;

const browserStore: KeyStore = {
  get: (key) => (typeof window === 'undefined' ? null : window.localStorage.getItem(key)),
  remove: (key) => {
    if (typeof window !== 'undefined') window.localStorage.removeItem(key);
  },
};

/** The client every migrated page uses. */
export const api = createApiClient({
  baseUrl: import.meta.env.VITE_CS_API_BASE ?? '',
  store: browserStore,
  onUnauthorized: () => {
    if (typeof window !== 'undefined' && !window.location.pathname.endsWith('/auth/login')) {
      window.location.assign(`${import.meta.env.BASE_URL ?? '/'}auth/login`);
    }
  },
});
