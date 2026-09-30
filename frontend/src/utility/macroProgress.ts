// Real-time progress polling for a long-running macro execution — see
// AbstractModule::multiRawConsoleCommandRun()'s own comment (vendor,
// switcher-core) for the full backend mechanism. A macro that legitimately
// takes over a minute (confirmed live) used to give zero feedback about
// which step it was even on until the whole request finally resolved —
// requested live, since that's indistinguishable from "stuck" without a
// clock. Polling GET /component/macros/execute/progress/{id} while the
// main execute() call is still in flight fills that gap; the main call's
// own response is still the authoritative final result.
import { DataService } from '@/config/dataService/dataService';

export interface MacroProgressCommand {
  command: string;
  output: string;
  success: boolean;
}
export interface MacroProgress {
  commands: MacroProgressCommand[];
  done: number;
  total: number;
}

export function generateExecutionId(): string {
  if (typeof crypto !== 'undefined' && crypto.randomUUID) return crypto.randomUUID();
  // Fallback for a non-secure-context browser (crypto.randomUUID needs
  // https/localhost) — doesn't need to be cryptographically strong, just
  // unique enough not to collide with another concurrent execution.
  return `${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

// Starts polling immediately and keeps going until stop() is called —
// callers stop it themselves once their own execute() call resolves
// (success or error), since that response is always the authoritative
// final state regardless of what the last poll happened to see.
//
// `basePath` defaults to the generic Macros component's progress endpoint;
// pass '/component/onts_registration' for the separate ONT registration
// one instead (own permission gate — unregistered_onts, not
// macros_execute — but the exact same cache-key mechanism server-side).
export function pollMacroProgress(
  executionId: string,
  onUpdate: (p: MacroProgress) => void,
  intervalMs = 1500,
  basePath = '/component/macros'
): { stop: () => void } {
  let stopped = false;
  let timer: ReturnType<typeof setTimeout> | null = null;

  async function tick() {
    if (stopped) return;
    try {
      const { data } = await DataService.get(`${basePath}/execute/progress/${executionId}`);
      if (!stopped && data?.data) onUpdate(data.data as MacroProgress);
    } catch {
      // A single failed poll isn't worth surfacing — the next tick (or
      // the main execute() call's own resolution) will catch up.
    }
    if (!stopped) timer = setTimeout(tick, intervalMs);
  }
  tick();

  return {
    stop() {
      stopped = true;
      if (timer) clearTimeout(timer);
    },
  };
}
