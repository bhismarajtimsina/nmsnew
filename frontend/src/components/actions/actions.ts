/**
 * Dangerous device actions (Plans 26 and 38) as the browser sees them. The flow is always the same: prepare (a dry
 * run that lists every target and returns a short-lived, single-use confirmation), show that list to the user, then
 * execute with the confirmation. Execute only queues the work; the results are followed by polling until every
 * target has finished, and an `actions.finished` realtime notice cuts the wait short. Nothing here talks to a device: the API and its worker do, and only when the operator has
 * switched device actions on.
 */
import { ApiError, type ApiClient } from '@/api/client';
import type { components } from '@/api/schema';

type Schemas = components['schemas'];
export type ActionSpec = Schemas['ActionSpecOut'];
export type Prepared = Schemas['ActionPrepared'];
export type Executed = Schemas['ActionExecuted'];
export type TargetResult = Schemas['ActionTargetResult'];
export type Target = Record<string, string>;
export type Params = Record<string, string>;
/** Bulk requests: stop at the first target that does not succeed. Part of what is confirmed, like the targets. */
export interface RunOptions {
  stopOnFailure?: boolean;
}
export const ACTIONS_FINISHED = 'actions.finished';

/** Both are needed for any action; the catalogue entry adds its own permission on top. */
export const GATE = 'dangerous_actions.execute';
export const PORT_ADMIN_ACTION = 'switch.port.set_admin_state';
export const PORT_ADMIN_PERMISSION = 'switches.port.set_admin_state';

/** Actions whose effect cannot be undone from the NMS, or that take subscribers offline for minutes. */
const HIGH_IMPACT = new Set(['switch.reboot', 'onu.reset', 'onu.deregister', 'onu.disable']);

export function actionsApi(client: ApiClient) {
  return {
    async list(): Promise<ActionSpec[]> {
      const { data } = await client.GET('/api/v1/actions');
      return data?.items ?? [];
    },
    async prepare(action: string, targets: Target[], params: Params = {}, options: RunOptions = {}): Promise<Prepared> {
      const { data } = await client.POST('/api/v1/actions/{action}/prepare', {
        params: { path: { action } },
        body: { targets, params, stop_on_failure: options.stopOnFailure ?? false },
      });
      if (!data) throw new ApiError(500, null);
      return data;
    },
    async execute(
      action: string,
      token: string,
      targets: Target[],
      params: Params = {},
      options: RunOptions = {},
    ): Promise<Executed> {
      const { data } = await client.POST('/api/v1/actions/{action}/execute', {
        params: { path: { action } },
        body: { token, targets, params, stop_on_failure: options.stopOnFailure ?? false },
      });
      if (!data) throw new ApiError(500, null);
      return data;
    },
    async results(confirmationId: string): Promise<Executed> {
      const { data } = await client.GET('/api/v1/actions/results/{confirmation_id}', {
        params: { path: { confirmation_id: confirmationId } },
      });
      if (!data) throw new ApiError(500, null);
      return data;
    },
    async setProtection(interfaceId: string, value: boolean): Promise<boolean> {
      const { data } = await client.PUT('/api/v1/interfaces/{interface_id}/protection', {
        params: { path: { interface_id: interfaceId } },
        body: { protected: value },
      });
      if (!data) throw new ApiError(500, null);
      return data.protected;
    },
  };
}

export type ActionsApi = ReturnType<typeof actionsApi>;

/** Whether the user may run this action and this build can run it right now. */
export function canRun(specs: ActionSpec[], key: string, can: (permission: string) => boolean): boolean {
  return can(GATE) && specs.some((spec) => spec.key === key && spec.available);
}

const FINAL = new Set<TargetResult['status']>(['succeeded', 'failed', 'refused', 'skipped']);

export function isFinal(results: TargetResult[]): boolean {
  return results.every((result) => FINAL.has(result.status));
}

export type Tally = Record<TargetResult['status'], number>;

export function tally(results: TargetResult[]): Tally {
  const counts: Tally = { queued: 0, running: 0, succeeded: 0, failed: 0, refused: 0, skipped: 0 };
  for (const result of results) counts[result.status] += 1;
  return counts;
}

