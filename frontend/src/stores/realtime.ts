/**
 * The shared realtime connection as a store (Plan 25): pages subscribe here, and the auth store starts it after a
 * sign-in or a successful session check and stops it on sign-out. Used with `VITE_AUTH_BACKEND=cybersathy`; the legacy
 * build keeps `services/wsClient.ts`.
 */
import { defineStore } from 'pinia';
import { api, ApiError } from '@/api/client';
import { RealtimeClient, type RealtimeDeps, type RealtimeHandler, type RealtimeStatus, type SocketLike } from '@/realtime/client';

export function browserDeps(onStatus: (status: RealtimeStatus) => void): RealtimeDeps {
  return {
    async mintTicket() {
      const { data } = await api.POST('/api/v1/realtime/ticket');
      if (!data?.ticket) throw new ApiError(500, 'no ticket');
      return data.ticket;
    },
    openSocket: (url) => new WebSocket(url) as unknown as SocketLike,
    socketUrl: (ticket) => {
      const proto = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
      return `${proto}//${window.location.host}/ws?ticket=${encodeURIComponent(ticket)}`;
    },
    setTimer: (fn, ms) => window.setTimeout(fn, ms),
    clearTimer: (handle) => window.clearTimeout(handle as number),
    onStatus,
  };
}

let depsFactory: (onStatus: (status: RealtimeStatus) => void) => RealtimeDeps = browserDeps;

/** Tests replace the socket, the ticket call and the timers. */
export function useRealtimeDeps(factory: typeof depsFactory): void {
  depsFactory = factory;
}

// One connection per app, kept out of the store's reactive state (it holds a socket and timers, not data).
const clients = new WeakMap<object, RealtimeClient>();

export const useRealtimeStore = defineStore('realtime', {
  state: () => ({
    status: 'idle' as RealtimeStatus,
  }),

  actions: {
    ensureClient(): RealtimeClient {
      let client = clients.get(this);
      if (!client) {
        const store = this;
        client = new RealtimeClient(depsFactory((status) => { store.status = status; }));
        clients.set(this, client);
      }
      return client;
    },

    start() {
      this.ensureClient().connect();
    },

    stop() {
      clients.get(this)?.stop();
    },

    subscribe(channel: string, handler: RealtimeHandler): () => void {
      return this.ensureClient().subscribe(channel, handler);
    },
  },
});
