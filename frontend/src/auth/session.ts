/**
 * Which API the frontend signs in against, chosen at build time (Plan 25):
 *
 *   VITE_AUTH_BACKEND=legacy       (default) the legacy Vuex auth module and DataService, exactly as before
 *   VITE_AUTH_BACKEND=cybersathy   the Pinia auth store and the typed client against the new API
 *
 * The default never changes what a production build does. The switch is flipped when the new API is the one serving
 * this frontend (Plan 34), not before. The router, start-up and the sign-in page only ever call this module.
 */
import { useAuthStore } from '@/stores/auth';
import legacyStore from '@/vuex/store';

export type AuthBackend = 'legacy' | 'cybersathy';

export function authBackend(value: string | undefined = import.meta.env.VITE_AUTH_BACKEND): AuthBackend {
  return value === 'cybersathy' ? 'cybersathy' : 'legacy';
}

export interface SignInResult {
  ok: boolean;
  needs2fa: boolean;
  error: string | null;
  userName: string;
}

const legacy: any = legacyStore;

export function isLoggedIn(backend: AuthBackend = authBackend()): boolean {
  return backend === 'cybersathy' ? useAuthStore().loggedIn : !!legacy.state.auth.login;
}

export async function checkSession(backend: AuthBackend = authBackend()): Promise<boolean> {
  return backend === 'cybersathy' ? useAuthStore().checkSession() : legacy.dispatch('checkSession');
}

export function isSigningIn(backend: AuthBackend = authBackend()): boolean {
  return backend === 'cybersathy' ? useAuthStore().loading : !!legacy.state.auth.loading;
}

export async function signIn(
  credentials: { login: string; password: string; twofaPin?: string },
  backend: AuthBackend = authBackend(),
): Promise<SignInResult> {
  if (backend === 'cybersathy') {
    const auth = useAuthStore();
    const result = await auth.login(credentials);
    if (result.ok) return { ok: true, needs2fa: false, error: null, userName: auth.displayName };
    const needs2fa = 'needs2fa' in result && result.needs2fa === true;
    return { ok: false, needs2fa, error: needs2fa ? null : auth.error, userName: '' };
  }
  const ok: boolean = await legacy.dispatch('login', { login: credentials.login, password: credentials.password });
  return {
    ok,
    needs2fa: false,
    error: ok ? null : legacy.state.auth.error,
    userName: ok ? legacy.state.auth.user?.name || credentials.login : '',
  };
}
