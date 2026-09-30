<!--
  A Chart.js stacked-area chart for the Dashboard's "Online ONTs chart" /
  "Online devices" widgets — styled to match the plain look every other
  dashboard chart uses (this went through a "heart monitor" dark/glowing
  theme for a bit and was reverted back to this plain style).

  Why this isn't just the shared Chartjs.vue wrapper, and two real bugs found
  building it:

  1. Chart.js on this bundled build renders a completely blank canvas (no
     error) when a dataset combines `fill: true` with `scales.y.stacked:
     true` — this is exactly why these two widgets were built with
     ApexCharts earlier in the project. Worked around here by never setting
     `scales.y.stacked` at all: each dataset's `data` is pre-summed into a
     running cumulative total in JS (first dataset = its own values, filled
     to the x-axis; each next dataset = previous cumulative + its own
     values, filled down to the previous dataset's line via `fill: index -
     1`). That reproduces a stacked-area visual using Chart.js's ordinary
     (non-stacked) fill mode, which does render correctly. The original,
     non-cumulative per-series value is kept alongside each point (as
     `__raw`) purely so the tooltip can still show the real segment value
     instead of the cumulative one.

  2. The shared Chartjs.vue wrapper's `watchEffect` calls `new Chart(...)`
     on every reactive prop change without ever calling `.destroy()` on the
     previous instance first. Chart.js throws ("Canvas is already in use")
     the second time that happens on the same canvas — which these two
     widgets would hit constantly, since the Dashboard's auto-refresh
     interval reassigns their data on a timer. Handled here by keeping one
     Chart instance in a plain (non-reactive) variable and mutating its
     `.data` in place + calling `.update()` on refresh, only ever
     constructing a new instance once on mount and destroying it in
     onBeforeUnmount.
-->
<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount, watch } from 'vue';
import {
  Chart,
  LineController,
  LineElement,
  PointElement,
  LinearScale,
  CategoryScale,
  Filler,
  Legend,
  Tooltip,
} from 'chart.js';
import zoomPlugin from 'chartjs-plugin-zoom';

Chart.register(LineController, LineElement, PointElement, LinearScale, CategoryScale, Filler, Legend, Tooltip, zoomPlugin);

interface RawDataset {
  label: string;
  data: number[];
  borderColor?: string;
  backgroundColor?: string;
  hidden?: boolean;
}
const props = defineProps<{
  chart: { labels: string[]; datasets: RawDataset[] } | null;
}>();

const canvasEl = ref<HTMLCanvasElement | null>(null);
let chartInstance: Chart | null = null;

function hexToRgba(color: string, alpha: number) {
  if (color.startsWith('rgba') || color.startsWith('rgb')) {
    // already has/lacks alpha — just reuse the color as the border and let
    // the caller-provided alpha apply only to our own fill copy
    const nums = color.match(/[\d.]+/g);
    if (nums && nums.length >= 3) return `rgba(${nums[0]},${nums[1]},${nums[2]},${alpha})`;
  }
  const hex = color.replace('#', '');
  const bigint = parseInt(hex.length === 3 ? hex.split('').map((c) => c + c).join('') : hex, 16);
  const r = (bigint >> 16) & 255;
  const g = (bigint >> 8) & 255;
  const b = bigint & 255;
  return `rgba(${r},${g},${b},${alpha})`;
}

function buildConfig() {
  const visible = (props.chart?.datasets ?? []).filter((d) => !d.hidden);
  let cumulative = new Array(props.chart?.labels?.length ?? 0).fill(0);
  const datasets = visible.map((d, i) => {
    const color = d.borderColor || d.backgroundColor || '#30a46c';
    const raw = d.data;
    cumulative = cumulative.map((v, idx) => v + (raw[idx] ?? 0));
    // Small pulse dot only where the value actually changes from the
    // previous bucket — a flat run of identical values stays plain (radius
    // 0), so the eye is drawn to real changes instead of dots everywhere.
    const pointRadius = raw.map((v, idx) => (idx > 0 && v !== raw[idx - 1] ? 3 : 0));
    return {
      label: d.label,
      data: [...cumulative],
      __raw: raw,
      borderColor: color,
      // Plain solid-ish fill (opacity 0.85), matching the original
      // pre-"heartbeat" ApexCharts styling — no glow/shadow.
      backgroundColor: hexToRgba(color, 0.85),
      fill: i === 0 ? 'origin' : (i - 1) as any,
      borderWidth: 1.5,
      pointRadius,
      pointBackgroundColor: color,
      pointBorderColor: '#ffffff',
      pointBorderWidth: 1,
      pointHitRadius: 8,
      // Stepped instead of a smoothed bezier curve — this is bucketed
      // time-series data that holds a value then jumps, so a smooth curve
      // was overshooting past the real value between points, making the
      // chart visibly disagree with the actual numbers. Stepped always
      // sits exactly on the real value.
      stepped: true,
    };
  });
  return {
    type: 'line' as const,
    data: { labels: props.chart?.labels ?? [], datasets },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index' as const, intersect: false },
      plugins: {
        legend: { position: 'top' as const, align: 'start' as const, labels: { color: '#5a5f7d', boxWidth: 10 } },
        tooltip: {
          mode: 'index' as const,
          intersect: false,
          backgroundColor: '#ffffff',
          borderColor: '#e6e9f1',
          borderWidth: 1,
          titleColor: '#272b41',
          bodyColor: '#5a5f7d',
          callbacks: {
            // Show the real per-series value, not the internal cumulative
            // running total used to draw the stacked bands.
            label(ctx: any) {
              const raw = ctx.dataset.__raw?.[ctx.dataIndex];
              return `${ctx.dataset.label}: ${raw ?? ctx.parsed.y}`;
            },
          },
        },
        zoom: {
          pan: { enabled: true, mode: 'x' as const },
          zoom: { wheel: { enabled: true }, pinch: { enabled: true }, mode: 'x' as const },
        },
      },
      scales: {
        x: {
          grid: { color: '#485e9015', tickColor: '#485e9015' },
          ticks: { color: '#8c90a4', font: { size: 10 }, maxRotation: 60, minRotation: 60, autoSkipPadding: 8 },
        },
        y: {
          beginAtZero: true,
          grid: { color: '#485e9015' },
          ticks: { color: '#8c90a4', font: { size: 11 } },
        },
      },
    },
  };
}

// A small live pulse — an expanding, fading ring — around each series' most
// recent point, like a heart-monitor's live trace. Cosmetic only (`draw()`,
// never `update()`, so it can't fight the zoom plugin or restart the load
// animation); throttled to ~15fps via setInterval since a full canvas
// redraw every animation frame is wasteful for a purely decorative effect.
let pulseTimer: ReturnType<typeof setInterval> | null = null;
const pulsePlugin = {
  id: 'livePulse',
  afterDatasetsDraw(c: Chart) {
    const t = (performance.now() % 1400) / 1400; // 0 -> 1 loop, 1.4s period
    const ctx = c.ctx;
    c.data.datasets.forEach((ds: any, i: number) => {
      const meta = c.getDatasetMeta(i);
      const pt = meta.data[meta.data.length - 1];
      if (!pt || meta.hidden) return;
      ctx.save();
      ctx.beginPath();
      ctx.arc(pt.x, pt.y, 3 + t * 7, 0, Math.PI * 2);
      ctx.strokeStyle = hexToRgba(ds.borderColor, (1 - t) * 0.8);
      ctx.lineWidth = 1.5;
      ctx.stroke();
      ctx.restore();
    });
  },
};

function render() {
  if (!canvasEl.value || !props.chart) return;
  const cfg = buildConfig();
  if (chartInstance) {
    chartInstance.data = cfg.data as any;
    chartInstance.options = cfg.options as any;
    // 'none' skips Chart.js's default update animation — without it,
    // .update() replays the whole "lines grow in from zero" intro
    // animation on every call, which on a dashboard refreshing every few
    // seconds (WS-triggered or timer) looked like the chart was reloading
    // from scratch each time instead of just its data changing. The
    // initial `new Chart(...)` below still gets its normal one-time intro
    // animation — this only silences repeat updates.
    chartInstance.update('none');
  } else {
    chartInstance = new Chart(canvasEl.value, { ...cfg, plugins: [pulsePlugin] } as any);
    pulseTimer = setInterval(() => chartInstance?.draw(), 66);
  }
}

function resetZoom() {
  chartInstance?.resetZoom();
}
defineExpose({ resetZoom });

watch(() => props.chart, render, { deep: false });
onMounted(render);
onBeforeUnmount(() => {
  if (pulseTimer) clearInterval(pulseTimer);
  chartInstance?.destroy();
  chartInstance = null;
});
</script>

<template>
  <div class="heartbeat-chart">
    <button class="heartbeat-chart__reset" title="Reset zoom" @click="resetZoom"><unicon name="expand-arrows"></unicon></button>
    <canvas ref="canvasEl"></canvas>
  </div>
</template>

<style scoped>
.heartbeat-chart {
  position: relative;
  width: 100%;
  height: 100%;
}
.heartbeat-chart__reset {
  position: absolute;
  top: -2px;
  right: 0;
  z-index: 2;
  background: transparent;
  border: 1px solid #e6e9f1;
  border-radius: 4px;
  color: #8c90a4;
  width: 24px;
  height: 24px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
}
.heartbeat-chart__reset:hover {
  color: #1868db;
  border-color: #1868db;
}
.heartbeat-chart__reset :deep(svg) {
  width: 11px;
  height: 11px;
}
</style>