/** One line for the whole request: "2 succeeded, 1 failed", or "Waiting for 3 targets" while any is pending. */
export function summarize(results: TargetResult[]): string {
  const counts = tally(results);
  const pending = counts.queued + counts.running;
  if (pending) return `Waiting for ${pending} target${pending === 1 ? '' : 's'}`;
  const parts = (['succeeded', 'failed', 'refused', 'skipped'] as const)
    .filter((s) => counts[s])
    .map((s) => `${counts[s]} ${s}`);
  return parts.join(', ') || 'No targets';
}

/** How a target reads in the confirmation and the results: the names the dry run resolved, never just an id. */
export function describeTarget(target: Record<string, string | null>): string {
  const parts = [target.device, target.interface, target.onu, target.ip ? `(${target.ip})` : null].filter(Boolean);
  return parts.length ? parts.join(' ') : Object.values(target).filter(Boolean).join(' ');
}

/**
 * The phrase a user must type before a high-impact or bulk request is sent, or null when the dry-run list and a
 * click are enough. Typing a name (or the count, for bulk) proves the user read which targets they picked.
 */
export function ackPhrase(summary: Prepared['summary']): string | null {
  const shutsPort = summary.action === PORT_ADMIN_ACTION && summary.params.state === 'down';
  if (summary.count > 1) return `${summary.count} targets`;
  if (!HIGH_IMPACT.has(summary.action) && !shutsPort) return null;
  const only: Record<string, string | null | undefined> = summary.targets[0] ?? {};
  return (only.interface ?? only.onu ?? only.device ?? '').trim() || 'confirm';
}

export function ackMatches(phrase: string | null, typed: string): boolean {
  return phrase === null || typed.trim() === phrase;
}

/** The API's reason in words an operator can act on; the status alone decides when the API gives none. */
export function actionError(error: unknown): string {
  if (!(error instanceof ApiError)) return 'The request could not be sent. Please try again.';
  if (typeof error.detail === 'string' && error.detail) return error.detail;
  switch (error.status) {
    case 409:
      return 'The confirmation expired or was already used. Review the targets again.';
    case 501:
      return 'This action is not available in this build yet.';
    case 503:
      return 'Device actions are switched off, or cannot be queued right now. Nothing was sent.';
    default:
      return error.message;
  }
}

export interface PollOptions {
  /** Waits between polls; injected so tests do not sleep. */
  sleep?: (ms: number) => Promise<void>;
  intervalMs?: number;
  /** Stop asking after this many polls; the last results are returned and the caller shows they are unfinished. */
  maxPolls?: number;
  onUpdate?: (executed: Executed) => void;
  isCancelled?: () => boolean;
}

const wait = (ms: number) => new Promise<void>((resolve) => setTimeout(resolve, ms));

/**
 * A sleep that `wake()` ends early: the dialog passes it to followResults and wakes it when the realtime notice for
 * its own confirmation arrives, so results show at once instead of on the next poll. A wake with nobody asleep is
 * remembered, so a notice that lands between two polls is not lost.
 */
export function wakeableSleep(timer: (ms: number) => Promise<void> = wait) {
  let wakeUp: (() => void) | null = null;
  let early = false;
  return {
    sleep(ms: number): Promise<void> {
      if (early) {
        early = false;
        return Promise.resolve();
      }
      return new Promise<void>((resolve) => {
        // Each sleep ends through its own `finish`, so a timer left over from an earlier, woken sleep cannot end this one.
        const finish = () => {
          if (wakeUp === finish) wakeUp = null;
          resolve();
        };
        wakeUp = finish;
        timer(ms).then(finish);
      });
    },
    wake(): void {
      if (wakeUp) wakeUp();
      else early = true;
    },
  };
}

/** Whether a realtime notice is about this request. The notice carries only the id; the results come from the API. */
export function isNoticeFor(data: unknown, confirmationId: string | undefined): boolean {
  return (
    confirmationId !== undefined &&
    typeof data === 'object' &&
    data !== null &&
    (data as { confirmation_id?: unknown }).confirmation_id === confirmationId
  );
}

/** Follow a queued request until every target has finished, the poll budget runs out, or the caller cancels. */
export async function followResults(api: ActionsApi, first: Executed, options: PollOptions = {}): Promise<Executed> {
  const { sleep = wait, intervalMs = 2000, maxPolls = 150, onUpdate, isCancelled } = options;
  let current = first;
  for (let poll = 0; poll < maxPolls && !isFinal(current.results); poll += 1) {
    await sleep(intervalMs);
    if (isCancelled?.()) break;
    current = await api.results(current.confirmation_id);
    onUpdate?.(current);
  }
  return current;
}
