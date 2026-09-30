<!--
  Shared live-traffic modal — polls the real device for its current
  in/out bit rate on a chosen interval and plots it as it comes in.
  Reverse engineered from LiveTrafficComponent in Macros-CixxxWzL.js:
  same endpoint (`GET /component/live_traffic/view`), same current/avg/max
  table (kept "In"/"Out", matching the original — see below), same
  interval choices and default (10s), same 1024-based bits formatter.

  In/Out vs Download/Upload: tried relabelling these as Download/Upload,
  but the "in"/"out" counters are raw ifInOctets/ifOutOctets on whatever
  interface is being viewed, so which one is "download" flips with the
  interface's own direction — confirmed empirically on this system's real
  counters: every ONU/PON (subscriber-facing) interface has out >> in by
  6-10x, consistent with out=download on that side, but the OLT's own
  uplink ports show no consistent in/out ratio at all (some in-heavy, some
  out-heavy) since they face the opposite direction (toward the core) and
  aren't uniformly "the" WAN link. A single Download/Upload label can't be
  correct for both, so this keeps the original's own plain In/Out — the
  one labelling that's always technically correct. The mirrored area chart
  (In up from a centre line, Out down from it — the original's own look)
  is kept too; the y-axis is formatted through the same bits formatter so
  a near-zero tick reads as a plain "0" rather than raw float noise. The
  chart's axis/tooltip use ONE fixed unit sized to the current history's
  largest magnitude (not one re-picked per value) — this view polls
  continuously, so auto-picking per value would jump between
  Kb/s/Mb/s/Gb/s on nearly every refresh as the reading crossed a 1024
  boundary, reading as the numbers themselves being wrong. The
  current/avg/max table cells keep their own per-value unit — each is a
  single standalone number, not neighbouring ticks on one axis, so there's
  no inconsistency to cause.

  vue3-apexcharts doesn't reliably re-apply function-valued options (the
  yaxis/tooltip formatters) on a reactive update — after the first render,
  a later poll could leave the chart running with no working formatter at
  all, showing raw unrounded numbers. Forcing a fresh `<apexchart>`
  instance per poll (via :key) avoids that; animations were already off
  here, so there's no smoothness lost by remounting.
-->
<script setup lang="ts">
import { ref, computed, onBeforeUnmount } from 'vue';
import { DataService } from '@/config/dataService/dataService';
import { formatBitsPerSecond as formatBitsRates, pickBitsUnit, formatWithUnit } from '@/utility/formatters';

const visible = ref(false);
const deviceId = ref<number | null>(null);
const interfaceBindKey = ref<number | string | null>(null);
const label = ref('');
const intervalSeconds = ref(10);
const intervalOptions = [5, 10, 30, 60, 120, 300];
let timer: ReturnType<typeof setInterval> | null = null;
const chartKey = ref(0);

interface Reading {
  time: string;
  in_bps: number;
  out_bps: number;
}
const current = ref<Reading>({ time: '', in_bps: 0, out_bps: 0 });
const history = ref<Reading[]>([]);

async function poll() {
  if (!deviceId.value || !interfaceBindKey.value) return;
  try {
    const { data } = await DataService.get('/component/live_traffic/view', { device_id: deviceId.value, interface: interfaceBindKey.value });
    const reading = { time: data.data.time, in_bps: data.data.rate.in_bps, out_bps: data.data.rate.out_bps };
    current.value = reading;
    history.value.push(reading);
    if (history.value.length > 60) history.value.shift();
    chartKey.value++;
  } catch {
    /* keep last known reading */
  }
}

function startTimer() {
  if (timer) clearInterval(timer);
  timer = setInterval(poll, intervalSeconds.value * 1000);
}

function open(device: number, bindKey: number | string, ifaceLabel: string) {
  deviceId.value = device;
  interfaceBindKey.value = bindKey;
  label.value = ifaceLabel;
  history.value = [];
  current.value = { time: '', in_bps: 0, out_bps: 0 };
  visible.value = true;
  poll();
  startTimer();
}
function close() {
  visible.value = false;
  if (timer) clearInterval(timer);
}
defineExpose({ open });
onBeforeUnmount(() => {
  if (timer) clearInterval(timer);
});

function watchInterval() {
  startTimer();
}

