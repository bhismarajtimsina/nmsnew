// Mirrors the real device dashboard's "deviceCalling" helper: every OLT tab
// that calls a device-facing endpoint (system, resources, pon-ports, onts,
// physical interfaces, ...) reports that response's `meta` block here under
// a name, so the sidebar "Device calling" card can show, for every module,
// whether its last read came from cache or live from the device (and how
// long ago), or errored. One instance is created per device-detail page
// load and passed down to every tab that fetches OLT data.
import { reactive } from 'vue';

export interface CallMeta {
  time?: string;
  source?: string;
  from_cache?: boolean;
  error?: { message?: string } | null;
}

export function useDeviceCalling() {
  const meta = reactive<Record<string, CallMeta>>({});
  function setMeta(entries: Record<string, CallMeta> | undefined | null) {
    if (!entries) return;
    Object.assign(meta, entries);
  }
  return { meta, setMeta };
}
export type DeviceCalling = ReturnType<typeof useDeviceCalling>;
