/**
 * The browser side of the console gateway (Plan 38). The API issues a single-use ticket (30 seconds, needs
 * `console.open`, scoped to the device); the terminal then opens a WebSocket to the gateway at the same origin,
 * `/console/ws?ticket=...`, which nginx forwards to the separate gateway process. The gateway shows a banner saying the
 * session is recorded, relays keystrokes and output, and hides from the record whatever is typed at a password prompt.
 *
 * Kept free of xterm.js and of the real WebSocket so it is tested in Node; ConsoleTerminal.vue wires them in.
 */
import { ApiError, type ApiClient } from '@/api/client';
import type { components } from '@/api/schema';

export type ConsoleTicket = components['schemas']['ConsoleTicket'];
export type ConsoleSession = components['schemas']['ConsoleSessionOut'];
export type ConsoleHistory = components['schemas']['ConsoleHistory'];

export const OPEN_PERMISSION = 'console.open';
export const AUTO_AUTH_PERMISSION = 'console.open_auto_auth';
export const LOGS_PERMISSION = 'console.logs.view';
/** The gateway's close codes (app/console/gateway.py). */
export const UNAUTHORIZED = 4401;
export const UNAVAILABLE = 4503;

export function consoleApi(client: ApiClient) {
  return {
    async request(deviceId: string, autoAuth = false): Promise<ConsoleTicket> {
      const { data } = await client.POST('/api/v1/console/sessions', {
        body: { device_id: deviceId, auto_auth: autoAuth },
      });
      if (!data) throw new ApiError(500, null);
      return data;
    },
    async sessions(deviceId?: string): Promise<ConsoleSession[]> {
      const { data } = await client.GET('/api/v1/console/sessions', {
        params: { query: deviceId ? { device_id: deviceId } : {} },
      });
      return data?.items ?? [];
    },
    async history(sessionId: string, afterSeq = 0): Promise<ConsoleHistory> {
      const { data } = await client.GET('/api/v1/console/sessions/{session_id}/history', {
        params: { path: { session_id: sessionId }, query: { after_seq: afterSeq } },
      });
      if (!data) throw new ApiError(500, null);
      return data;
    },
  };
}

export type ConsoleApi = ReturnType<typeof consoleApi>;

/** ws:// or wss:// to match the page, same host, the gateway path the API returned, and the ticket. */
export function gatewayUrl(location: Pick<Location, 'protocol' | 'host'>, ticket: ConsoleTicket): string {
  if (!ticket.gateway_path.startsWith('/')) throw new Error('unexpected gateway path');
  const scheme = location.protocol === 'https:' ? 'wss:' : 'ws:';
  return `${scheme}//${location.host}${ticket.gateway_path}?ticket=${encodeURIComponent(ticket.ticket)}`;
}

/** Why the connection ended, in words, from the WebSocket close code. */
export function closeReason(code: number, reason = ''): string {
  if (code === UNAUTHORIZED)
    return 'The console refused the session: the ticket expired or was used, or you may no longer open it.';
  if (code === UNAVAILABLE) return reason ? `The console is unavailable: ${reason}.` : 'The console is unavailable.';
  if (code === 1000 || code === 1005) return 'The session has ended.';
  return `The connection was lost (code ${code}).`;
}

export function consoleError(error: unknown): string {
  if (!(error instanceof ApiError)) return 'The console could not be opened. Please try again.';
  if (typeof error.detail === 'string' && error.detail) return error.detail;
  if (error.status === 503) return 'The console is switched off.';
  if (error.status === 429) return 'Too many console sessions are open; close one and try again.';
  return error.message;
}

/** The parts of a WebSocket the terminal uses, so a fake can stand in for it in tests. */
export interface SocketLike {
  send(data: string): void;
  close(code?: number): void;
  readyState: number;
  onopen: ((ev: unknown) => void) | null;
  onmessage: ((ev: { data: unknown }) => void) | null;
  onclose: ((ev: { code: number; reason: string }) => void) | null;
}

export type ConsoleState = 'connecting' | 'open' | 'closed';
const OPEN = 1;

export interface ConnectionEvents {
  onOutput(text: string): void;
  onState(state: ConsoleState, message?: string): void;
}

/**
 * One terminal session over one socket. Keystrokes are sent only while the socket itself reports open (anything typed
 * before or after is dropped, never queued and replayed into a later session). Only text frames are written to the terminal.
 */
