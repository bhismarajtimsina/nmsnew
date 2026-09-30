// Shared bit-rate formatter — used by the live-traffic view and the
// traffic-history chart so both show the same familiar ISP-style units
// (Kb/s, Mb/s, Gb/s...) instead of raw numbers or the fuller "Kbits/s"
// wording.
const BIT_UNITS = ['b/s', 'Kb/s', 'Mb/s', 'Gb/s', 'Tb/s'];

export function formatBitsPerSecond(bitsPerSec: number, decimals = 2): string {
  if (!bitsPerSec || !isFinite(bitsPerSec)) return '0 b/s';
  const sign = bitsPerSec < 0 ? '-' : '';
  const abs = Math.abs(bitsPerSec);
  const base = 1024;
  const idx = Math.min(BIT_UNITS.length - 1, Math.max(0, Math.floor(Math.log(abs) / Math.log(base))));
  return `${sign}${parseFloat((abs / Math.pow(base, idx)).toFixed(decimals))} ${BIT_UNITS[idx]}`;
}

// For an axis with several ticks (or a live-updating one), formatting
// each value's unit independently makes neighbouring ticks jump between
// Kb/s, Mb/s, Gb/s... as the value crosses each 1024 boundary — most
// visible once auto-refresh keeps nudging the data. This instead picks
// ONE unit for the whole series (sized to its largest magnitude) so every
// tick/tooltip in that chart stays in the same unit.
export function pickBitsUnit(maxAbsBitsPerSec: number): { divisor: number; suffix: string } {
  if (!maxAbsBitsPerSec || !isFinite(maxAbsBitsPerSec)) return { divisor: 1, suffix: 'b/s' };
  const base = 1024;
  const idx = Math.min(BIT_UNITS.length - 1, Math.max(0, Math.floor(Math.log(maxAbsBitsPerSec) / Math.log(base))));
  return { divisor: Math.pow(base, idx), suffix: BIT_UNITS[idx] };
}
export function formatWithUnit(v: number, unit: { divisor: number; suffix: string }, decimals = 2): string {
  if (!isFinite(v)) return `0 ${unit.suffix}`;
  const sign = v < 0 ? '-' : '';
  return `${sign}${parseFloat((Math.abs(v) / unit.divisor).toFixed(decimals))} ${unit.suffix}`;
}

// For cumulative interface counters (SNMP-style ifInOctets/ifOutOctets —
// a running byte total, not a rate) — e.g. the ONT/interface "Counters"
// cards, which otherwise showed the raw byte count with no unit at all.
// This is deliberately a byte-size formatter (B/KB/MB/GB/TB), NOT a
// bitrate one — these fields are totals, not per-second speeds, so
// Mb/s/Gb/s-style units would be the wrong dimension for them.
const BYTE_UNITS = ['B', 'KB', 'MB', 'GB', 'TB'];
export function formatBytes(bytes: number | string | null | undefined, decimals = 2): string {
  const n = Number(bytes || 0);
  if (!n || !isFinite(n)) return '0 B';
  const base = 1024;
  const idx = Math.min(BYTE_UNITS.length - 1, Math.max(0, Math.floor(Math.log(Math.abs(n)) / Math.log(base))));
  return `${parseFloat((n / Math.pow(base, idx)).toFixed(decimals))} ${BYTE_UNITS[idx]}`;
}
