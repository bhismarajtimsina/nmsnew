import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it, vi } from 'vitest';
import { ApiError, createApiClient } from '@/api/client';
import {
  browserSocket,
  ConsoleConnection,
  closeReason,
  consoleApi,
  consoleError,
  gatewayUrl,
  UNAUTHORIZED,
  UNAVAILABLE,
  type ConsoleTicket,
  type SocketLike,
} from './console';

const TICKET: ConsoleTicket = {
  session_id: 's1',
  ticket: 'abc+/=',
  expires_at: '2026-01-01T00:00:00Z',
  gateway_path: '/console/ws',
};

class FakeSocket implements SocketLike {
  sent: string[] = [];
  closedWith: number | undefined;
  readyState = 0;
  onopen: SocketLike['onopen'] = null;
  onmessage: SocketLike['onmessage'] = null;
  onclose: SocketLike['onclose'] = null;
  constructor(readonly url: string) {}
  send(data: string) {
    this.sent.push(data);
  }
  close(code?: number) {
    this.closedWith = code;
  }
  open() {
    this.readyState = 1;
    this.onopen?.({});
  }
  serverClose(code: number, reason = '') {
    this.readyState = 3;
    this.onclose?.({ code, reason });
  }
}

function connect() {
  const output: string[] = [];
  const states: [string, string | undefined][] = [];
  let socket!: FakeSocket;
  const connection = new ConsoleConnection(
    'ws://nms.test/console/ws?ticket=t',
    { onOutput: (t) => output.push(t), onState: (s, m) => states.push([s, m]) },
    (url) => (socket = new FakeSocket(url)),
  );
  return { connection, socket, output, states };
}

describe('the gateway address', () => {
  it('follows the page scheme and host, and escapes the ticket', () => {
    expect(gatewayUrl({ protocol: 'https:', host: 'nms.example:8443' }, TICKET)).toBe(
      'wss://nms.example:8443/console/ws?ticket=abc%2B%2F%3D',
    );
    expect(gatewayUrl({ protocol: 'http:', host: 'nms.test' }, TICKET)).toBe(
      'ws://nms.test/console/ws?ticket=abc%2B%2F%3D',
    );
  });

  it('refuses a gateway path that is not on this origin', () => {
    expect(() =>
      gatewayUrl({ protocol: 'https:', host: 'nms' }, { ...TICKET, gateway_path: '//evil.example/ws' }),
    ).not.toThrow();
    expect(gatewayUrl({ protocol: 'https:', host: 'nms' }, { ...TICKET, gateway_path: '//evil.example/ws' })).toMatch(
      /^wss:\/\/nms\/\//,
    );
    expect(() =>
      gatewayUrl({ protocol: 'https:', host: 'nms' }, { ...TICKET, gateway_path: 'wss://evil.example/ws' }),
    ).toThrow();
  });
});

describe('a console connection', () => {
  it('sends keystrokes only while open, and writes only text output', () => {
    const { connection, socket, output, states } = connect();
    expect(connection.send('early')).toBe(false);
    socket.open();
    expect(connection.send('show ver\r')).toBe(true);
    expect(connection.send('')).toBe(false);
    socket.onmessage?.({ data: 'sw# ' });
    socket.onmessage?.({ data: new ArrayBuffer(4) });
    expect(socket.sent).toEqual(['show ver\r']);
    expect(output).toEqual(['sw# ']);
    expect(states.map(([s]) => s)).toEqual(['connecting', 'open']);
  });

  it('drops keystrokes after the session ends and says why it ended', () => {
    const { connection, socket, states } = connect();
    socket.open();
    socket.serverClose(UNAVAILABLE, 'console is switched off');
    expect(connection.send('x')).toBe(false);
    expect(socket.sent).toEqual([]);
    expect(states.at(-1)).toEqual(['closed', 'The console is unavailable: console is switched off.']);
    connection.close();
    expect(socket.closedWith).toBeUndefined(); // already closed: nothing more to do
  });

  it('closing from the page ends the socket normally', () => {
    const { connection, socket } = connect();
    socket.open();
    connection.close();
    expect(socket.closedWith).toBe(1000);
  });

  it('is not open until the socket itself is', () => {
    const { connection, socket } = connect();
    socket.onopen?.({}); // state says open, but readyState is still CONNECTING
    expect(connection.send('x')).toBe(false);
  });
});

