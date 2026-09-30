<!--
  Sidebar "Device calling" card: for every device-facing module any tab on
  this page has queried, shows whether that last read came from cache or
  live from the device (and how long ago), or errored. Reverse engineered
  from DeviceCallingTable-uBEWluVj.js; fed by the shared `deviceCalling`
  composable every OLT tab reports its response `meta` into.
-->
<script setup lang="ts">
import { computed, ref } from 'vue';
import dayjs from 'dayjs';
import relativeTime from 'dayjs/plugin/relativeTime';
import type { DeviceCalling } from '@/composables/useDeviceCalling';

dayjs.extend(relativeTime);

const props = defineProps<{ deviceCalling: DeviceCalling; offline?: boolean }>();
const collapsed = ref(false);

const sortedEntries = computed(() => Object.entries(props.deviceCalling.meta).sort(([a], [b]) => a.localeCompare(b)));
function ago(time?: string) {
  return time ? dayjs(time).fromNow() : '';
}
</script>

<template>
  <sdCards v-if="sortedEntries.length" :headless="true" class="calling-card">
    <div class="calling-card__title" @click="collapsed = !collapsed">
      <span>Device calling</span>
      <unicon :name="collapsed ? 'angle-down' : 'angle-up'" width="16" height="16"></unicon>
    </div>
    <div v-show="!collapsed">
      <p v-if="offline" class="calling-card__offline">Device is offline</p>
      <div v-for="[name, entry] in sortedEntries" :key="name" class="calling-card__row">
        <b>{{ name }}</b>:
        <span v-if="entry.from_cache"><b>from cache</b> ({{ ago(entry.time) }})</span>
        <span v-else-if="entry.error" class="is-error" :title="entry.error.message"><b>error</b> ({{ ago(entry.time) }})</span>
        <span v-else class="is-online"><b>online</b> ({{ ago(entry.time) }})</span>
      </div>
    </div>
  </sdCards>
</template>

<style scoped>
.calling-card {
  margin-bottom: 16px;
}
.calling-card__title {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-weight: 700;
  font-size: 13px;
  color: #272b41;
  cursor: pointer;
  user-select: none;
}
.calling-card__offline {
  text-align: center;
  font-weight: 700;
  margin: 10px 0;
}
.calling-card__row {
  font-size: 11.5px;
  margin-top: 6px;
  color: #5a5f7d;
}
.calling-card__row .is-online {
  color: darkgreen;
}
.calling-card__row .is-error {
  color: darkred;
}
</style>
