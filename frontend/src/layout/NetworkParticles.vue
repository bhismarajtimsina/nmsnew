<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref } from 'vue';

// Lightweight canvas particle network — drifting nodes with lines drawn
// between nearby ones, brightening on proximity. No external dependency;
// hand-rolled so it doesn't add another package to chase version bugs on.
// Deliberately themed as a "network graph" to fit a network-monitoring product.

const props = withDefaults(
  defineProps<{
    /** RGB triplet e.g. "255,255,255" — white for dark panels, navy/blue for light ones. */
    dotColor?: string;
    lineOpacity?: number;
    dotOpacity?: number;
  }>(),
  {
    dotColor: '255,255,255',
    lineOpacity: 0.12,
    dotOpacity: 0.55,
  },
);

const canvasRef = ref<HTMLCanvasElement | null>(null);
let ctx: CanvasRenderingContext2D | null = null;
let raf = 0;
let resizeObserver: ResizeObserver | null = null;

type Particle = { x: number; y: number; vx: number; vy: number; r: number };
let particles: Particle[] = [];
let width = 0;
let height = 0;

const PARTICLE_COUNT = 46;
const LINK_DISTANCE = 130;
const SPEED = 0.18;

function seedParticles() {
  particles = Array.from({ length: PARTICLE_COUNT }, () => ({
    x: Math.random() * width,
    y: Math.random() * height,
    vx: (Math.random() - 0.5) * SPEED,
    vy: (Math.random() - 0.5) * SPEED,
    r: 1 + Math.random() * 1.6,
  }));
}

function resize() {
  const canvas = canvasRef.value;
  if (!canvas) return;
  const parent = canvas.parentElement;
  width = parent ? parent.clientWidth : window.innerWidth;
  height = parent ? parent.clientHeight : window.innerHeight;
  const dpr = Math.min(window.devicePixelRatio || 1, 2);
  canvas.width = width * dpr;
  canvas.height = height * dpr;
  canvas.style.width = width + 'px';
  canvas.style.height = height + 'px';
  ctx?.setTransform(dpr, 0, 0, dpr, 0, 0);
  seedParticles();
}

function tick() {
  if (!ctx) return;
  ctx.clearRect(0, 0, width, height);

  for (const p of particles) {
    p.x += p.vx;
    p.y += p.vy;
    if (p.x < 0 || p.x > width) p.vx *= -1;
    if (p.y < 0 || p.y > height) p.vy *= -1;
  }

  for (let i = 0; i < particles.length; i++) {
    for (let j = i + 1; j < particles.length; j++) {
      const a = particles[i];
      const b = particles[j];
      const dx = a.x - b.x;
      const dy = a.y - b.y;
      const dist = Math.sqrt(dx * dx + dy * dy);
      if (dist < LINK_DISTANCE) {
        ctx.strokeStyle = `rgba(${props.dotColor},${props.lineOpacity * (1 - dist / LINK_DISTANCE)})`;
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(a.x, a.y);
        ctx.lineTo(b.x, b.y);
        ctx.stroke();
      }
    }
  }

  for (const p of particles) {
    ctx.beginPath();
    ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
    ctx.fillStyle = `rgba(${props.dotColor},${props.dotOpacity})`;
    ctx.fill();
  }

  raf = requestAnimationFrame(tick);
}

onMounted(() => {
  const canvas = canvasRef.value;
  if (!canvas) return;
  ctx = canvas.getContext('2d');
  resize();

  resizeObserver = new ResizeObserver(() => resize());
  if (canvas.parentElement) resizeObserver.observe(canvas.parentElement);

  const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (!prefersReducedMotion) {
    raf = requestAnimationFrame(tick);
  } else {
    // Still draw one static frame so the panel isn't empty.
    tick();
    cancelAnimationFrame(raf);
  }
});

onBeforeUnmount(() => {
  cancelAnimationFrame(raf);
  resizeObserver?.disconnect();
});
</script>

<template>
  <canvas ref="canvasRef" class="network-particles"></canvas>
</template>

<style scoped>
.network-particles {
  position: absolute;
  inset: 0;
  display: block;
  pointer-events: none;
}
</style>
