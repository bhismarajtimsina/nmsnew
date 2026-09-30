<script setup lang="ts">
import { ref, onMounted, computed, reactive } from 'vue';
import { DataService } from '@/config/dataService/dataService';
import { Main } from '../styled';
import QrPrintPreviewModal from './QrPrintPreviewModal.vue';

interface DeviceRow {
  id: number;
  ip: string;
  name: string;
  description: string;
  group?: { name?: string } | null;
  model?: { name?: string; type?: string } | null;
  updated_at: string;
}

const loading = ref(true);
const devices = ref<DeviceRow[]>([]);
const selectedKeys = ref<number[]>([]);
function onSelectionChange(keys: number[]) {
  selectedKeys.value = keys;
}

// Print parameters
const withLabel = ref(false);
const withLogo = ref(true);
const size = ref(400);

const previewVisible = ref(false);

const filters = reactive({ ip: '', name: '', group: '', description: '', model: '', type: '' });

const filteredDevices = computed(() =>
  devices.value.filter(
    (d) =>
      (!filters.ip || d.ip.toLowerCase().includes(filters.ip.toLowerCase())) &&
      (!filters.name || d.name.toLowerCase().includes(filters.name.toLowerCase())) &&
      (!filters.group || (d.group?.name || '').toLowerCase().includes(filters.group.toLowerCase())) &&
      (!filters.description || d.description.toLowerCase().includes(filters.description.toLowerCase())) &&
      (!filters.model || (d.model?.name || '').toLowerCase().includes(filters.model.toLowerCase())) &&
      (!filters.type || (d.model?.type || '').toLowerCase().includes(filters.type.toLowerCase())),
  ),
);

const previewTargets = computed(() =>
  devices.value
    .filter((d) => selectedKeys.value.includes(d.id))
    .map((d) => ({ id: d.id, description: `${d.ip} - ${d.name}` })),
);

async function load() {
  loading.value = true;
  try {
    const { data } = await DataService.get('/device', { limit: 999999 });
    devices.value = data.data || [];
  } finally {
    loading.value = false;
  }
}
onMounted(load);
</script>

<template>
  <sdPageHeader :routes="[{ path: '/', breadcrumbName: 'Dashboard' }, { path: '', breadcrumbName: 'Printing QR for devices' }]" class="ninjadash-page-header-main" />
  <Main>
    <a-row :gutter="25">
      <a-col :span="24" style="margin-bottom: 25px">
        <sdCards title="Parameters">
          <a-row :gutter="16" align="bottom">
            <a-col :xs="12" :sm="6" :md="4">
              <label class="qr-field-label">With label</label>
              <div><a-switch v-model:checked="withLabel" /></div>
            </a-col>
            <a-col :xs="12" :sm="6" :md="4">
              <label class="qr-field-label">With logo</label>
              <div><a-switch v-model:checked="withLogo" /></div>
            </a-col>
            <a-col :xs="12" :sm="6" :md="4">
              <label class="qr-field-label">Size (px)</label>
              <a-input-number v-model:value="size" :min="60" :max="1000" style="width: 100%" />
            </a-col>
            <a-col :xs="24" :sm="6" :md="4">
              <sdButton type="primary" :disabled="!selectedKeys.length" @click="previewVisible = true">
                <unicon name="print"></unicon> Preview
              </sdButton>
            </a-col>
          </a-row>
        </sdCards>
      </a-col>
    </a-row>

    <a-row :gutter="25">
      <a-col :span="24">
        <sdCards :headless="true">
          <!-- Per-column "Filter by" row, matching the original app -->
          <div v-if="!loading" class="qr-filter-row">
            <a-row :gutter="16">
              <a-col :span="4"><a-input v-model:value="filters.ip" placeholder="Filter by IP" size="small" /></a-col>
              <a-col :span="4"><a-input v-model:value="filters.name" placeholder="Filter by name" size="small" /></a-col>
              <a-col :span="4"><a-input v-model:value="filters.group" placeholder="Filter by group" size="small" /></a-col>
              <a-col :span="4"><a-input v-model:value="filters.description" placeholder="Filter by description" size="small" /></a-col>
              <a-col :span="4"><a-input v-model:value="filters.model" placeholder="Filter by model" size="small" /></a-col>
              <a-col :span="4"><a-input v-model:value="filters.type" placeholder="Filter by type" size="small" /></a-col>
            </a-row>
          </div>
          <a-skeleton v-if="loading" active />
          <a-table
            v-else
            :data-source="filteredDevices"
            row-key="id"
            size="small"
            :scroll="{ x: 900 }"
            :row-selection="{ selectedRowKeys: selectedKeys, onChange: onSelectionChange }"
            :pagination="{ pageSize: 20, showTotal: (t: number) => `${t} record(s)` }"
          >
            <a-table-column title="Id" data-index="id" :width="70" :sorter="(a: DeviceRow, b: DeviceRow) => a.id - b.id" />
            <a-table-column title="IP" data-index="ip" :sorter="(a: DeviceRow, b: DeviceRow) => a.ip.localeCompare(b.ip)" />
            <a-table-column title="Name" data-index="name" :sorter="(a: DeviceRow, b: DeviceRow) => a.name.localeCompare(b.name)" />
            <a-table-column title="Groups">
              <template #default="{ record }">{{ record.group?.name || '—' }}</template>
            </a-table-column>
            <a-table-column title="Description" data-index="description" />
            <a-table-column title="Model">
              <template #default="{ record }">{{ record.model?.name || '—' }}</template>
            </a-table-column>
            <a-table-column title="Type">
              <template #default="{ record }">{{ record.model?.type || '—' }}</template>
            </a-table-column>
            <a-table-column title="Updated at" data-index="updated_at" :width="160" />
          </a-table>
        </sdCards>
      </a-col>
    </a-row>

    <QrPrintPreviewModal
      v-model:visible="previewVisible"
      type="device"
      :targets="previewTargets"
      :with-label="withLabel"
      :with-logo="withLogo"
      :size="size"
    />
  </Main>
</template>

<style scoped>
.qr-field-label {
  display: block;
  font-size: 12px;
  color: #8c90a4;
  margin-bottom: 6px;
}
.qr-filter-row {
  padding: 16px 16px 0;
}
</style>