describe('messages', () => {
  it('explain each way a session ends', () => {
    expect(closeReason(UNAUTHORIZED)).toMatch(/ticket expired or was used/);
    expect(closeReason(UNAVAILABLE)).toBe('The console is unavailable.');
    expect(closeReason(1000)).toBe('The session has ended.');
    expect(closeReason(1006)).toBe('The connection was lost (code 1006).');
  });

  it("prefer the API's reason when a session cannot be requested", () => {
    expect(consoleError(new ApiError(409, 'automatic login needs a CLI username and password'))).toMatch(
      /CLI username/,
    );
    expect(consoleError(new ApiError(503, null))).toBe('The console is switched off.');
    expect(consoleError(new ApiError(429, null))).toMatch(/Too many/);
    expect(consoleError(new Error('x'))).toMatch(/could not be opened/);
  });
});

describe('the API calls', () => {
  it('request a ticket for a device, with automatic login only when asked', async () => {
    const seen: unknown[] = [];
    const fetchImpl = vi.fn(async (request: Request) => {
      seen.push(JSON.parse(await request.clone().text()));
      return new Response(JSON.stringify(TICKET), { status: 201, headers: { 'Content-Type': 'application/json' } });
    }) as unknown as typeof fetch;
    const store = { get: () => 'token', remove: () => {} };
    const consoles = consoleApi(
      createApiClient({ baseUrl: 'http://nms.test', store, onUnauthorized: () => {}, fetch: fetchImpl }),
    );
    await consoles.request('d1');
    await consoles.request('d1', true);
    expect(seen).toEqual([
      { device_id: 'd1', auto_auth: false },
      { device_id: 'd1', auto_auth: true },
    ]);
  });
});

describe('the pages', () => {
  const terminal = readFileSync(resolve(__dirname, 'ConsoleTerminal.vue'), 'utf8');
  const detail = readFileSync(resolve(__dirname, '../../views/devices/DeviceDetailNewPage.vue'), 'utf8');

  it('the terminal shows the recorded-session banner and asks for a ticket only on Connect', () => {
    expect(terminal).toMatch(/This session is recorded with your name/);
    expect(terminal).toMatch(/@click="connect"/);
    expect(terminal.match(/consoles\.request\(/g)).toHaveLength(1);
    expect(terminal).toMatch(/v-if="canAutoAuth"/);
  });

  it('the device page offers the console only to holders of console.open', () => {
    expect(detail).toMatch(/canConsole = computed\(\(\) => can\(OPEN_PERMISSION\)\)/);
    expect(detail).toMatch(/v-if="canConsole && detail"/);
  });
});

describe('the browser socket adapter', () => {
  it('passes data, state and close codes through', () => {
    const made: FakeWs[] = [];
    class FakeWs {
      readyState = 0;
      sent: string[] = [];
      closed?: number;
      onopen: ((ev: unknown) => void) | null = null;
      onmessage: ((ev: { data: unknown }) => void) | null = null;
      onclose: ((ev: { code: number; reason: string }) => void) | null = null;
      constructor(readonly url: string) {
        made.push(this);
      }
      send(d: string) {
        this.sent.push(d);
      }
      close(c?: number) {
        this.closed = c;
      }
    }
    const events: string[] = [];
    const socket = browserSocket('ws://nms.test/console/ws?ticket=t', FakeWs as unknown as typeof WebSocket);
    socket.onopen = () => events.push('open');
    socket.onmessage = (ev) => events.push(`data:${ev.data}`);
    socket.onclose = (ev) => events.push(`close:${ev.code}:${ev.reason}`);
    const ws = made[0];
    expect(ws.url).toBe('ws://nms.test/console/ws?ticket=t');
    ws.readyState = 1;
    ws.onopen?.({});
    ws.onmessage?.({ data: 'sw# ' });
    expect(socket.readyState).toBe(1);
    socket.send('x');
    socket.close(1000);
    ws.onclose?.({ code: 4401, reason: 'unauthorized' });
    expect(ws.sent).toEqual(['x']);
    expect(ws.closed).toBe(1000);
    expect(events).toEqual(['open', 'data:sw# ', 'close:4401:unauthorized']);
  });
});
