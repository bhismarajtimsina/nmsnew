/**
 * The realtime connection to the CyberSathy-NMS API (Plans 22 and 25). Same shape as the legacy `services/wsClient.ts`
 * (one shared socket, channels resubscribed after every reconnect, capped exponential backoff, a full stop on
 * logout) with what the new API requires:
 *
 *   - every connection, first or reconnect, uses a fresh single-use ticket from `POST /api/v1/realtime/ticket`
 *     (`/ws?ticket=`); the session token never appears in a URL;
 *   - if the server refuses a ticket (close code 4401) three times in a row, the client stops instead of looping;
 *   - if minting a ticket itself fails with 401, the login is gone: the client stops (the API client has already
 *     cleared the session and sent the user to sign in).
 *
 * Messages are "something changed" signals; pages re-fetch through the typed API client.
 */
export interface RealtimeMessage {
  type: 'event';
  channel: string;
  data: unknown;
}
export type RealtimeHandler = (message: RealtimeMessage) => void;
export type RealtimeStatus = 'idle' | 'connecting' | 'open' | 'reconnecting' | 'stopped';

/* eslint-disable @typescript-eslint/no-explicit-any */
export interface SocketLike {
  readonly readyState: number;
  onopen: ((ev: any) => void) | null;
  onmessage: ((ev: { data: any }) => void) | null;
  onclose: ((ev: { code: number }) => void) | null;
  onerror: ((ev: any) => void) | null;
  send(data: string): void;
  close(): void;
}
/* eslint-enable @typescript-eslint/no-explicit-any */

export interface RealtimeDeps {
  /** Resolves to a ticket, or rejects; a rejection with `status === 401` means the login is gone. */
  mintTicket: () => Promise<string>;
  openSocket: (url: string) => SocketLike;
  socketUrl: (ticket: string) => string;
  setTimer: (fn: () => void, ms: number) => unknown;
  clearTimer: (handle: unknown) => void;
  onStatus?: (status: RealtimeStatus) => void;
}

const OPEN = 1;
export const UNAUTHORIZED_CLOSE = 4401;
export const MAX_TICKET_REFUSALS = 3;
export const MAX_BACKOFF_MS = 30000;

export class RealtimeClient {
  private socket: SocketLike | null = null;
  private handlers = new Map<string, Set<RealtimeHandler>>();
  private attempt = 0;
  private refusals = 0;
  private timer: unknown = null;
  private wanted = false;
  private generation = 0;
  status: RealtimeStatus = 'idle';

  constructor(private readonly deps: RealtimeDeps) {}

  private setStatus(status: RealtimeStatus): void {
    this.status = status;
    this.deps.onStatus?.(status);
  }

  /** Start (or keep) the connection. Safe to call repeatedly. */
  connect(): void {
    this.wanted = true;
    if (this.socket || this.status === 'connecting' || this.timer !== null) return;
    void this.open();
  }

  private async open(): Promise<void> {
    const generation = ++this.generation;
    this.setStatus(this.attempt === 0 ? 'connecting' : 'reconnecting');
    let ticket: string;
    try {
      ticket = await this.deps.mintTicket();
    } catch (error) {
      if (generation !== this.generation || !this.wanted) return;
      if ((error as { status?: number })?.status === 401) return this.stop();
      return this.retry();
    }
    if (generation !== this.generation || !this.wanted) return;
    const socket = this.deps.openSocket(this.deps.socketUrl(ticket));
    this.socket = socket;
    socket.onmessage = (ev) => this.receive(ev.data);
    socket.onclose = (ev) => {
      if (this.socket !== socket) return;
      this.socket = null;
      if (!this.wanted) return;
      if (ev.code === UNAUTHORIZED_CLOSE) {
        this.refusals += 1;
        if (this.refusals >= MAX_TICKET_REFUSALS) return this.stop();
      }
      this.retry();
    };
    socket.onerror = () => {};
    socket.onopen = () => {};
  }

  private receive(raw: string): void {
    let message: { type?: string; channel?: string; data?: unknown };
    try {
      message = JSON.parse(raw);
    } catch {
      return;
    }
    if (message.type === 'ready') {
      this.attempt = 0;
      this.refusals = 0;
      this.setStatus('open');
      this.handlers.forEach((_, channel) => this.send({ action: 'subscribe', channel }));
      return;
    }
    if (message.type !== 'event' || !message.channel) return;
    this.handlers.get(message.channel)?.forEach((handler) => {
      try {
        handler({ type: 'event', channel: message.channel!, data: message.data });
      } catch (error) {
        // eslint-disable-next-line no-console
        console.error('realtime handler failed', error);
      }
    });
  }

  private retry(): void {
    const delay = Math.min(MAX_BACKOFF_MS, 1000 * 2 ** this.attempt);
    this.attempt += 1;
    this.setStatus('reconnecting');
    this.timer = this.deps.setTimer(() => {
      this.timer = null;
      if (this.wanted) void this.open();
    }, delay);
  }

  private send(payload: object): void {
    if (this.socket?.readyState === OPEN) this.socket.send(JSON.stringify(payload));
  }

  /** Listen on a channel; returns the function that stops listening. */
  subscribe(channel: string, handler: RealtimeHandler): () => void {
    const isNew = !this.handlers.has(channel);
    if (isNew) this.handlers.set(channel, new Set());
    this.handlers.get(channel)!.add(handler);
    if (isNew) this.send({ action: 'subscribe', channel });
    return () => this.unsubscribe(channel, handler);
  }

  unsubscribe(channel: string, handler: RealtimeHandler): void {
    const set = this.handlers.get(channel);
    if (!set) return;
    set.delete(handler);
    if (set.size === 0) {
      this.handlers.delete(channel);
      this.send({ action: 'unsubscribe', channel });
    }
  }

  /** On logout: drop the socket, every subscription and any pending reconnect. */
  stop(): void {
    this.wanted = false;
    this.generation += 1;
    if (this.timer !== null) {
      this.deps.clearTimer(this.timer);
      this.timer = null;
    }
    const socket = this.socket;
    this.socket = null;
    socket?.close();
    this.handlers.clear();
    this.attempt = 0;
    this.refusals = 0;
    this.setStatus('stopped');
  }

  get channels(): string[] {
    return [...this.handlers.keys()];
  }
}
