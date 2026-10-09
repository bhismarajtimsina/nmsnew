/**
 * On-demand diagnostics (Plan 38): ask the API to ping a device's management address and follow the result. The API
 * only queues the request; a worker sends the packets. The page names a device, never an address. A
 * `diagnostics.finished` realtime notice wakes the wait early; polling covers a missed notice.
 */
import { ApiError, type ApiClient } from '@/api/client';
import type { components } from '@/api/schema';
import { wakeableSleep } from '@/components/actions/actions';

export type Diagnostic = components['schemas']['DiagnosticOut'];
export type PingSummary = components['schemas']['PingSummary'];

export const PING_PERMISSION = 'diagnostics.icmp_ping';
export const DIAGNOSTICS_FINISHED = 'diagnostics.finished';
export const MAX_COUNT = 5;

export function diagnosticsApi(client: ApiClient) {
  return {
    async ping(deviceId: string, count = 4): Promise<Diagnostic> {
      const { data } = await client.POST('/api/v1/diagnostics/ping', { body: { device_id: deviceId, count } });
      if (!data) throw new ApiError(500, null);
      return data;
    },
    async get(requestId: string): Promise<Diagnostic> {
      const { data } = await client.GET('/api/v1/diagnostics/{request_id}', {
        params: { path: { request_id: requestId } },
      });
      if (!data) throw new ApiError(500, null);
      return data;
    },
  };
}

export type DiagnosticsApi = ReturnType<typeof diagnosticsApi>;

export function isDone(diagnostic: Diagnostic): boolean {
  return diagnostic.status !== 'queued';
}

/** One line for the result: "3/4 replies, 25% loss, avg 2.1 ms", or why there is none. */
export function describePing(diagnostic: Diagnostic): string {
  if (diagnostic.status === 'queued') return 'Waiting for the worker…';
  if (diagnostic.status !== 'succeeded' || !diagnostic.result) return diagnostic.error ?? `Ping ${diagnostic.status}`;
  const r = diagnostic.result;
  const avg = r.avg_ms === null ? '' : `, avg ${r.avg_ms} ms (min ${r.min_ms}, max ${r.max_ms})`;
  return `${r.received}/${r.sent} replies, ${r.loss_percent}% loss${avg}`;
}

export function diagnosticError(error: unknown): string {
  if (!(error instanceof ApiError)) return 'The request could not be sent. Please try again.';
  if (typeof error.detail === 'string' && error.detail) return error.detail;
  if (error.status === 429) return 'Too many pings; try again in a minute.';
  if (error.status === 503) return 'Diagnostics are switched off or unavailable. Nothing was sent.';
  return error.message;
}

export function isNoticeFor(data: unknown, requestId: string | undefined): boolean {
  return (
    requestId !== undefined &&
    typeof data === 'object' &&
    data !== null &&
    (data as { request_id?: unknown }).request_id === requestId
  );
}

export interface FollowOptions {
  sleep?: (ms: number) => Promise<void>;
  intervalMs?: number;
  maxPolls?: number;
  isCancelled?: () => boolean;
}

/** Follow a queued ping until it has a result, the poll budget runs out (the record expires anyway), or cancel. */
export async function followPing(
  api: DiagnosticsApi,
  first: Diagnostic,
  options: FollowOptions = {},
): Promise<Diagnostic> {
  const { sleep = wakeableSleep().sleep, intervalMs = 1500, maxPolls = 40, isCancelled } = options;
  let current = first;
  for (let poll = 0; poll < maxPolls && !isDone(current); poll += 1) {
    await sleep(intervalMs);
    if (isCancelled?.()) break;
    current = await api.get(current.request_id);
  }
  return current;
}
