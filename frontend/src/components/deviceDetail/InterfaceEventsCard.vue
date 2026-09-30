<!--
  Per-interface unresolved events — reverse engineered from Events in
  UpwardTopology-BurABP73.js. The real endpoint's own `labels` filter
  parameter turned out not to actually narrow results server-side when
  tested live (same class of issue already found and worked around for
  the device-level Events tab), so this fetches the device's unresolved
  events and filters to this interface client-side by matching
  `labels.iface_id`/`labels.iface_name`.
-->
<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { DataService } from '@/config/dataService/dataService';

const props = defineProps<{ deviceId: number; interfaceBindKey: string; interfaceName: string }>();
const router = useRouter();

interface WcaEvent {
  id: number;
  severity: string;
  annotation: string | null;
  name: string;
  description: string;
  created_at: string;
  labels?: Record<string, any>;
}

const loading = ref(true);
const events = ref<WcaEvent[]>([]);
const severityMeta: Record<string, string> = { CRITICAL: 'sev-critical', WARNING: 'sev-warning', INFO: 'sev-info' };

async function loadInfo() {
  loading.value = true;
  try {
    const { data } = await DataService.post('/component/events', { device: { id: props.deviceId }, not_resolved: true, limit: 500, page: 1, ascending: 0 });
    events.value = (data.data || []).filter(
      (e: WcaEvent) => String(e.labels?.iface_id) === props.interfaceBindKey || e.labels?.iface_name === props.interfaceName,
    );
  } catch {
    events.value = [];
  } finally {
    loading.value = false;
  }
}
function goToEvents() {
  router.push({ name: 'events', query: { device_id: String(props.deviceId) } });
}
onMounted(() => loadInfo());
</script>

<template>
  <sdCards title="Events" style="margin-bottom: 20px">
    <sdButton type="light" size="small" style="margin-bottom: 12px" @click="goToEvents">Go to events</sdButton>
    <a-skeleton v-if="loading" active />
    <a-empty v-else-if="!events.length" description="No unresolved events for this interface" />
    <a-table v-else :data-source="events" row-key="id" size="small" :pagination="{ pageSize: 10 }">
      <a-table-column title="Severity" :width="100">
        <template #default="{ record }"><span class="iec-sev" :class="severityMeta[record.severity]">{{ record.severity }}</span></template>
      </a-table-column>
      <a-table-column title="Time" data-index="created_at" :width="150" />
      <a-table-column title="Annotation">
        <template #default="{ record }"><strong>{{ record.annotation || record.name }}</strong></template>
      </a-table-column>
      <a-table-column title="Description" data-index="description" />
    </a-table>
  </sdCards>
</template>

<style scoped>
.iec-sev {
  display: inline-block;
  padding: 1px 9px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
}
.iec-sev.sev-info {
  background: rgba(24, 104, 219, 0.12);
  color: #1868db;
}
.iec-sev.sev-warning {
  background: rgba(212, 160, 23, 0.15);
  color: #ab7d0a;
}
.iec-sev.sev-critical {
  background: rgba(166, 10, 10, 0.12);
  color: #a60a0a;
}
</style>
