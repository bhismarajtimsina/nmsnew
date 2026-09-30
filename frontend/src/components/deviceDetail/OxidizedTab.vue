<!--
  The OLT dashboard's "oxidized" tab: this device's config-backup status
  and last saved running-config, from the real Oxidized integration.
  Reverse engineered from Oxidized-Dx_jxiht.js — same two endpoints
  (`/component/oxidized/data/status/{id}`, then `/data/config/{id}` once a
  status exists), same "device not found in Oxidized" empty state, same
  "Open in Oxidized" link out to its own web UI.
-->
<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { DataService } from '@/config/dataService/dataService';
import type { DeviceCalling } from '@/composables/useDeviceCalling';

const props = defineProps<{ deviceId: number; deviceCalling: DeviceCalling }>();

interface OxidizedStatus {
  name: string;
  ip: string;
  model: string;
  last: { start: string; end: string; status: string; time: number } | null;
  mtime: string;
}

const loading = ref(true);
const status = ref<OxidizedStatus | null>(null);
const config = ref<string | null>(null);

async function loadStatus() {
  try {
    const { data } = await DataService.get(`/component/oxidized/data/status/${props.deviceId}`);
    status.value = data.data;
  } catch {
    status.value = null;
  }
}
async function loadConfig() {
  try {
    const { data } = await DataService.get(`/component/oxidized/data/config/${props.deviceId}`);
    config.value = data.data;
  } catch {
    config.value = null;
  }
}

async function loadInfo() {
  loading.value = true;
  status.value = null;
  config.value = null;
  await loadStatus();
  if (status.value !== null) await loadConfig();
  loading.value = false;
}
defineExpose({ loadInfo });
onMounted(() => loadInfo());
</script>

<template>
  <div>
    <a-skeleton v-if="loading" active />
    <a-empty v-else-if="!status" description="Device not found in Oxidized" />
    <template v-else>
      <a-row :gutter="20">
        <a-col :xs="24" :md="16">
          <table v-if="status.last" class="ox-kv">
            <tbody>
              <tr><th>Model</th><td>{{ status.model }}</td></tr>
              <tr>
                <th>Last status</th>
                <td><span class="ox-status" :class="status.last.status === 'success' ? 'is-success' : 'is-failed'">{{ status.last.status }}</span></td>
              </tr>
              <tr><th>Start</th><td>{{ status.last.start }}</td></tr>
              <tr><th>End</th><td>{{ status.last.end }}</td></tr>
              <tr><th>Spent</th><td>{{ status.last.time.toFixed(2) }}s</td></tr>
            </tbody>
          </table>
        </a-col>
        <a-col :xs="24" :md="8">
          <a v-if="status.last" class="ox-open-btn" :href="`/oxidized/node/show/${status.ip}`" target="_blank" rel="noopener">
            <unicon name="file-alt" width="16" height="16"></unicon> Open in Oxidized
          </a>
        </a-col>
      </a-row>

      <template v-if="config !== null">
        <hr style="margin: 16px 0" />
        <h4>Last config</h4>
        <pre class="ox-config">{{ config }}</pre>
      </template>
    </template>
  </div>
</template>

<style scoped>
.ox-kv {
  width: 100%;
  border-collapse: collapse;
}
.ox-kv th {
  text-align: left;
  font-weight: 600;
  color: #5a5f7d;
  font-size: 12.5px;
  padding: 4px 10px 4px 0;
  white-space: nowrap;
}
.ox-kv td {
  font-size: 13px;
  padding: 4px 0;
}
.ox-status {
  display: inline-block;
  padding: 1px 10px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  color: #fff;
}
.ox-status.is-success {
  background: #1a7a3a;
}
.ox-status.is-failed {
  background: #a60a0a;
}
.ox-open-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: #1868db;
  color: #fff;
  padding: 7px 14px;
  border-radius: 6px;
  font-size: 13px;
  font-weight: 600;
}
.ox-open-btn:hover {
  color: #fff;
  opacity: 0.9;
}
.ox-open-btn :deep(svg) {
  fill: #fff !important;
}
.ox-config {
  background: #1e1e1e;
  color: #d4d4d4;
  padding: 12px;
  border-radius: 6px;
  font-size: 12px;
  max-height: 600px;
  overflow: auto;
  white-space: pre-wrap;
}
</style>
