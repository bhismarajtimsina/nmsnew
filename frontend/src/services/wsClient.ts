// Shared real-time WebSocket client — one connection for the whole app,
// reused by every page/component that wants live updates instead of a
// manual refresh.
//
// Protocol (server: WCAA\Console\WebSocketServerCommand, `/ws` proxied by
// nginx to wca-ws:9501):
//   connect  -> wss://<host>/ws?token=<wca_auth_key>
//   server   -> {"type":"ready","user":{...}} on success,
//               {"type":"error","error":"unauthorized"} + close otherwise
//   client   -> {"action":"subscribe","channel":"<name>"}
//   server   -> {"type":"subscribed","channel":"<name>"}
//   server   -> {"type":"event"|"update","channel":"<name>","data":{...},"ts":...}
//     for anything published on that channel afterwards
//
// Channels are exact names (e.g. "event:device:updated",
// "event:poller:finished", "event:storage:links:updated") — the server
// restricts "*" wildcard subscriptions to a privileged "owner" group
// (WebSocketServerCommand.php's subscribe handler), unverified for the
// account this deployment uses, so callers filter on payload fields
// (e.g. msg.data.device.id) instead of relying on wildcard channels.
//
// Messages carry only "this changed, go refetch" signals, never the row
// itself — callers re-fetch through the existing DataService/cache-backed
// endpoints. This keeps WebSockets purely about delivery speed, not about
// increasing how often we actually query live devices.

import { getItem } from '@/utility/localStorageControl';

export interface WsMessage {
  type: string;
  channel: string;
  data: any;
  ts: number;
}
export type WsHandler = (msg: WsMessage) => void;

class WsClient {
  private socket: WebSocket | null = null;
  private handlers = new Map<string, Set<WsHandler>>();
  // Channels we've asked the server for — resent after every reconnect
  // (a fresh socket has no memory of what was subscribed before).
  private subscribedChannels = new Set<string>();
  private reconnectAttempt = 0;
  private reconnectTimer: number | null = null;
  private manuallyClosed = false;

  private wsUrl(): string | null {
    const token = getItem('wca_auth_key');
    if (!token || typeof token !== 'string') return null;
    const proto = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
    return `${proto}//${window.location.host}/ws?token=${encodeURIComponent(token)}`;
  }

  connect(): void {
    if (this.socket && (this.socket.readyState === WebSocket.OPEN || this.socket.readyState === WebSocket.CONNECTING)) {
      return;
    }
    const url = this.wsUrl();
    if (!url) return; // not logged in yet — the next login()/subscribe() call retries

    this.manuallyClosed = false;
    const socket = new WebSocket(url);
    this.socket = socket;

    socket.onopen = () => {
      this.reconnectAttempt = 0;
    };

    socket.onmessage = (evt) => {
      let msg: any;
      try {
        msg = JSON.parse(evt.data);
      } catch {
        return;
      }
      if (msg.type === 'ready') {
        // Fresh (or reconnected) session — (re)subscribe to everything
        // components have registered so far.
        this.subscribedChannels.forEach((ch) => this.sendSubscribe(ch));
        return;
      }
      if (msg.type === 'error' || msg.type === 'subscribed' || msg.type === 'unsubscribed' || msg.type === 'pong') {
        return;
      }
      const channel = msg.channel as string | undefined;
      if (!channel) return;
      this.handlers.get(channel)?.forEach((handler) => {
        try {
          handler(msg as WsMessage);
        } catch (e) {
          // eslint-disable-next-line no-console
          console.error('wsClient handler error', e);
        }
      });
    };

    socket.onclose = () => {
      this.socket = null;
      if (!this.manuallyClosed) this.scheduleReconnect();
    };

    // onerror is always followed by onclose in browsers — reconnect is
    // handled there, nothing extra to do here.
    socket.onerror = () => {};
  }

  private scheduleReconnect(): void {
    if (this.reconnectTimer !== null) return;
    const delay = Math.min(30000, 1000 * 2 ** this.reconnectAttempt);
    this.reconnectAttempt++;
    this.reconnectTimer = window.setTimeout(() => {
      this.reconnectTimer = null;
      this.connect();
    }, delay);
  }

  private sendSubscribe(channel: string): void {
    if (this.socket?.readyState === WebSocket.OPEN) {
      this.socket.send(JSON.stringify({ action: 'subscribe', channel }));
    }
  }

  /**
   * Subscribe to a channel. Returns an unsubscribe function — call it from
   * onBeforeUnmount (or just call unsubscribe(channel, handler) directly).
   */
  subscribe(channel: string, handler: WsHandler): () => void {
    if (!this.handlers.has(channel)) this.handlers.set(channel, new Set());
    this.handlers.get(channel)!.add(handler);

    const isNewChannel = !this.subscribedChannels.has(channel);
    this.subscribedChannels.add(channel);
    this.connect();
    if (isNewChannel) this.sendSubscribe(channel);

    return () => this.unsubscribe(channel, handler);
  }

  unsubscribe(channel: string, handler: WsHandler): void {
    const set = this.handlers.get(channel);
    if (!set) return;
    set.delete(handler);
    if (set.size === 0) {
      this.handlers.delete(channel);
      this.subscribedChannels.delete(channel);
      if (this.socket?.readyState === WebSocket.OPEN) {
        this.socket.send(JSON.stringify({ action: 'unsubscribe', channel }));
      }
    }
  }

  /**
   * Called on logout (and on a 401/403 auth failure) — drops the
   * connection entirely so a stale authenticated socket doesn't linger
   * after the user signs out.
   */
  disconnectForLogout(): void {
    this.manuallyClosed = true;
    this.subscribedChannels.clear();
    this.handlers.clear();
    if (this.reconnectTimer !== null) {
      window.clearTimeout(this.reconnectTimer);
      this.reconnectTimer = null;
    }
    this.socket?.close();
    this.socket = null;
  }
}

export const wsClient = new WsClient();
