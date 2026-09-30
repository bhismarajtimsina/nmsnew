<!--
  Sidebar "Pollers" card: the last run of every poller module configured
  for this device, refreshed every 6s while mounted, plus a "Run poller"
  button that requests an immediate background poll. Reverse engineered
  from the Pollers component in SupportedModules-DeqsCDHV.js.
-->
<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount, watch } from 'vue';
import { DataService } from '@/config/dataService/dataService';
import { notification } from 'ant-design-vue';
import { wsClient } from '@/services/wsClient';

const props = defineProps<{ deviceId: number }>();

interface PollerRun {
  id: number;
  poller: string;
  stop_at: string | null;
  status: string;
}

const collapsed = ref(true);
const loading = ref(true);
const errorMessage = ref('');
const runs = ref<PollerRun[]>([]);
const running = ref(false);
let timer: ReturnType<typeof setInterval> | null = null;

async function load() {
  try {
    const { data } = await DataService.get(`/poller/latests/${props.deviceId}`);
    runs.value = data.data || [];
  } catch {
    errorMessage.value = 'Error loading info';
  }
}

async function runPoller() {
  running.value = true;
  try {
    await DataService.put(`/poller/poll-background/${props.deviceId}`, {});
    notification.success({ message: 'Poll requested' });
    setTimeout(() => (running.value = false), 30000);
  } catch {
    running.value = false;
  }
}

function startTimer() {
  if (timer) clearInterval(timer);
  timer = setInterval(load, 6000);
}

watch(
  () => props.deviceId,
  async () => {
    loading.value = true;
    await load();
    loading.value = false;
    startTimer();
  },
);

// Real-time: the 6s timer above is a safety-net poll — this makes a run
// finishing show up the instant it happens instead of up to 6s later.
const unsubPoller = wsClient.subscribe('event:poller:finished', (msg) => {
  if (msg.data?.device?.id === props.deviceId) load();
});

onMounted(async () => {
  loading.value = true;
  await load();
  loading.value = false;
  startTimer();
});
onBeforeUnmount(() => {
  if (timer) clearInterval(timer);
  unsubPoller();
});
</script>

<template>
  <sdCards :headless="true" class="pollers-card">
    <div class="pollers-card__title" @click="collapsed = !collapsed">
      <span>Pollers</span>
      <unicon :name="collapsed ? 'angle-down' : 'angle-up'" width="16" height="16"></unicon>
    </div>
    <div v-show="!collapsed">
      <a-skeleton v-if="loading" active :paragraph="{ rows: 3 }" />
      <p v-else-if="errorMessage" class="pollers-card__error">{{ errorMessage }}</p>
      <a-empty v-else-if="!runs.length" description="Poller hasn't worked yet" />
      <div v-else class="pollers-card__list">
        <div v-for="r in runs" :key="r.id" class="pollers-card__row" :style="{ color: r.stop_at === null ? '#1868db' : r.status === 'SUCCESS' ? 'darkgreen' : 'darkred' }">
          <b>{{ r.poller }}</b>
          <small v-if="r.stop_at">{{ r.stop_at }}</small>
          <a-spin v-else size="small" />
        </div>
      </div>
      <sdButton v-if="!loading" type="primary" size="small" block :loading="running" style="margin-top: 12px" @click="runPoller">
        <unicon name="download-alt" width="14" height="14"></unicon> Run poller
      </sdButton>
    </div>
  </sdCards>
</template>

<style scoped>
.pollers-card {
  margin-bottom: 16px;
}
.pollers-card__title {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-weight: 700;
  font-size: 13px;
  color: #272b41;
  cursor: pointer;
  user-select: none;
}
.pollers-card__list {
  max-height: 220px;
  overflow-y: auto;
  margin-top: 10px;
}
.pollers-card__row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 12px;
  padding: 3px 0;
}
.pollers-card__error {
  color: darkred;
  text-align: center;
  margin-top: 10px;
}
</style>
