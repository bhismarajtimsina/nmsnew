import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it, vi } from 'vitest';
import { ApiError, createApiClient } from '@/api/client';
import {
  ackMatches,
  ackPhrase,
  actionError,
  actionsApi,
  canRun,
  describeTarget,
  followResults,
  isFinal,
  isNoticeFor,
  summarize,
  wakeableSleep,
  tally,
  type ActionSpec,
  type Executed,
  type Prepared,
  type TargetResult,
} from './actions';

const result = (status: TargetResult['status'], error: string | null = null): TargetResult => ({
  target: { interface_id: 'i1' },
  status,
  error,
});
const summary = (over: Partial<Prepared['summary']> = {}): Prepared['summary'] => ({
  action: 'switch.port.set_admin_state',
  title: 'Enable or disable a port',
  count: 1,
  params: { state: 'up' },
  stop_on_failure: false,
  targets: [{ interface_id: 'i1', interface: 'Gi0/1', device_id: 'd1', device: 'core-sw' }],
  ...over,
});
const spec = (key: string, available = true): ActionSpec => ({
  key,
  title: key,
  target_kind: 'interface',
  max_targets: 1,
  params: {},
  available,
});

describe('who is offered an action', () => {
  const all = () => true;
  it('needs the gate and an available catalogue entry', () => {
    expect(canRun([spec('switch.port.set_admin_state')], 'switch.port.set_admin_state', all)).toBe(true);
    expect(canRun([spec('switch.port.set_admin_state', false)], 'switch.port.set_admin_state', all)).toBe(false);
    expect(canRun([spec('switch.reboot')], 'switch.port.set_admin_state', all)).toBe(false);
    expect(
      canRun(
        [spec('switch.port.set_admin_state')],
        'switch.port.set_admin_state',
        (p) => p !== 'dangerous_actions.execute',
      ),
    ).toBe(false);
  });
});

describe('results', () => {
  it('are final only when no target is queued or running', () => {
    expect(isFinal([result('succeeded'), result('failed'), result('refused')])).toBe(true);
    expect(isFinal([result('succeeded'), result('queued')])).toBe(false);
    expect(isFinal([result('running')])).toBe(false);
    expect(isFinal([])).toBe(true);
  });

  it('are counted by status and summarized in one line', () => {
    const rows = [result('succeeded'), result('succeeded'), result('failed')];
    expect(tally(rows)).toEqual({ queued: 0, running: 0, succeeded: 2, failed: 1, refused: 0, skipped: 0 });
    expect(summarize(rows)).toBe('2 succeeded, 1 failed');
    expect(summarize([result('queued'), result('running'), result('failed')])).toBe('Waiting for 2 targets');
    expect(summarize([result('queued')])).toBe('Waiting for 1 target');
    expect(summarize([result('refused')])).toBe('1 refused');
    expect(summarize([result('failed'), result('skipped'), result('skipped')])).toBe('1 failed, 2 skipped');
    expect(isFinal([result('failed'), result('skipped')])).toBe(true);
    expect(summarize([])).toBe('No targets');
  });

  it('show targets by the names the dry run resolved', () => {
    expect(describeTarget(summary().targets[0])).toBe('core-sw Gi0/1');
    expect(describeTarget({ device_id: 'd1', device: 'olt-1', ip: '10.0.0.1' })).toBe('olt-1 (10.0.0.1)');
    expect(describeTarget({ interface_id: 'i9' })).toBe('i9');
  });
});

describe('the typed acknowledgement', () => {
  it('is not asked for a single low-impact request', () => {
    expect(ackPhrase(summary())).toBeNull();
    expect(ackMatches(null, '')).toBe(true);
  });

  it('asks for the port name before shutting a port down', () => {
    const phrase = ackPhrase(summary({ params: { state: 'down' } }));
    expect(phrase).toBe('Gi0/1');
    expect(ackMatches(phrase, 'Gi0/1')).toBe(true);
    expect(ackMatches(phrase, '  Gi0/1 ')).toBe(true);
    expect(ackMatches(phrase, 'gi0/1')).toBe(false);
    expect(ackMatches(phrase, '')).toBe(false);
  });

  it('asks for the device or ONU name for high-impact actions', () => {
    const device = [{ device_id: 'd1', device: 'core-sw', ip: null }];
    expect(ackPhrase(summary({ action: 'switch.reboot', params: {}, targets: device }))).toBe('core-sw');
    expect(
      ackPhrase(
        summary({ action: 'onu.reset', params: {}, targets: [{ device_id: 'd1', onu: '0/1:3', device: 'olt' }] }),
      ),
    ).toBe('0/1:3');
    expect(
      ackPhrase(
        summary({ action: 'onu.deregister', params: {}, targets: [{ device_id: 'd1', onu: '0/1:4', device: 'olt' }] }),
      ),
    ).toBe('0/1:4');
    expect(
      ackPhrase(
        summary({ action: 'onu.disable', params: {}, targets: [{ device_id: 'd1', onu: '0/1:5', device: 'olt' }] }),
      ),
    ).toBe('0/1:5');
    expect(
      ackPhrase(summary({ action: 'switch.reboot', params: {}, targets: [{ device_id: 'd1', device: '  ' }] })),
    ).toBe('confirm');
  });

  it('asks for the count on any bulk request', () => {
    expect(ackPhrase(summary({ action: 'switch.save_config', count: 3, params: {} }))).toBe('3 targets');
    expect(ackPhrase(summary({ action: 'switch.counters.clear', count: 2, params: {} }))).toBe('2 targets');
  });
});