const avg = computed(() => {
  if (!history.value.length) return { in_bps: 0, out_bps: 0 };
  const sum = history.value.reduce((a, r) => ({ in_bps: a.in_bps + r.in_bps, out_bps: a.out_bps + r.out_bps }), { in_bps: 0, out_bps: 0 });
  return { in_bps: sum.in_bps / history.value.length, out_bps: sum.out_bps / history.value.length };
});
const max = computed(() => history.value.reduce((a, r) => ({ in_bps: Math.max(a.in_bps, r.in_bps), out_bps: Math.max(a.out_bps, r.out_bps) }), { in_bps: 0, out_bps: 0 }));

const series = computed(() => [
  { name: 'In', data: history.value.map((r) => r.in_bps) },
  { name: 'Out', data: history.value.map((r) => -1 * r.out_bps) },
]);
const chartUnit = computed(() => {
  let maxAbs = 0;
  history.value.forEach((r) => {
    maxAbs = Math.max(maxAbs, Math.abs(r.in_bps), Math.abs(r.out_bps));
  });
  return pickBitsUnit(maxAbs);
});
const formatChartTick = (v: number) => formatWithUnit(v, chartUnit.value, 1);
const options = computed(() => ({
  chart: { type: 'area' as const, toolbar: { show: false }, animations: { enabled: false } },
  colors: ['rgba(2,2,233,1)', 'rgba(8,123,3,1)'],
  dataLabels: { enabled: false },
  stroke: { curve: 'smooth' as const, width: 2 },
  fill: { type: 'gradient', gradient: { opacityFrom: 0.35, opacityTo: 0.05 } },
  legend: { position: 'top' as const },
  grid: { borderColor: '#485e9015' },
  xaxis: { categories: history.value.map((r) => r.time), labels: { style: { fontSize: '9px' } } },
  yaxis: { tickAmount: 5, labels: { style: { fontSize: '9px' }, formatter: formatChartTick } },
  tooltip: { style: { fontSize: '11px' }, y: { formatter: formatChartTick } },
}));
</script>

<template>
  <a-modal v-model:visible="visible" title="Live traffic view" :footer="null" width="900px" @cancel="close">
    <p class="lt-label">{{ label }}</p>
    <a-row :gutter="20" style="margin-bottom: 14px">
      <a-col :xs="24" :md="16">
        <table class="lt-table">
          <thead>
            <tr><th></th><th>Current</th><th>Avg</th><th>Max</th></tr>
          </thead>
          <tbody>
            <tr>
              <th class="lt-table__dir lt-table__dir--in"><unicon name="chart-growth" width="15" height="15"></unicon> In</th>
              <td>{{ formatBitsRates(current.in_bps) }}</td>
              <td>{{ formatBitsRates(avg.in_bps) }}</td>
              <td>{{ formatBitsRates(max.in_bps) }}</td>
            </tr>
            <tr>
              <th class="lt-table__dir lt-table__dir--out"><unicon name="chart-down" width="15" height="15"></unicon> Out</th>
              <td>{{ formatBitsRates(current.out_bps) }}</td>
              <td>{{ formatBitsRates(avg.out_bps) }}</td>
              <td>{{ formatBitsRates(max.out_bps) }}</td>
            </tr>
          </tbody>
        </table>
      </a-col>
      <a-col :xs="24" :md="8">
        <label class="lt-interval-label">Update interval</label>
        <a-select v-model:value="intervalSeconds" style="width: 100%" @change="watchInterval">
          <a-select-option v-for="s in intervalOptions" :key="s" :value="s">{{ s }}s</a-select-option>
        </a-select>
      </a-col>
    </a-row>
    <apexchart :key="chartKey" type="area" height="280" :options="options" :series="series"></apexchart>
  </a-modal>
</template>

<style scoped>
.lt-label {
  font-weight: 700;
  margin-bottom: 10px;
}
.lt-table {
  width: 100%;
  border-collapse: collapse;
}
.lt-table th,
.lt-table td {
  padding: 4px 8px;
  font-size: 13px;
  text-align: center;
}
.lt-table thead th {
  color: #8c90a4;
  font-size: 11px;
  text-transform: uppercase;
}
.lt-table tbody th {
  text-align: left;
}
.lt-table__dir {
  display: flex;
  align-items: center;
  gap: 5px;
}
.lt-table__dir--in {
  color: #1868db;
}
.lt-table__dir--out {
  color: #30a46c;
}
/* The app's global `.unicon svg { fill: <theme gray> }` rule otherwise
   wins over these labels' own color — force the glyph to match. */
.lt-table__dir--in :deep(svg) {
  fill: #1868db !important;
}
.lt-table__dir--out :deep(svg) {
  fill: #30a46c !important;
}
.lt-interval-label {
  display: block;
  font-size: 12px;
  color: #5a5f7d;
  margin-bottom: 4px;
}
</style>
