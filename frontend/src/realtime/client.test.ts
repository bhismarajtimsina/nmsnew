import { beforeEach, describe, expect, it } from 'vitest';
import { MAX_BACKOFF_MS, RealtimeClient, UNAUTHORIZED_CLOSE, type RealtimeDeps, type SocketLike } from './client';

class FakeSocket implements SocketLike {
  readyState = 0;
  sent: unknown[] = [];
  closed = false;
  onopen: SocketLike['onopen'] = null;
  onmessage: SocketLike['onmessage'] = null;
  onclose: SocketLike['onclose'] = null;
  onerror: SocketLike['onerror'] = null;
  constructor(readonly url: string) {}
  send(data: string) { this.sent.push(JSON.parse(data)); }
  close() { this.closed = true; }
  // test helpers
  ready() { this.readyState = 1; this.onmessage?.({ data: JSON.stringify({ type: 'ready', user: {} }) }); }
  push(message: object) { this.onmessage?.({ data: JSON.stringify(message) }); }
  drop(code = 1006) { this.readyState = 3; this.onclose?.({ code }); }
}

let sockets: FakeSocket[];
let timers: { fn: () => void; ms: number }[];
let tickets: number;
let mint: () => Promise<string>;

function deps(): RealtimeDeps {
  return {
    mintTicket: () => mint(),
    openSocket: (url) => { const s = new FakeSocket(url); sockets.push(s); return s; },
    socketUrl: (ticket) => `ws://nms.test/ws?ticket=${ticket}`,
    setTimer: (fn, ms) => { const t = { fn, ms }; timers.push(t); return t; },
    clearTimer: (t) => { timers = timers.filter((x) => x !== t); },
  };
}

const flush = () => new Promise((r) => setTimeout(r, 0));
async function fireTimer() { const t = timers.shift()!; t.fn(); await flush(); return t.ms; }

beforeEach(() => {
  sockets = []; timers = []; tickets = 0;
  mint = async () => `csw_${++tickets}`;
});

describe('the realtime client', () => {
  it('connects with a single-use ticket, never the session token', async () => {
    const client = new RealtimeClient(deps());
    client.connect();
    await flush();
    expect(sockets).toHaveLength(1);
    expect(sockets[0].url).toBe('ws://nms.test/ws?ticket=csw_1');
    expect(sockets[0].url).not.toContain('token');
  });

  it('delivers events only to that channel and resubscribes everything after a reconnect, with a fresh ticket', async () => {
    const client = new RealtimeClient(deps());
    const got: unknown[] = [];
    client.subscribe('events.created', (m) => got.push(m.data));
    client.connect();
    await flush();
    sockets[0].ready();
    expect(sockets[0].sent).toEqual([{ action: 'subscribe', channel: 'events.created' }]);
    sockets[0].push({ type: 'event', channel: 'events.created', data: { id: 'e1' } });
    sockets[0].push({ type: 'event', channel: 'devices.updated', data: { id: 'd1' } });
    expect(got).toEqual([{ id: 'e1' }]);

    sockets[0].drop();
    expect(client.status).toBe('reconnecting');
    await fireTimer();
    expect(sockets[1].url).toBe('ws://nms.test/ws?ticket=csw_2'); // a used ticket is never reused
    sockets[1].ready();
    expect(sockets[1].sent).toEqual([{ action: 'subscribe', channel: 'events.created' }]);
    expect(client.status).toBe('open');
  });

  it('backs off exponentially up to a cap, and resets once connected', async () => {
    const client = new RealtimeClient(deps());
    client.connect();
    await flush();
    const delays: number[] = [];
    for (let i = 0; i < 7; i++) {
      sockets[sockets.length - 1].drop();
      delays.push(await fireTimer());
    }
    expect(delays).toEqual([1000, 2000, 4000, 8000, 16000, MAX_BACKOFF_MS, MAX_BACKOFF_MS]);
    sockets[sockets.length - 1].ready();
    sockets[sockets.length - 1].drop();
    expect(await fireTimer()).toBe(1000);
  });

  it('stops after the server refuses three tickets in a row instead of looping', async () => {
    const client = new RealtimeClient(deps());
    client.connect();
    await flush();
    sockets[0].drop(UNAUTHORIZED_CLOSE);
    await fireTimer();
    sockets[1].drop(UNAUTHORIZED_CLOSE);
    await fireTimer();
    sockets[2].drop(UNAUTHORIZED_CLOSE);
    expect(client.status).toBe('stopped');
    expect(timers).toHaveLength(0);
  });

  it('stops when minting a ticket says the login is gone, and retries other mint failures', async () => {
    mint = async () => { throw Object.assign(new Error('down'), { status: 503 }); };
    const client = new RealtimeClient(deps());
    client.connect();
    await flush();
    expect(client.status).toBe('reconnecting');
    expect(timers).toHaveLength(1);

    mint = async () => { throw Object.assign(new Error('gone'), { status: 401 }); };
    await fireTimer();
    expect(client.status).toBe('stopped');
    expect(timers).toHaveLength(0);
    expect(sockets).toHaveLength(0);
  });

  it('stop() on logout closes the socket, forgets subscriptions and cancels a pending reconnect', async () => {
    const client = new RealtimeClient(deps());
    client.subscribe('events.created', () => {});
    client.connect();
    await flush();
    sockets[0].drop();
    expect(timers).toHaveLength(1);
    client.stop();
    expect(timers).toHaveLength(0);
    expect(client.channels).toEqual([]);
    client.connect(); // a later sign-in starts clean
    await flush();
    sockets[1].ready();
    expect(sockets[1].sent).toEqual([]);
  });

  it('unsubscribes from the server only when the last listener on a channel leaves', async () => {
    const client = new RealtimeClient(deps());
    client.connect();
    await flush();
    sockets[0].ready();
    const offA = client.subscribe('events.created', () => {});
    const offB = client.subscribe('events.created', () => {});
    offA();
    expect(sockets[0].sent).toEqual([{ action: 'subscribe', channel: 'events.created' }]);
    offB();
    expect(sockets[0].sent.at(-1)).toEqual({ action: 'unsubscribe', channel: 'events.created' });
  });
});
