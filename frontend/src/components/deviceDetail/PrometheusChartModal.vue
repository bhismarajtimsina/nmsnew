<!--
  Shared Prometheus history-chart modal — every "chart" icon across the
  device/interface pages (optical RX/TX/OLT-RX, temperature, voltage,
  CPU/RAM, traffic counters) opens this against a different
  `/component/prometheus_wrapper/chart-*-series` endpoint. All of them
  share the exact same request shape — device_id[/interface_id], optional
  start/end, and a Prometheus `step` — and the exact same {labels,
  datasets:[{label, borderColor, backgroundColor, fill, data}]} response
  shape (Chart.js-style, converted to ApexCharts colors/series here), so
  the range picker below works generically for every chart this modal
  opens, not just traffic. One correction to the endpoints' own OpenAPI
  docs, found by testing live: they document start/end as "Y-m-d H:i:s"
  strings, but the real controller (seriesRequest() in
  PrometheusWrapper/Controllers/Controller.php) does raw arithmetic on
  them expecting Unix timestamps — a date string 500s. Sent as Unix
  seconds here instead, confirmed working.

  Range spans the full spectrum from 5 minutes up to 30 days, each preset
  paired with a step sized for it (finer for short windows, coarser for
  long ones) — the short-window steps are deliberately not sub-minute:
  Prometheus' rate() needs at least two samples inside the window to
  produce a point, and this system's own interface_counters poller only
  writes a new sample every 3 minutes, so a step much finer than that
  just comes back empty (confirmed live: 10s/30s/1m steps under a 30-min
  window returned zero points; 2m-5m steps reliably don't). "Auto-refresh"
  (only meaningful on a preset, not a fixed custom range) re-fetches on
  an interval scaled to the window itself, so the chart keeps sliding
  forward with fresh points instead of going stale — the closer this gets
  to acting like the live-traffic view the shorter the selected window is.

  Kept as-is from the original: the traffic-counters endpoint's "Out
  bytes" series arrives pre-negated, so it plots as a mirrored below-zero
  line against "In bytes" above it — the same look the live-traffic chart
  uses. Values are run through a small formatter so axis ticks and
  tooltips round to a sensible precision instead of raw floats like
  "0.000000000"; the traffic-counter chart specifically is shown in the
  same Kb/s-Gb/s bitrate units as the live-traffic view (its own PromQL
  query already converts octets/sec to bits/sec server-side, so no
  client-side rescale is needed here — only the unit formatting). That
  unit is picked ONCE per chart, sized to the data's largest magnitude,
  rather than re-picked per value — auto-picking per value made
  neighbouring axis ticks (and, worse, successive auto-refreshes) jump
  between Kb/s/Mb/s/Gb/s as the data crossed each 1024 boundary, which
  read as the values themselves being wrong rather than just relabelled.
-->
<script setup lang="ts">
import { ref, computed, reactive, watch, onBeforeUnmount } from 'vue';
import dayjs, { Dayjs } from 'dayjs';
import { DataService } from '@/config/dataService/dataService';
import { pickBitsUnit, formatWithUnit } from '@/utility/formatters';

const visible = ref(false);
const loading = ref(false);
const title = ref('');
const labels = ref<string[]>([]);
const datasets = ref<{ label: string; borderColor: string; data: number[] }[]>([]);
const unit = ref<'bitrate' | null>(null);

// The request this popup was opened with, kept around so changing the
// time range (or auto-refreshing) can re-fetch without needing the
// original trigger again.
const request = reactive<{ uri: string; params: Record<string, any> }>({ uri: '', params: {} });

type RangeKey = '5m' | '15m' | '30m' | '1h' | '6h' | '24h' | '3d' | '7d' | '30d' | 'custom';
const RANGE_OPTIONS: { key: RangeKey; label: string; minutes?: number; step: string }[] = [
  { key: '5m', label: '5 min', minutes: 5, step: '2m' },
  { key: '15m', label: '15 min', minutes: 15, step: '3m' },
  { key: '30m', label: '30 min', minutes: 30, step: '5m' },
  { key: '1h', label: '1 hour', minutes: 60, step: '5m' },
  { key: '6h', label: '6 hours', minutes: 60 * 6, step: '5m' },
  { key: '24h', label: '24 hours', minutes: 60 * 24, step: '30m' },
  { key: '3d', label: '3 days', minutes: 60 * 24 * 3, step: '2h' },
  { key: '7d', label: '7 days', minutes: 60 * 24 * 7, step: '6h' },
  { key: '30d', label: '30 days', minutes: 60 * 24 * 30, step: '1d' },
  { key: 'custom', label: 'Custom range', step: '1h' },
];
const rangeKey = ref<RangeKey>('24h');
const customRange = ref<[Dayjs, Dayjs] | null>(null);
const autoRefresh = ref(false);
let refreshTimer: ReturnType<typeof setInterval> | null = null;
// vue3-apexcharts doesn't reliably re-apply function-valued options (like
// the yaxis/tooltip formatters below) on every reactive update — switching
// range or auto-refreshing could leave the old chart instance running with
// whatever formatter it happened to mount with, which then shows raw
// unformatted numbers instead of "Mb/s"/"Gb/s". Forcing a fresh
// `<apexchart>` instance per fetch (via :key) sidesteps that entirely.
const chartKey = ref(0);

async function fetchSeries() {
  loading.value = true;
  const range = RANGE_OPTIONS.find((r) => r.key === rangeKey.value)!;
  const params: Record<string, any> = { ...request.params, step: range.step };
  if (range.key === 'custom' && customRange.value) {
    params.start = customRange.value[0].unix();
    params.end = customRange.value[1].unix();
  } else if (range.minutes) {
    params.start = dayjs().subtract(range.minutes, 'minute').unix();
    params.end = dayjs().unix();
  }
  try {
    const { data } = await DataService.post(request.uri, params);
    labels.value = data.data?.labels || [];
    datasets.value = data.data?.datasets || [];
    chartKey.value++;
  } finally {
    loading.value = false;
  }
}

function stopAutoRefresh() {
  if (refreshTimer) {
    clearInterval(refreshTimer);
    refreshTimer = null;
  }
}
function startAutoRefresh() {
  stopAutoRefresh();
  if (!autoRefresh.value || !visible.value) return;
  const range = RANGE_OPTIONS.find((r) => r.key === rangeKey.value)!;
  if (!range.minutes) return; // custom range: fixed window, nothing to slide forward
  // Aim for ~30 fresh points across the window, clamped to a sane cadence.
  const intervalSec = Math.min(60, Math.max(5, Math.round((range.minutes * 60) / 30)));
  refreshTimer = setInterval(fetchSeries, intervalSec * 1000);
}
watch(autoRefresh, startAutoRefresh);
onBeforeUnmount(stopAutoRefresh);

async function open(chartTitle: string, uri: string, params: Record<string, any>, options: { unit?: 'bitrate' } = {}) {
  title.value = chartTitle;
  unit.value = options.unit || null;
  request.uri = uri;
  request.params = params;
  rangeKey.value = '24h';
  customRange.value = null;
  autoRefresh.value = false;
  labels.value = [];
  datasets.value = [];
  visible.value = true;
  await fetchSeries();
}
defineExpose({ open });

function close() {
  visible.value = false;
  stopAutoRefresh();
}
function onRangeChange() {
  if (rangeKey.value !== 'custom') {
    fetchSeries();
    startAutoRefresh();
  } else {
    stopAutoRefresh();
  }
}
function onCustomRangeChange() {
  stopAutoRefresh();
  if (customRange.value) fetchSeries();
}

// Rounds to a precision sensible for the value's own magnitude, and
// collapses float noise around zero to a plain "0" instead of something
// like "0.000000000".
function formatValue(v: number) {
  if (!isFinite(v)) return '0';
  const abs = Math.abs(v);
  if (abs < 0.005) return '0';
  if (abs >= 1000) return v.toFixed(0);
  if (abs >= 1) return String(parseFloat(v.toFixed(2)));
  return String(parseFloat(v.toFixed(3)));
}
// One fixed unit for the whole chart (sized to its largest magnitude),
// not one auto-picked per value — otherwise neighbouring axis ticks (or
// successive auto-refreshes) jump between Kb/s, Mb/s, Gb/s as the data
// crosses each 1024 boundary, which reads as "wrong" values rather than
// just a different unit.
const bitsUnit = computed(() => {
  if (unit.value !== 'bitrate') return null;
  let maxAbs = 0;
  datasets.value.forEach((d) => d.data.forEach((v) => (maxAbs = Math.max(maxAbs, Math.abs(v)))));
  return pickBitsUnit(maxAbs);
});
const formatTick = (v: number) => (bitsUnit.value ? formatWithUnit(v, bitsUnit.value, 1) : formatValue(v));

// The traffic-counters endpoint's own dataset labels are "In bytes"/"Out
// bytes" — a leftover name from before its PromQL query started
// converting to bits/sec server-side. Left alone, the legend/tooltip
// would say "bytes" right next to axis ticks reading "Mb/s"/"Gb/s",
// which reads as contradictory. Strips that stale unit word only when
// this chart is flagged as a bitrate one; every other chart's own label
// (Optical RX, CPU utilization %, ...) passes through untouched.
const series = computed(() =>
  datasets.value.map((d) => ({ name: unit.value === 'bitrate' ? d.label.replace(/\s*(bytes|bps)$/i, '') : d.label, data: d.data })),
);
const options = computed(() => ({
  chart: { type: 'area' as const, toolbar: { show: true }, zoom: { enabled: true }, animations: { enabled: !autoRefresh.value } },
  colors: datasets.value.map((d) => d.borderColor),
  dataLabels: { enabled: false },
  stroke: { curve: 'smooth' as const, width: 2 },
  fill: { type: 'gradient', gradient: { opacityFrom: 0.35, opacityTo: 0.05 } },
  legend: { show: datasets.value.length > 1, position: 'top' as const },
  grid: { borderColor: '#485e9015' },
  xaxis: { categories: labels.value, labels: { style: { fontSize: '9px' }, rotate: -45 } },
  yaxis: { tickAmount: 5, labels: { style: { fontSize: '9px' }, formatter: formatTick } },
  tooltip: { shared: true, style: { fontSize: '11px' }, y: { formatter: formatTick } },
}));
</script>

<template>
  <a-modal v-model:visible="visible" :title="title" :footer="null" width="860px" @cancel="close">
    <div class="pcm-toolbar">
      <a-radio-group v-model:value="rangeKey" button-style="solid" size="small" @change="onRangeChange">
        <a-radio-button v-for="r in RANGE_OPTIONS" :key="r.key" :value="r.key">{{ r.label }}</a-radio-button>
      </a-radio-group>
      <a-range-picker v-if="rangeKey === 'custom'" v-model:value="customRange" show-time size="small" style="margin-left: 12px" @change="onCustomRangeChange" />
      <label v-if="rangeKey !== 'custom'" class="pcm-auto-refresh">
        <a-switch v-model:checked="autoRefresh" size="small" /> Auto-refresh
      </label>
    </div>
    <a-skeleton v-if="loading && !datasets.length" active />
    <a-empty v-else-if="!datasets.length" description="No data for this period" />
    <apexchart v-else :key="chartKey" type="area" height="320" :options="options" :series="series"></apexchart>
  </a-modal>
</template>

<style scoped>
.pcm-toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
  margin-bottom: 16px;
}
.pcm-auto-refresh {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12.5px;
  color: #5a5f7d;
  margin-left: auto;
}
</style>
