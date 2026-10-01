<script setup lang="ts">
import { ref, reactive, onMounted, onBeforeUnmount, computed } from 'vue';
import { useRouter } from 'vue-router';
import { DataService } from '@/config/dataService/dataService';
import { Main } from '../styled';
import { wsClient } from '@/services/wsClient';
import { mergeById, removeById } from '@/utility/listMerge';
import { api } from '@/api/client';
import { authBackend } from '@/auth/session';
import { fromLegacyModel, loadLegacyModels, loadNewModels, type ModelRow } from './deviceModels';

const router = useRouter();
// With the new login the page reads the new, read-only catalogue (src/views/devices/deviceModels.ts); legacy is unchanged.
const newApi = authBackend() === 'cybersathy';
const loading = ref(true);
const rows = ref<ModelRow[]>([]);
const filters = reactive({ key: '', name: '', type: '' });

async function load() {
  loading.value = true;
  try {
    rows.value = newApi ? await loadNewModels(api) : await loadLegacyModels((path) => DataService.get(path));
  } finally {
    loading.value = false;
  }
}
onMounted(load);

// Real-time: device_models table changed anywhere — refresh without a manual reload.
// Pushed record's fields (id/key/name/type/vendor/icon/pollers) match this
// row's shape directly — merge instead of a full reload.
// The new catalogue only changes when it is regenerated and re-seeded, so the new login has nothing to subscribe to.
const noop = () => {};
const unsubAdded = newApi ? noop : wsClient.subscribe('event:storage:device_models:added', (msg) => mergeById(rows, fromLegacyModel(msg.data)));
const unsubUpdated = newApi ? noop : wsClient.subscribe('event:storage:device_models:updated', (msg) => mergeById(rows, fromLegacyModel(msg.data)));
const unsubDeleted = newApi ? noop : wsClient.subscribe('event:storage:device_models:deleted', (msg) => removeById(rows, msg.data.id));
onBeforeUnmount(() => {
  unsubAdded();
  unsubUpdated();
  unsubDeleted();
});

const filteredRows = computed(() =>
  rows.value.filter(
    (r) =>
      (!filters.key || r.key.toLowerCase().includes(filters.key.toLowerCase())) &&
      (!filters.name || (r.name || '').toLowerCase().includes(filters.name.toLowerCase())) &&
      (!filters.type || (r.type || '').toLowerCase().includes(filters.type.toLowerCase())),
  ),
);

function goEdit(row: ModelRow) {
  if (!row.editable) return;
  router.push({ name: 'device-model-edit', params: { id: row.id } });
}
</script>

<template>
  <sdPageHeader :routes="[{ path: '/', breadcrumbName: 'Dashboard' }, { path: '', breadcrumbName: 'Device models' }]" class="ninjadash-page-header-main" />
  <Main>
    <a-row :gutter="25">
      <a-col :span="24">
        <sdCards :headless="true">
          <div class="model-filter-row">
            <a-input v-model:value="filters.key" placeholder="Filter by key" style="max-width: 220px" />
            <a-input v-model:value="filters.name" placeholder="Filter by name" style="max-width: 220px" />
            <a-input v-model:value="filters.type" placeholder="Filter by type" style="max-width: 220px" />
          </div>
          <a-skeleton v-if="loading" active />
          <a-table v-else :data-source="filteredRows" row-key="id" size="small" :scroll="{ x: 700 }" :pagination="{ pageSize: 25 }">
            <a-table-column title="Key" data-index="key" :width="220">
              <template #default="{ record }"><strong>{{ record.key }}</strong></template>
            </a-table-column>
            <a-table-column title="Name" data-index="name" />
            <a-table-column title="Type" data-index="type" :width="110" />
            <a-table-column v-if="newApi" title="Vendor" data-index="vendor" :width="110" />
            <a-table-column v-if="newApi" title="Detected by">
              <template #default="{ record }"><code v-if="record.detection" class="model-detection">{{ record.detection }}</code><span v-else>—</span></template>
            </a-table-column>
            <a-table-column v-if="!newApi" title="Default pollers" :width="220">
              <template #default="{ record }">
                <ul v-if="record.pollers && Object.keys(record.pollers).length" class="model-pollers">
                  <li v-for="(seconds, name) in record.pollers" :key="name">{{ name }} <em>({{ seconds }}s)</em></li>
                </ul>
                <span v-else>—</span>
              </template>
            </a-table-column>
            <a-table-column v-if="!newApi" title="" :width="90">
              <template #default="{ record }">
                <a @click="goEdit(record)" title="Edit"><unicon name="edit"></unicon></a>
              </template>
            </a-table-column>
          </a-table>
        </sdCards>
      </a-col>
    </a-row>
  </Main>
</template>

<style scoped>
.model-filter-row {
  display: flex;
  gap: 16px;
  padding: 16px 16px 16px 0;
  flex-wrap: wrap;
}
.model-detection {
  font-size: 12px;
  word-break: break-all;
}
.model-pollers {
  margin: 0;
  padding-left: 16px;
  font-size: 12px;
  color: #8c90a4;
}
:deep(.ant-table) a {
  color: #8c90a4;
}
:deep(.ant-table) a:hover {
  color: #1868db;
}
</style>
