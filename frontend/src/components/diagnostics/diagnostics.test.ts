import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it, vi } from 'vitest';
import { ApiError, createApiClient } from '@/api/client';
import {
  describePing,
  diagnosticError,
  diagnosticsApi,
  followPing,
  isDone,
  isNoticeFor,
  type Diagnostic,
} from './diagnostics';

const RID = '00000000-0000-0000-0000-0000000000aa';
const queued: Diagnostic = { request_id: RID, device_id: 'd1', status: 'queued', result: null, error: null };
const done = (over: Partial<Diagnostic> = {}): Diagnostic => ({
  ...queued,
  status: 'succeeded',
  result: { sent: 4, received: 3, loss_percent: 25, min_ms: 1, avg_ms: 2, max_ms: 3 },
  ...over,
});

function serve(replies: unknown[]) {
  const seen: { method: string; path: string; body: unknown }[] = [];
  let next = 0;
  const fetchImpl = vi.fn(async (request: Request) => {
    const text = request.method === 'GET' ? '' : await request.clone().text();
    seen.push({ method: request.method, path: new URL(request.url).pathname, body: text ? JSON.parse(text) : null });
    const reply = replies[Math.min(next++, replies.length - 1)];
    return new Response(JSON.stringify(reply), { status: 200, headers: { 'Content-Type': 'application/json' } });
  }) as unknown as typeof fetch;
  const store = { get: () => 'token', remove: () => {} };
  return {
    api: diagnosticsApi(
      createApiClient({ baseUrl: 'http://nms.test', store, onUnauthorized: () => {}, fetch: fetchImpl }),
    ),
    seen,
  };
}

describe('the ping request', () => {
  it('names the device and a packet count, never an address', async () => {
    const { api, seen } = serve([queued]);
    await api.ping('d1');
    expect(seen).toEqual([{ method: 'POST', path: '/api/v1/diagnostics/ping', body: { device_id: 'd1', count: 4 } }]);
  });

  it('is followed until it has a result, then stops', async () => {
    const { api, seen } = serve([queued, done()]);
    const sleep = vi.fn(async () => {});
    const final = await followPing(api, queued, { sleep });
    expect(final.status).toBe('succeeded');
    expect(seen.map((s) => s.path)).toEqual([`/api/v1/diagnostics/${RID}`, `/api/v1/diagnostics/${RID}`]);
    expect(sleep).toHaveBeenCalledTimes(2);
  });

  it('is not polled once done, and stops on cancel or after the budget', async () => {
    const first = serve([]);
    await followPing(first.api, done(), { sleep: async () => {} });
    expect(first.seen).toEqual([]);

    const second = serve([queued]);
    const final = await followPing(second.api, queued, { sleep: async () => {}, maxPolls: 2 });
    expect(isDone(final)).toBe(false);
    expect(second.seen).toHaveLength(2);

    const third = serve([queued]);
    await followPing(third.api, queued, { sleep: async () => {}, isCancelled: () => true });
    expect(third.seen).toEqual([]);
  });
});

describe('what the user sees', () => {
  it('describes a result, a refusal and a wait', () => {
    expect(describePing(done())).toBe('3/4 replies, 25% loss, avg 2 ms (min 1, max 3)');
    expect(
      describePing(
        done({ result: { sent: 4, received: 0, loss_percent: 100, min_ms: null, avg_ms: null, max_ms: null } }),
      ),
    ).toBe('0/4 replies, 100% loss');
    expect(describePing(done({ status: 'refused', result: null, error: 'switched off' }))).toBe('switched off');
    expect(describePing(done({ status: 'failed', result: null, error: null }))).toBe('Ping failed');
    expect(describePing(queued)).toMatch(/Waiting/);
    expect(isDone(queued)).toBe(false);
    expect(isDone(done({ status: 'refused' }))).toBe(true);
  });

  it("shows the API's reason, with fallbacks", () => {
    expect(diagnosticError(new ApiError(429, 'too many diagnostics for you'))).toBe('too many diagnostics for you');
    expect(diagnosticError(new ApiError(429, null))).toMatch(/Too many pings/);
    expect(diagnosticError(new ApiError(503, null))).toMatch(/Nothing was sent/);
    expect(diagnosticError(new ApiError(404, null))).toBe('Not found');
    expect(diagnosticError(new Error('x'))).toMatch(/could not be sent/);
  });

  it('only a notice for this request counts', () => {
    expect(isNoticeFor({ request_id: RID }, RID)).toBe(true);
    expect(isNoticeFor({ request_id: 'other' }, RID)).toBe(false);
    expect(isNoticeFor({}, undefined)).toBe(false);
    expect(isNoticeFor(null, RID)).toBe(false);
  });

  it('the device page offers Ping only to holders of the permission and wakes on its own notice', () => {
    const page = readFileSync(resolve(__dirname, '../../views/devices/DeviceDetailNewPage.vue'), 'utf8');
    expect(page).toMatch(/canPing = computed\(\(\) => can\(PING_PERMISSION\)\)/);
    expect(page).toMatch(/v-if="canPing && detail"/);
    expect(page).toMatch(/isNoticeFor\(message\.data, queued\.request_id\)\) wake\.wake\(\)/);
  });
});