describe('error messages', () => {
  it("prefer the API's own words", () => {
    expect(actionError(new ApiError(503, 'Device actions are switched off'))).toBe('Device actions are switched off');
  });
  it('fall back on the status', () => {
    expect(actionError(new ApiError(409, null))).toMatch(/expired or was already used/);
    expect(actionError(new ApiError(501, null))).toMatch(/not available/);
    expect(actionError(new ApiError(503, null))).toMatch(/Nothing was sent/);
    expect(actionError(new ApiError(403, null))).toBe('You do not have permission to do this');
    expect(actionError(new Error('boom'))).toMatch(/could not be sent/);
  });
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
    api: actionsApi(createApiClient({ baseUrl: 'http://nms.test', store, onUnauthorized: () => {}, fetch: fetchImpl })),
    seen,
  };
}

const CID = '00000000-0000-0000-0000-000000000001';
const executed = (...statuses: TargetResult['status'][]): Executed => ({
  action: 'switch.port.set_admin_state',
  confirmation_id: CID,
  results: statuses.map((s) => result(s)),
});

describe('the API calls', () => {
  it('prepare, execute and protection go to their endpoints with exactly what was confirmed', async () => {
    const { api, seen } = serve([
      { token: 't', expires_at: '2026-01-01T00:00:00Z', summary: summary() },
      executed('queued'),
      { protected: true },
    ]);
    const targets = [{ interface_id: 'i1' }];
    const prepared = await api.prepare('switch.port.set_admin_state', targets, { state: 'down' });
    await api.execute('switch.port.set_admin_state', prepared.token, targets, { state: 'down' });
    expect(await api.setProtection('i1', true)).toBe(true);
    expect(seen).toEqual([
      {
        method: 'POST',
        path: '/api/v1/actions/switch.port.set_admin_state/prepare',
        body: { targets, params: { state: 'down' }, stop_on_failure: false },
      },
      {
        method: 'POST',
        path: '/api/v1/actions/switch.port.set_admin_state/execute',
        body: { token: 't', targets, params: { state: 'down' }, stop_on_failure: false },
      },
      { method: 'PUT', path: '/api/v1/interfaces/i1/protection', body: { protected: true } },
    ]);
  });

  it('stop-on-failure is sent with both the dry run and the execute', async () => {
    const { api, seen } = serve([
      { token: 't', expires_at: '2026-01-01T00:00:00Z', summary: summary() },
      executed('queued'),
    ]);
    const targets = [{ interface_id: 'i1' }, { interface_id: 'i2' }];
    await api.prepare('switch.counters.clear', targets, {}, { stopOnFailure: true });
    await api.execute('switch.counters.clear', 't', targets, {}, { stopOnFailure: true });
    expect(seen.map((s) => (s.body as { stop_on_failure: boolean }).stop_on_failure)).toEqual([true, true]);
  });

  it('removing protection sends false', async () => {
    const { api, seen } = serve([{ protected: false }]);
    expect(await api.setProtection('i1', false)).toBe(false);
    expect(seen[0].body).toEqual({ protected: false });
  });

  it('following stops as soon as every target has finished', async () => {
    const { api, seen } = serve([executed('running'), executed('succeeded')]);
    const sleep = vi.fn(async () => {});
    const updates: Executed[] = [];
    const final = await followResults(api, executed('queued'), { sleep, onUpdate: (u) => updates.push(u) });
    expect(final.results[0].status).toBe('succeeded');
    expect(seen.map((s) => s.path)).toEqual([`/api/v1/actions/results/${CID}`, `/api/v1/actions/results/${CID}`]);
    expect(updates).toHaveLength(2);
    expect(sleep).toHaveBeenCalledTimes(2);
  });

  it('does not poll a request that is already finished', async () => {
    const { api, seen } = serve([]);
    await followResults(api, executed('failed'), { sleep: async () => {} });
    expect(seen).toEqual([]);
  });

  it('gives up after the poll budget and when cancelled', async () => {
    const { api, seen } = serve([executed('running')]);
    const final = await followResults(api, executed('queued'), { sleep: async () => {}, maxPolls: 3 });
    expect(isFinal(final.results)).toBe(false);
    expect(seen).toHaveLength(3);

    const second = serve([executed('running')]);
    let cancelled = false;
    await followResults(second.api, executed('queued'), {
      sleep: async () => {
        cancelled = true;
      },
      isCancelled: () => cancelled,
    });
    expect(second.seen).toEqual([]);
  });
});