export class ConsoleConnection {
  private socket: SocketLike;
  state: ConsoleState = 'connecting';

  constructor(url: string, events: ConnectionEvents, makeSocket: (url: string) => SocketLike) {
    this.socket = makeSocket(url);
    events.onState('connecting');
    this.socket.onopen = () => {
      this.state = 'open';
      events.onState('open');
    };
    this.socket.onmessage = (ev) => {
      if (typeof ev.data === 'string') events.onOutput(ev.data);
    };
    this.socket.onclose = (ev) => {
      this.state = 'closed';
      events.onState('closed', closeReason(ev.code, ev.reason));
    };
  }

  send(keys: string): boolean {
    if (this.socket.readyState !== OPEN || !keys) return false;
    this.socket.send(keys);
    return true;
  }

  close(): void {
    if (this.state !== 'closed') this.socket.close(1000);
  }
}

/** The real browser socket behind the `SocketLike` interface. */
export function browserSocket(url: string, Impl: typeof WebSocket = WebSocket): SocketLike {
  const ws = new Impl(url);
  const adapter: SocketLike = {
    send: (data) => ws.send(data),
    close: (code) => ws.close(code),
    get readyState() {
      return ws.readyState;
    },
    onopen: null,
    onmessage: null,
    onclose: null,
  };
  ws.onopen = (ev) => adapter.onopen?.(ev);
  ws.onmessage = (ev) => adapter.onmessage?.({ data: ev.data });
  ws.onclose = (ev) => adapter.onclose?.({ code: ev.code, reason: ev.reason });
  return adapter;
}

export type TranscriptChunk = ConsoleHistory['chunks'][number];

/** The API's default page size (app/api/console.py), used to tell the last page apart. */
export const PAGE_SIZE = 500;

/** The most pages one transcript view fetches; a longer transcript is shown cut, and says so. */
export const MAX_TRANSCRIPT_PAGES = 200;

/**
 * A whole transcript, page by page (the API pages by `after_seq`). Stops at the first short or empty page, or after
 * MAX_TRANSCRIPT_PAGES, in which case `complete` is false.
 */
export async function loadTranscript(
  consoles: Pick<ConsoleApi, 'history'>,
  sessionId: string,
): Promise<{ session: ConsoleSession; chunks: TranscriptChunk[]; complete: boolean }> {
  const chunks: TranscriptChunk[] = [];
  let after = 0;
  let session: ConsoleSession | null = null;
  for (let page = 0; page < MAX_TRANSCRIPT_PAGES; page += 1) {
    const reply = await consoles.history(sessionId, after);
    session = reply.session;
    chunks.push(...reply.chunks);
    if (!reply.chunks.length) return { session, chunks, complete: true };
    after = reply.chunks[reply.chunks.length - 1].seq;
    if (reply.chunks.length < PAGE_SIZE) return { session, chunks, complete: true };
  }
  if (session === null) throw new Error('no transcript');
  return { session, chunks, complete: false };
}

/**
 * What the device printed, to replay in a read-only terminal, and what the user typed, as lines. The device usually
 * echoes typed commands into its output, so the two overlap; the input view is there to show exactly what was sent,
 * with `[input hidden]` where a password was typed.
 */
export function splitTranscript(chunks: TranscriptChunk[]): { output: string; input: string[] } {
  const output = chunks
    .filter((c) => c.direction === 'out')
    .map((c) => c.data)
    .join('');
  const typed = chunks
    .filter((c) => c.direction === 'in')
    .map((c) => c.data)
    .join('');
  const input = typed
    .split(/\r\n|\r|\n/)
    .map((line) => line.replace(/[\x00-\x08\x0b-\x1f\x7f]/g, ''))
    .filter((line) => line.trim() !== '');
  return { output, input };
}

/** "12 min", "45 s", or how far a session got when it never opened or is still open. */
export function sessionLength(session: ConsoleSession, now: Date = new Date()): string {
  if (!session.opened_at) return session.status === 'expired' ? 'ticket expired unused' : 'not opened';
  const end = session.closed_at ? new Date(session.closed_at) : now;
  const seconds = Math.max(0, Math.round((end.getTime() - new Date(session.opened_at).getTime()) / 1000));
  const length = seconds < 60 ? `${seconds} s` : `${Math.round(seconds / 60)} min`;
  return session.closed_at ? length : `${length} so far`;
}
