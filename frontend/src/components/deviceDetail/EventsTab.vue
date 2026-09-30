<!--
  The OLT dashboard's "events" tab: unresolved events for this device, with
  inline resolve. Reverse engineered from the Events tab (exported `E`) in
  Topology-gTZFUR5F.js.
-->
<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount } from 'vue';
import { useRouter } from 'vue-router';
import { DataService } from '@/config/dataService/dataService';
import { notification } from 'ant-design-vue';
import LogRowDetails from '@/components/logs/LogRowDetails.vue';
import { wsClient } from '@/services/wsClient';

const props = defineProps<{ deviceId: number }>();
const router = useRouter();

interface WcaEvent {
  id: number;
  severity: string;
  annotation: string | null;
  name: string;
  description: string;
  created_at: string;
}

const loading = ref(true);
const events = ref<WcaEvent[]>([]);
const resolvingId = ref<number | null>(null);
const severityMeta: Record<string, string> = { CRITICAL: 'sev-critical', WARNING: 'sev-warning', INFO: 'sev-info' };

async function loadInfo() {
  loading.value = true;
  try {
    const { data } = await DataService.post('/component/events', {
      device: { id: props.deviceId },
      not_resolved: true,
      limit: 100,
      page: 1,
      ascending: 0,
    });
    events.value = data.data || [];
  } catch {
    events.value = [];
  } finally {
    loading.value = false;
  }
}
async function resolveEvent(ev: WcaEvent) {
  resolvingId.value = ev.id;
  try {
    await DataService.put(`/component/events/${ev.id}/resolve`, {});
    events.value = events.value.filter((e) => e.id !== ev.id);
    notification.success({ message: 'Event resolved' });
  } catch (err: any) {
    notification.error({ message: 'Could not resolve event', description: err?.response?.data?.error?.description || 'Please try again.' });
  } finally {
    resolvingId.value = null;
  }
}
function goToEvents() {
  router.push({ name: 'events', query: { device_id: String(props.deviceId) } });
}
defineExpose({ loadInfo });
onMounted(() => loadInfo());

// Real-time: a new alert or any events-table change refreshes this tab.
// The generic storage:c_events:* signal carries no device id, so this
// can't filter to just this device client-side — refetching regardless is
// still a cheap DB-scoped read (not a live device query), same cost as the
// manual refresh already was.
const unsubWebhook = wsClient.subscribe('event:webhook:alertmanager', () => loadInfo());
const unsubAdded = wsClient.subscribe('event:storage:c_events:added', () => loadInfo());
const unsubUpdated = wsClient.subscribe('event:storage:c_events:updated', () => loadInfo());
onBeforeUnmount(() => {
  unsubWebhook();
  unsubAdded();
  unsubUpdated();
});
</script>

<template>
  <div>
    <sdButton type="light" size="small" style="margin-bottom: 12px" @click="goToEvents">Go to events</sdButton>
    <a-skeleton v-if="loading" active />
    <a-empty v-else-if="!events.length" description="No unresolved events" />
    <a-table v-else :data-source="events" :expand-column-width="30" row-key="id" size="small" :pagination="{ pageSize: 20 }">
      <template #expandedRowRender="{ record }">
        <LogRowDetails :record="record" />
      </template>
      <a-table-column title="Severity" :width="100">
        <template #default="{ record }"><span class="sev-tag" :class="severityMeta[record.severity]">{{ record.severity }}</span></template>
      </a-table-column>
      <a-table-column title="Time" data-index="created_at" :width="150" />
      <a-table-column title="Annotation" :width="180">
        <template #default="{ record }"><strong>{{ record.annotation || record.name }}</strong></template>
      </a-table-column>
      <a-table-column title="Description" data-index="description" />
      <a-table-column title="" :width="100">
        <template #default="{ record }">
          <sdButton size="small" type="light" :loading="resolvingId === record.id" @click="resolveEvent(record)"><unicon name="check"></unicon> Resolve</sdButton>
        </template>
      </a-table-column>
    </a-table>
  </div>
</template>

<style scoped>
.sev-tag {
  display: inline-block;
  padding: 1px 9px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
}
.sev-tag.sev-info {
  background: rgba(24, 104, 219, 0.12);
  color: #1868db;
}
.sev-tag.sev-warning {
  background: rgba(212, 160, 23, 0.15);
  color: #ab7d0a;
}
.sev-tag.sev-critical {
  background: rgba(166, 10, 10, 0.12);
  color: #a60a0a;
}
</style>
