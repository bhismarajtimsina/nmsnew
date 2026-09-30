// Small shared debounce helper — used to collapse a burst of real-time
// WebSocket signals (e.g. a whole fleet of devices' pollers finishing
// close together) into a single refetch instead of one per message.
export function debounce(fn: () => void, waitMs: number): { call: () => void; cancel: () => void } {
  let timer: ReturnType<typeof setTimeout> | null = null;
  return {
    call() {
      if (timer) clearTimeout(timer);
      timer = setTimeout(fn, waitMs);
    },
    cancel() {
      if (timer) clearTimeout(timer);
      timer = null;
    },
  };
}
