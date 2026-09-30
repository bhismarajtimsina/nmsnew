/**
 * Shared helpers for the analytics/dashboard trend charts (HeartbeatAreaChart.vue
 * consumers). Split out of DashboardPage.vue so the Analytics "Device statuses"
 * page can reuse the same "All" -> "Offline" derivation without duplicating it.
 */
export interface ChartData {
  labels: string[];
  datasets: { label: string; data: number[]; borderColor?: string; backgroundColor?: string; hidden?: boolean }[];
}

/**
 * The device-statuses chart endpoint (`POST /component/analytics/charts/device-statuses`)
 * returns two datasets, "All" (total device count) and "Online" (online subset) —
 * these are NOT complementary/additive partition segments like the ONT chart's
 * genuine LOS/Offline/Online split, so stacking them as-is in HeartbeatAreaChart
 * (which sums datasets cumulatively) would double-count. This derives the real
 * `Offline = All - Online` series client-side so the two returned series become
 * a genuine two-segment partition safe to stack.
 */
export function toOnlineOfflineChart(raw: ChartData | null): ChartData | null {
  if (!raw) return null;
  const all = raw.datasets.find((d) => /all/i.test(d.label))?.data ?? [];
  const online = raw.datasets.find((d) => /online/i.test(d.label))?.data ?? [];
  const offline = all.map((total, i) => Math.max(0, total - (online[i] ?? 0)));
  return {
    labels: raw.labels,
    datasets: [
      { label: 'Offline', borderColor: '#e5484d', backgroundColor: '#e5484d', data: offline },
      { label: 'Online', borderColor: '#0a7318', backgroundColor: '#0a7318', data: online },
    ],
  };
}
