/**
 * Shared session guard for the standalone pages served alongside the vendor SPA.
 *
 * The SPA stores its session in localStorage under "auth" as {key, user, ...};
 * getAuthKey() reads .key. These pages reuse that session rather than asking for
 * credentials again.
 *
 * IMPORTANT - what this does and does not protect:
 *   The page shell is static HTML/JS and contains no secrets, exactly like the
 *   vendor's own index.html. This guard stops the UI rendering for someone
 *   without a session; it is NOT a substitute for the API enforcing auth.
 *   While TRUSTED_HOST_NETWORK_LIST='0.0.0.0/0' the API answers unauthenticated
 *   callers as the "sys" system user, so anyone can still read data with curl.
 *   That is why requireSession() rejects the system fallback (id <= 0) as well
 *   as a missing key - otherwise every anonymous visitor would look "logged in".
 */
(function (global) {
  'use strict';

  const API = '/api/v1';

  // Pages may offer a manually pasted token (monitoring-targets.html does) for
  // use outside a logged-in browser session; those are accepted as a fallback.
  const FALLBACK_TOKEN_KEYS = ['support_monitoring_token'];

  function readSession() {
    try {
      const raw = localStorage.getItem('auth');
      if (raw) {
        const auth = JSON.parse(raw);
        if (auth && auth.key) return auth;
      }
    } catch (e) {
      /* corrupt entry falls through to the manual token */
    }
    for (const k of FALLBACK_TOKEN_KEYS) {
      const t = localStorage.getItem(k);
      if (t) return { key: t, manual: true };
    }
    return null;
  }

  function authKey() {
    const s = readSession();
    return s ? s.key : '';
  }

  function headers(extra) {
    const h = Object.assign({ 'Content-Type': 'application/json' }, extra || {});
    const k = authKey();
    if (k) h['X-Auth-Key'] = k;
    return h;
  }

  function denyScreen(title, detail) {
    document.body.innerHTML =
      '<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;' +
      'font-family:Arial,Helvetica,sans-serif;background:#f6f8fb;color:#172046;margin:0">' +
      '<div style="background:#fff;border:1px solid #d8dee8;border-radius:10px;padding:34px 40px;' +
      'max-width:460px;text-align:center;box-shadow:0 10px 30px rgba(23,32,70,.08)">' +
      '<div style="font-size:34px;line-height:1;margin-bottom:14px">&#128274;</div>' +
      '<h1 style="margin:0 0 10px;font-size:20px">' + title + '</h1>' +
      '<p style="margin:0 0 22px;color:#667085;font-size:14px;line-height:1.5">' + detail + '</p>' +
      '<a href="/" style="display:inline-block;background:#1769e0;color:#fff;text-decoration:none;' +
      'padding:10px 22px;border-radius:6px;font-size:14px;font-weight:600">Go to login</a>' +
      '</div></div>';
  }

  /**
   * Resolve to the signed-in user, or block the page.
   * @returns {Promise<object>} the authenticated user (id > 0)
   */
  async function requireSession() {
    const session = readSession();
    if (!session) {
      denyScreen('Sign in required',
        'No active session was found in this browser. Sign in to the panel first, then reopen this page.');
      throw new Error('no session');
    }

    let res;
    try {
      res = await fetch(API + '/user/self', { headers: headers() });
    } catch (e) {
      denyScreen('Cannot reach the API',
        'The server did not respond, so the session could not be verified. It may be restarting.');
      throw new Error('api unreachable');
    }

    if (res.status === 401 || res.status === 403) {
      denyScreen('Session expired',
        'Your session is no longer valid. Sign in again to continue.');
      throw new Error('unauthorised');
    }
    if (!res.ok) {
      denyScreen('Cannot verify session', 'The server returned HTTP ' + res.status + '.');
      throw new Error('verify failed');
    }

    const user = (await res.json()).data;
    // id <= 0 means the API answered as an internal account (sys/console/cron),
    // which is what an unauthenticated caller resolves to on this install.
    if (!user || typeof user.id !== 'number' || user.id <= 0) {
      denyScreen('Sign in required',
        'The API answered as an internal system account rather than a signed-in user. Sign in to the panel first.');
      throw new Error('system account');
    }
    return user;
  }

  global.PanelAuth = { requireSession, authKey, headers, readSession };
})(window);
