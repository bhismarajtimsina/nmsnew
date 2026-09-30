// Shared colour-threshold helpers for OLT/ONU optical readings — copied
// exactly (including the thresholds) from the compiled OntsTree/OntInfo
// components so every place that shows a signal/temperature reading uses
// the same scale the original app does.
export function signalColor(v: number): string {
  if (v < -32) return '#7a0000';
  if (v < -30) return '#b32400';
  if (v < -28) return '#b37100';
  if (v < -23) return '#247000';
  if (v < -22) return '#1a4d01';
  return '#174900';
}
export function tempColor(t: number): string {
  if (t < 20) return '#063400';
  if (t < 30) return '#004c17';
  if (t < 40) return '#1a5c03';
  if (t < 50) return '#5e6501';
  if (t < 60) return '#a39300';
  if (t < 70) return '#6f1f00';
  if (t < 80) return '#8b0202';
  return '#a50000';
}
export function loadColor(pct: number): string {
  if (pct < 20) return '#063400';
  if (pct < 60) return '#1a5c03';
  if (pct < 70) return '#5e6501';
  if (pct < 80) return '#a39300';
  if (pct < 95) return '#8b0202';
  return '#a50000';
}
export function portStatColor(stat: { count: number; offline: number }): string {
  if (stat.offline === 0) return '#0E4D00';
  if (stat.offline === stat.count) return '#8F0000';
  return '#b37100';
}
