import axios, { type RawAxiosRequestHeaders } from 'axios';
import { getItem } from '../../utility/localStorageControl';
import { wsClient } from '../../services/wsClient';

const API_ENDPOINT = import.meta.env.VITE_API_ENDPOINT;

// WCA (Support API) authenticates with an X-Auth-Key header, not a Bearer
// token — see POST /api/v1/auth, whose response body's `data.key` is what
// gets stored here and replayed on every subsequent request.
const authHeader = (): RawAxiosRequestHeaders => {
  const key = getItem('wca_auth_key');
  return typeof key === 'string' && key ? { 'X-Auth-Key': key } : {};
};

const client = axios.create({
  baseURL: API_ENDPOINT,
  headers: {
    'Content-Type': 'application/json',
  },
  // Confirmed live: axios has no timeout by default (waits forever), and
  // this app has real slow-but-legitimate calls (console/macro-driven ONT
  // actions can genuinely take over a minute on a real device). But when
  // the backend's own PHP worker gets killed mid-request instead of
  // returning a clean error, the browser's request was observed to just
  // hang — no response, no error, no timeout — leaving a button stuck
  // showing "loading" indefinitely with zero feedback.
  //
  // PATCHED: this was 200000 (200s), based on the backend's
  // max_execution_time being 180s at the time — but the backend's OWN
  // configured console-operation budget (SWC_CONSOLE_TIMEOUT_SEC in .env)
  // is 300s, meaning this timeout could fire and give up on a device
  // action BEFORE the backend's own intended budget had even been used up
  // (a real, confirmed mismatch — see docker/roadrunner/php.ini's matching
  // fix for max_execution_time, raised from 180s to 360s for the same
  // reason). 340s sits between the backend's 300s console budget and its
  // new 360s PHP execution ceiling — comfortably past the slowest a
  // legitimate device operation is configured to take, so it fires only
  // for a genuine "nothing is ever coming back" case.
  timeout: 340000,
});

class DataService {
  static get(path = '', params = {}) {
    return client({
      method: 'GET',
      url: path,
      params,
      headers: { ...authHeader() },
    });
  }

  static post(path = '', data = {}, optionalHeader = {}) {
    return client({
      method: 'POST',
      url: path,
      data,
      headers: { ...authHeader(), ...optionalHeader },
    });
  }

  static patch(path = '', data = {}) {
    return client({
      method: 'PATCH',
      url: path,
      data,
      headers: { ...authHeader() },
    });
  }

  static delete(path = '', data = {}) {
    return client({
      method: 'DELETE',
      url: path,
      data,
      headers: { ...authHeader() },
    });
  }

  static put(path = '', data = {}) {
    return client({
      method: 'PUT',
      url: path,
      data,
      headers: { ...authHeader() },
    });
  }
}

/**
 * axios interceptors run before/after every request.
 */
client.interceptors.request.use((config) => {
  const requestConfig: any = config;
  requestConfig.headers = { ...config.headers, ...authHeader() };
  return requestConfig;
});

client.interceptors.response.use(
  (response) => response,
  (error) => {
    const { response } = error;
    if (response && (response.status === 401 || response.status === 403)) {
      // Session expired or invalid — clear the stale key so the auth guard
      // sends the user back to the login screen instead of looping on 401s.
      localStorage.removeItem('wca_auth_key');
      localStorage.removeItem('wca_user');
      wsClient.disconnectForLogout();
    }
    return Promise.reject(error);
  },
);

export { DataService };
