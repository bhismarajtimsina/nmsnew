import mutations from './mutations';
import { DataService } from '@/config/dataService/dataService';
import { getItem, setItem, removeItem } from '@/utility/localStorageControl';
import { wsClient } from '@/services/wsClient';
import Cookies from 'js-cookie';

// nginx's config reads a cookie literally named "Token" (`$cookie_Token`)
// to authorize the embedded external apps proxied through wca-eap —
// Grafana, Prometheus, Alertmanager, Oxidized, and the web console (ttyd,
// which also forwards it as the console's own `-t`/token CLI arg). The API
// itself is header-based (X-Auth-Key) and never needed a cookie, so this
// was never set anywhere in the app — confirmed live, every one of those
// links redirected straight to /login regardless of being signed in.
function setAuthCookie(key: string) {
  Cookies.set('Token', key, { path: '/', sameSite: 'lax' });
}
function clearAuthCookie() {
  Cookies.remove('Token', { path: '/' });
}

const state = () => ({
  login: !!getItem('wca_auth_key'),
  user: getItem('wca_user') || null,
  loading: false,
  error: null,
});

const actions = {
  async login({ commit }: { commit: any }, { login, password }: { login: string; password: string }) {
    try {
      commit('loginBegin');
      const { data } = await DataService.post('/auth', { login, password });
      // Response shape: { data: { key, user, expired_at, ... } } — see
      // POST /api/v1/auth. `key` is replayed as the X-Auth-Key header.
      setItem('wca_auth_key', data.data.key);
      setItem('wca_user', data.data.user);
      setAuthCookie(data.data.key);
      wsClient.connect();
      commit('loginSuccess', data.data.user);
      return true;
    } catch (err: any) {
      const status = err?.response?.status;
      let userMessage: string;
      if (status === 401 || status === 403) {
        // The API's auth-failure body is just a generic HTTP phrase
        // (e.g. {"message":"403 Forbidden"}) — not something to show a
        // user as-is. Bad credentials is the only realistic cause here.
        userMessage = 'Incorrect username or password.';
      } else if (!err?.response) {
        userMessage = 'Cannot reach the server — check your connection and try again.';
      } else {
        userMessage =
          err?.response?.data?.error?.description ||
          (typeof err?.response?.data?.message === 'string' && !/^\d{3}\s/.test(err.response.data.message)
            ? err.response.data.message
            : null) ||
          'Sign in failed — please try again.';
      }
      commit('loginErr', userMessage);
      return false;
    }
  },

  async logOut({ commit }: { commit: any }) {
    try {
      commit('logoutBegin');
      // Best-effort — session cleanup happens client-side regardless of
      // whether this call succeeds (e.g. key already expired).
      await DataService.delete('/logout').catch(() => {});
      removeItem('wca_auth_key');
      removeItem('wca_user');
      clearAuthCookie();
      wsClient.disconnectForLogout();
      commit('logoutSuccess', null);
    } catch (err) {
      commit('logoutErr', err);
    }
  },

  /**
   * Validates a stored key against the API on app boot (e.g. after a hard
   * refresh). Mirrors the check the old panel-auth.js did for standalone
   * pages — an internal "sys" account (id <= 0) does not count as signed in.
   */
  async checkSession({ commit }: { commit: any }) {
    const key = getItem('wca_auth_key');
    if (!key) {
      return false;
    }
    try {
      const { data } = await DataService.get('/user/self');
      const user = data.data;
      if (!user || typeof user.id !== 'number' || user.id <= 0) {
        throw new Error('not signed in');
      }
      setItem('wca_user', user);
      // Also (re)sets the cookie on every refresh for sessions that logged
      // in before this existed, so they don't need to sign out/in again to
      // get a working console/Grafana/Oxidized/Prometheus link.
      setAuthCookie(String(key));
      wsClient.connect();
      commit('loginSuccess', user);
      return true;
    } catch (err) {
      removeItem('wca_auth_key');
      removeItem('wca_user');
      clearAuthCookie();
      wsClient.disconnectForLogout();
      commit('logoutSuccess', null);
      return false;
    }
  },
};

export default {
  namespaced: false,
  state,
  actions,
  mutations,
};
