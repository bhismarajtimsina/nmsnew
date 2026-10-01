/**
 * Authentication state against the CyberSathy-NMS API (Plan 25).
 *
 * The only code that writes `cs_auth_key` and `cs_user` (D-26); it never reads or writes the legacy `wca_*` keys.
 * Used when the frontend is built with `VITE_AUTH_BACKEND=cybersathy` (see `src/auth/session.ts`); the legacy Vuex
 * auth module keeps serving the default build until the switch.
 */
import { defineStore } from 'pinia';
import { api, ApiError, AUTH_KEY, USER_KEY, type ApiClient } from '@/api/client';
import type { components } from '@/api/schema';

export type AuthUser = components['schemas']['AuthUser'];

export interface Credentials {
  login: string;
  password: string;
  twofaPin?: string;
  recoveryCode?: string;
}

export type LoginResult =
  | { ok: true; user: AuthUser; mustChangePassword: boolean }
  | { ok: false; needs2fa: true }
  | { ok: false; needs2fa: false; error: string };

let client: ApiClient = api;

/** Tests pass a client wired to a fake fetch; the app always uses the shared `api`. */
export function useAuthClient(replacement: ApiClient): void {
  client = replacement;
}

function readUser(): AuthUser | null {
  try {
    const raw = localStorage.getItem(USER_KEY);
    return raw ? (JSON.parse(raw) as AuthUser) : null;
  } catch {
    return null;
  }
}

function signInMessage(error: unknown): string {
  if (error instanceof ApiError) {
    if (error.status === 401) return 'Incorrect username or password.';
    if (error.status === 429) return error.message || 'Too many attempts. Wait a moment and try again.';
    if (error.status === 0) return 'Cannot reach the server. Check your connection and try again.';
    return error.message;
  }
  return 'Cannot reach the server. Check your connection and try again.';
}

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: readUser() as AuthUser | null,
    hasToken: !!localStorage.getItem(AUTH_KEY),
    loading: false,
    error: null as string | null,
  }),

  getters: {
    loggedIn: (state): boolean => state.hasToken && state.user !== null,
    permissions: (state): ReadonlySet<string> => new Set(state.user?.permissions ?? []),
    /** For menus and buttons only. The API enforces every permission itself; hiding is convenience, not security. */
    can(): (permission: string) => boolean {
      return (permission) => this.permissions.has(permission);
    },
    displayName: (state): string => state.user?.display_name || state.user?.username || '',
  },

  actions: {
    remember(token: string, user: AuthUser) {
      localStorage.setItem(AUTH_KEY, token);
      localStorage.setItem(USER_KEY, JSON.stringify(user));
      this.hasToken = true;
      this.user = user;
    },

    forget() {
      localStorage.removeItem(AUTH_KEY);
      localStorage.removeItem(USER_KEY);
      this.hasToken = false;
      this.user = null;
    },

    async login(credentials: Credentials): Promise<LoginResult> {
      this.loading = true;
      this.error = null;
      try {
        const { data } = await client.POST('/api/v1/auth/login', {
          body: {
            login: credentials.login,
            password: credentials.password,
            twofa_pin: credentials.twofaPin ?? null,
            recovery_code: credentials.recoveryCode ?? null,
          },
        });
        if (data?.need_2fa) return { ok: false, needs2fa: true };
        if (!data?.token || !data.user) throw new ApiError(500, 'The server returned no session');
        this.remember(data.token, data.user);
        return { ok: true, user: data.user, mustChangePassword: data.must_change_password };
      } catch (error) {
        this.error = signInMessage(error);
        return { ok: false, needs2fa: false, error: this.error };
      } finally {
        this.loading = false;
      }
    },

    /** Run once at start-up: a stored token counts only if the API still accepts it. */
    async checkSession(): Promise<boolean> {
      if (!localStorage.getItem(AUTH_KEY)) {
        this.forget();
        return false;
      }
      try {
        const { data } = await client.GET('/api/v1/auth/session');
        if (!data) throw new ApiError(500, null);
        localStorage.setItem(USER_KEY, JSON.stringify(data));
        this.user = data;
        this.hasToken = true;
        return true;
      } catch {
        // Any failure signs the shell out, as the legacy checkSession does: better a sign-in page than a "logged in"
        // shell whose every call fails.
        this.forget();
        return false;
      }
    },

    async logout(): Promise<void> {
      try {
        await client.POST('/api/v1/auth/logout');
      } catch {
        // Best effort: the session is dropped locally whatever the server says.
      } finally {
        this.forget();
      }
    },
  },
});
