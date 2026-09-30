<!--
  Sidebar "Supported modules" card: the list of poller/control modules the
  device's matched model declares support for (from
  `/switcher-core/device/system/{id}` → `data.meta.modules`). Reverse
  engineered from the SupportedModules component in SupportedModules-DeqsCDHV.js.
-->
<script setup lang="ts">
import { ref } from 'vue';
defineProps<{ modules: string[] }>();
const collapsed = ref(true);
</script>

<template>
  <sdCards :headless="true" class="sm-card">
    <div class="sm-card__title" @click="collapsed = !collapsed">
      <span>Supported modules</span>
      <unicon :name="collapsed ? 'angle-down' : 'angle-up'" width="16" height="16"></unicon>
    </div>
    <ul v-show="!collapsed" class="sm-card__list">
      <li v-for="m in modules" :key="m">{{ m }}</li>
    </ul>
  </sdCards>
</template>

<style scoped>
.sm-card {
  margin-bottom: 16px;
}
.sm-card__title {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-weight: 700;
  font-size: 13px;
  color: #272b41;
  cursor: pointer;
  user-select: none;
}
.sm-card__list {
  max-height: 250px;
  overflow-y: auto;
  margin: 10px 0 0;
  padding-left: 18px;
  font-size: 12px;
  color: #5a5f7d;
}
.sm-card__list li {
  padding: 2px 0;
}
</style>
