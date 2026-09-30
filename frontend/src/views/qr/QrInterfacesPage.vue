<script setup lang="ts">
import { ref, onMounted, computed, reactive } from 'vue';
import { DataService } from '@/config/dataService/dataService';
import { notification } from 'ant-design-vue';
import { Main } from '../styled';
import QrPrintPreviewModal from './QrPrintPreviewModal.vue';

interface DeviceOption {
  id: number;
  ip: string;
  name: string;
}
interface InterfaceRow {
  id: number;
  name: string;
  type: string;
  description: string;
  agreement: string;
  status: string;
}

const deviceOptions = ref<DeviceOption[]>([]);
const selectedDeviceId = ref<number | null>(null);
const devicesLoading = ref(true);

const interfaces = ref<InterfaceRow[]>([]);
const searched = ref(false);
const searching = ref(false);
const selectedKeys = ref<number[]>([]);
function onSelectionChange(keys: number[]) {
  selectedKeys.value = keys;
}

const withLabel = ref(false);
const withLogo = ref(true);
const size = ref(400);
const previewVisible = ref(false);

// Ant Design's built-in <a-tag color="green"> renders white text on a pale
// green background in this template (a pre-existing global CSS conflict) —
// unreadable. Use plain colored badges instead, same fix already applied on
// the dashboard's status column.
const statusClass: Record<string, string> = {
  Up: 'ok',
  Online: 'ok',
  Down: 'fail',
  LOS: 'fail',
  Offline: 'muted',
  PowerOff: 'muted',
};

const filters = reactive({ name: '', type: '', description: '', agreement: '', status: '' });

const filteredInterfaces = computed(() =>
  interfaces.value.filter(
    (i) =>
      (!filters.name || i.name.toLowerCase().includes(filters.name.toLowerCase())) &&
      (!filters.type || i.type.toLowerCase().includes(filters.type.toLowerCase())) &&
      (!filters.description || (i.description || '').toLowerCase().includes(filters.description.toLowerCase())) &&
      (!filters.agreement || (i.agreement || '').toLowerCase().includes(filters.agreement.toLowerCase())) &&
      (!filters.status || i.status.toLowerCase().includes(filters.status.toLowerCase())),
  ),
);

const previewTargets = computed(() =>
  interfaces.value
    .filter((i) => selectedKeys.value.includes(i.id))
    .map((i) => ({ id: i.id, description: i.description || i.name })),
);

async function loadDeviceOptions() {
  devicesLoading.value = true;
  try {
    const { data } = await DataService.get('/device/options');
    deviceOptions.value = data.data || [];
  } finally {
    devicesLoading.value = false;
  }
}

async function search() {
  if (!selectedDeviceId.value) {
    notification.error({ message: 'Select a device first' });
    return;
  }
  searching.value = true;
  selectedKeys.value = [];
  try {
    const { data } = await DataService.get('/device-interface', { device_id: selectedDeviceId.value });
    interfaces.value = data.data || [];
    searched.value = true;
  } finally {
    searching.value = false;
  }
}

onMounted(loadDeviceOptions);
</script>

<template>
  <sdPageHeader :routes="[{ path: '/', breadcrumbName: 'Dashboard' }, { path: '', breadcrumbName: 'Printing QR for interfaces' }]" class="ninjadash-page-header-main" />
  <Main>
    <a-row :gutter="25">
      <a-col :span="24" style="margin-bottom: 25px">
        <sdCards title="Parameters">
          <a-row :gutter="16" align="bottom">
            <a-col :xs="24" :sm="8" :md="6">
              <label class="qr-field-label">Device</label>
              <a-select
                v-model:value="selectedDeviceId"
                :loading="devicesLoading"
                show-search
                placeholder="Select a device"
                style="width: 100%"
                :filter-option="(input: string, option: any) => option.label.toLowerCase().includes(input.toLowerCase())"
                :options="deviceOptions.map((d) => ({ value: d.id, label: `${d.ip} - ${d.name}` }))"
              />
            </a-col>
            <a-col :xs="12" :sm="4" :md="3">
              <label class="qr-field-label">With label</label>
              <div><a-switch v-model:checked="withLabel" /></div>
            </a-col>
            <a-col :xs="12" :sm="4" :md="3">
              <label class="qr-field-label">With logo</label>
              <div><a-switch v-model:checked="withLogo" /></div>
            </a-col>
            <a-col :xs="12" :sm="4" :md="3">
              <label class="qr-field-label">Size (px)</label>
              <a-input-number v-model:value="size" :min="60" :max="1000" style="width: 100%" />
            </a-col>
            <a-col :xs="12" :sm="4" :md="3">
              <sdButton type="primary" :loading="searching" @click="search"><unicon name="search"></unicon> Search</sdButton>
            </a-col>
            <a-col :xs="24" :sm="4" :md="3">
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
          <div v-if="searched" class="qr-filter-row">
            <a-row :gutter="16">
              <a-col :span="5"><a-input v-model:value="filters.name" placeholder="Filter by name" size="small" /></a-col>
              <a-col :span="4"><a-input v-model:value="filters.type" placeholder="Filter by type" size="small" /></a-col>
              <a-col :span="5"><a-input v-model:value="filters.description" placeholder="Filter by description" size="small" /></a-col>
              <a-col :span="5"><a-input v-model:value="filters.agreement" placeholder="Filter by agreement" size="small" /></a-col>
              <a-col :span="5"><a-input v-model:value="filters.status" placeholder="Filter by status" size="small" /></a-col>
            </a-row>
          </div>
          <a-skeleton v-if="searching" active />
          <a-table
            v-else-if="searched"
            :data-source="filteredInterfaces"
            row-key="id"
            size="small"
            :scroll="{ x: 800 }"
            :row-selection="{ selectedRowKeys: selectedKeys, onChange: onSelectionChange }"
            :pagination="{ pageSize: 20, showTotal: (t: number) => `${t} record(s)` }"
          >
            <a-table-column title="Id" data-index="id" :width="70" :sorter="(a: InterfaceRow, b: InterfaceRow) => a.id - b.id" />
            <a-table-column title="Name" data-index="name" :sorter="(a: InterfaceRow, b: InterfaceRow) => a.name.localeCompare(b.name)" />
            <a-table-column title="Type" data-index="type" :width="100" />
            <a-table-column title="Description" data-index="description" />
            <a-table-column title="Agreement" data-index="agreement" />
            <a-table-column title="Status" :width="110">
              <template #default="{ record }">
                <span class="status-badge" :class="statusClass[record.status] || 'muted'">{{ record.status }}</span>
              </template>
            </a-table-column>
          </a-table>
          <a-empty v-else description="Select a device and press Search" />
        </sdCards>
      </a-col>
    </a-row>

    <QrPrintPreviewModal
      v-model:visible="previewVisible"
      type="interface"
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
.status-badge {
  display: inline-block;
  padding: 1px 10px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 700;
}
.status-badge.ok {
  background: rgba(38, 179, 87, 0.12);
  color: #1a9c50;
}
.status-badge.fail {
  background: rgba(255, 77, 79, 0.12);
  color: #e5484d;
}
.status-badge.muted {
  background: rgba(140, 144, 164, 0.15);
  color: #6b7086;
}
</style>