describe('the pages', () => {
  const modal = readFileSync(resolve(__dirname, 'ActionConfirmModal.vue'), 'utf8');
  const detail = readFileSync(resolve(__dirname, '../../views/devices/DeviceDetailNewPage.vue'), 'utf8');

  it('the dialog only sends what passed the dry run and the acknowledgement', () => {
    expect(modal).toMatch(
      /const canSend = computed\(\(\) => stage\.value === 'confirm' && ackMatches\(phrase\.value, typed\.value\)\)/,
    );
    expect(modal).toMatch(/:disabled="!canSend"/);
    expect(modal).toMatch(/actions\.execute\(\s*props\.action,\s*prepared\.value\.token,\s*props\.targets/);
    expect(modal).toMatch(/const options = \(\) => \(\{ stopOnFailure: bulk\.value && stopOnFailure\.value \}\)/);
    expect(modal).toMatch(/isNoticeFor\(message\.data, executed\.value\?\.confirmation_id\)\) wakeable\.wake\(\)/);
  });

  it('the interface table offers Enable/Disable only when the action can run, and never Disable on a protected port', () => {
    expect(detail).toMatch(/column\.key === 'actions' && canPortAdmin/);
    expect(detail).toMatch(/:disabled="record\.protected"\s+@click="setAdminState\(record, 'down'\)"/);
    expect(detail).toMatch(/canProtect = computed\(\(\) => can\('interfaces\.manage'\)\)/);
  });
});

describe('waking the poll early', () => {
  function manualTimer() {
    const pending: (() => void)[] = [];
    return {
      timer: () => new Promise<void>((resolve) => pending.push(resolve)),
      fire: () => pending.shift()?.(),
      pending,
    };
  }

  it('a wake ends the current sleep at once', async () => {
    const { timer } = manualTimer();
    const w = wakeableSleep(timer);
    let done = false;
    const sleeping = w.sleep(2000).then(() => (done = true));
    await Promise.resolve();
    expect(done).toBe(false);
    w.wake();
    await sleeping;
    expect(done).toBe(true);
  });

  it('a sleep ends on its own when the timer fires', async () => {
    const { timer, fire } = manualTimer();
    const w = wakeableSleep(timer);
    const sleeping = w.sleep(2000);
    fire();
    await expect(sleeping).resolves.toBeUndefined();
  });

  it('a wake between two sleeps is remembered once', async () => {
    const { timer, pending } = manualTimer();
    const w = wakeableSleep(timer);
    w.wake();
    await w.sleep(2000); // returns at once, no timer started
    expect(pending).toHaveLength(0);
    let done = false;
    w.sleep(2000).then(() => (done = true));
    await Promise.resolve();
    expect(done).toBe(false);
    expect(pending).toHaveLength(1);
  });

  it('a wake after a sleep ended on its own is remembered for the next one', async () => {
    const { timer, fire, pending } = manualTimer();
    const w = wakeableSleep(timer);
    const first = w.sleep(2000);
    fire();
    await first;
    w.wake();
    await w.sleep(2000);
    expect(pending).toHaveLength(0);
  });

  it('a late timer after a wake does nothing', async () => {
    const { timer, fire } = manualTimer();
    const w = wakeableSleep(timer);
    const first = w.sleep(2000);
    w.wake();
    await first;
    fire(); // the first sleep's timer, now stale
    let done = false;
    w.sleep(2000).then(() => (done = true));
    await Promise.resolve();
    await Promise.resolve();
    expect(done).toBe(false);
  });

  it('only a notice for this confirmation counts', () => {
    expect(isNoticeFor({ confirmation_id: CID }, CID)).toBe(true);
    expect(isNoticeFor({ confirmation_id: 'other' }, CID)).toBe(false);
    expect(isNoticeFor({ confirmation_id: CID }, undefined)).toBe(false);
    expect(isNoticeFor(null, CID)).toBe(false);
    expect(isNoticeFor({}, undefined)).toBe(false);
    expect(isNoticeFor(CID, CID)).toBe(false);
  });
});
