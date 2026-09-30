<script setup lang="ts">
import NetworkParticles from './NetworkParticles.vue';
</script>

<template>
  <div class="auth-split">
    <aside class="auth-branding">
      <div class="auth-branding__glow auth-branding__glow--one"></div>
      <div class="auth-branding__glow auth-branding__glow--two"></div>
      <NetworkParticles />

      <div class="auth-branding__content">
        <div class="auth-branding__logo">
          <img src="/src/assets/img/logo-cybersathy-full.png" alt="CyberSathy" />
        </div>
        <h1 class="auth-branding__title">Network monitoring,<br />without the guesswork.</h1>
        <p class="auth-branding__subtitle">
          Live device health, ONT signal levels, topology and alerting — one place to see everything happening
          across your network, from the core to the last mile.
        </p>
        <ul class="auth-branding__points">
          <li><unicon name="signal-alt-3"></unicon> Real-time ONT &amp; interface status</li>
          <li><unicon name="sitemap"></unicon> Topology &amp; link visibility</li>
          <li><unicon name="bell"></unicon> Alerting that reaches you first</li>
          <li><unicon name="server-network"></unicon> Multi-vendor OLT &amp; switch support</li>
          <li><unicon name="brackets-curly"></unicon> Macro-driven device automation</li>
          <li><unicon name="shield-check"></unicon> Role-based access control</li>
        </ul>
      </div>
    </aside>

    <main class="auth-form-panel">
      <NetworkParticles dot-color="24,104,219" :line-opacity="0.10" :dot-opacity="0.35" />
      <div class="auth-form-panel__content">
        <router-view></router-view>
      </div>
    </main>
  </div>
</template>

<style scoped>
.auth-split {
  min-height: 100vh;
  display: flex;
}

.auth-branding {
  position: relative;
  flex: 0 0 50%;
  min-height: 100vh;
  overflow: hidden;
  background: linear-gradient(160deg, #0b1f3f 0%, #123a75 55%, #1868db 100%);
  display: flex;
  align-items: center;
  padding: 60px 56px;
}

.auth-branding__glow {
  position: absolute;
  border-radius: 50%;
  filter: blur(70px);
  opacity: 0.55;
  pointer-events: none;
  animation: auth-glow-float 10s ease-in-out infinite;
}
.auth-branding__glow--one {
  width: 340px;
  height: 340px;
  top: -80px;
  right: -100px;
  background: #4f9dff;
}
.auth-branding__glow--two {
  width: 260px;
  height: 260px;
  bottom: -60px;
  left: -80px;
  background: #1450ad;
  animation-delay: -5s;
}

@keyframes auth-glow-float {
  0%,
  100% {
    transform: translate(0, 0) scale(1);
  }
  50% {
    transform: translate(20px, -20px) scale(1.08);
  }
}

.auth-branding__content {
  position: relative;
  z-index: 1;
  color: #ffffff;
}

.auth-branding__logo {
  display: inline-flex;
  background: rgba(255, 255, 255, 0.92);
  border-radius: 12px;
  padding: 14px 22px;
  margin-bottom: 48px;
}
.auth-branding__logo img {
  height: 56px;
  width: auto;
  display: block;
}

.auth-branding__title {
  font-size: 42px;
  line-height: 1.22;
  font-weight: 700;
  color: #ffffff;
  margin-bottom: 22px;
}

.auth-branding__subtitle {
  font-size: 17px;
  line-height: 1.65;
  color: rgba(255, 255, 255, 0.78);
  max-width: 440px;
  margin-bottom: 40px;
}

.auth-branding__points {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 20px;
}
.auth-branding__points li {
  display: flex;
  align-items: center;
  gap: 14px;
  font-size: 16px;
  font-weight: 500;
  color: rgba(255, 255, 255, 0.92);
}
.auth-branding__points :deep(svg) {
  width: 24px;
  height: 24px;
  fill: #ffffff;
  flex-shrink: 0;
  opacity: 0.9;
}

.auth-form-panel {
  position: relative;
  flex: 0 0 50%;
  min-height: 100vh;
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #f8f9fb;
  padding: 40px 24px;
}

.auth-form-panel__content {
  position: relative;
  z-index: 1;
  width: 100%;
  display: flex;
  justify-content: center;
}

@media (max-width: 991px) {
  .auth-branding {
    display: none;
  }
  .auth-form-panel {
    flex: 1 1 100%;
  }
}
</style>
