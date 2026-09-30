<script setup lang="ts">
import { ref, reactive, onMounted, onBeforeUnmount } from 'vue';
import { DataService } from '@/config/dataService/dataService';
import { notification } from 'ant-design-vue';
import { Main } from '../styled';
import { wsClient } from '@/services/wsClient';
import { debounce } from '@/utility/debounce';

interface IfaceLite {
  id: number;
  name: string;
  device?: { id: number; name: string; ip: string };
}
interface DupRow {
  first: string;
  last: string;
  mac_address: string;
  vlan_id: string | number;
  count_duplicates: number;
  ifaces: IfaceLite[];
}

const loading = ref(true);
const rows = ref<DupRow[]>([]);
const groupOptions = ref<{ id: number; name: string }[]>([]);

const filters = reactive({ groups: [] as number[], mac: '' });

async function loadGroups() {
  const { data } = await DataService.get('/device-group');
  groupOptions.value = data.data || [];
}

async function load() {
  loading.value = true;
  try {
    const { data } = await DataService.put('/component/analytics/table/duplicated-mac-addresses', {
      device_groups: filters.groups.map((id) => ({ id })),
      mac_address: filters.mac,
    });
    rows.value = data.data || [];
  } catch (err: any) {
    notification.error({ message: 'Could not load duplicated MACs', description: err?.response?.data?.error?.description || 'Please try again.' });
  } finally {
    loading.value = false;
  }
}

function exportCsv() {
  const header = ['MAC address', 'VLAN', 'Duplicates', 'First seen', 'Last seen', 'Interfaces'];
  const lines = rows.value.map((r) =>
    [r.mac_address, r.vlan_id, r.count_duplicates, r.first, r.last, r.ifaces.map((i) => `${i.device?.ip || ''}/${i.name}`).join('; ')]
      .map((v) => `"${String(v).replace(/"/g, '""')}"`)
      .join(','),
  );
  const blob = new Blob([[header.join(','), ...lines].join('\n')], { type: 'text/csv' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = 'duplicated-macs.csv';
  a.click();
  URL.revokeObjectURL(url);
}

onMounted(async () => {
  await loadGroups();
  load();
});

// Real-time: fleet-wide analytics table — debounced since many devices'
// pollers can finish close together.
const reloadDebounced = debounce(() => load(), 3000);
const unsubPoller = wsClient.subscribe('event:poller:finished', () => reloadDebounced.call());
onBeforeUnmount(() => {
  reloadDebounced.cancel();
  unsubPoller();
});
</script>

<template>
  <sdPageHeader :routes="[{ path: '/', breadcrumbName: 'Dashboard' }, { path: '', breadcrumbName: 'Duplicated MACs' }]" class="ninjadash-page-header-main" />
  <Main>
    <a-row :gutter="25">
      <a-col :span="24">
        <sdCards title="Filters" style="margin-bottom: 16px">
          <a-row :gutter="16">
            <a-col :xs="24" :md="10" style="margin-bottom: 12px">
              <label class="log-filter-label">Device groups</label>
              <a-select v-model:value="filters.groups" mode="multiple" allow-clear placeholder="All groups" style="width: 100%" :options="groupOptions.map((g) => ({ value: g.id, label: g.name }))" />
            </a-col>
            <a-col :xs="24" :md="10" style="margin-bottom: 12px">
              <label class="log-filter-label">Duplicated MAC</label>
              <a-input v-model:value="filters.mac" placeholder="aa:bb:cc:dd:ee:ff" />
            </a-col>
            <a-col :xs="24" :md="4" style="margin-bottom: 12px; display: flex; align-items: flex-end; gap: 8px">
              <sdButton type="primary" block @click="load"><unicon name="search"></unicon></sdButton>
            </a-col>
          </a-row>
          <div class="log-filter-actions">
            <sdButton type="primary" @click="exportCsv"><unicon name="download-alt"></unicon> Export to excel</sdButton>
          </div>
        </sdCards>

        <sdCards :headless="true">
          <a-table :data-source="rows" :loading="loading" row-key="mac_address" size="small" :scroll="{ x: 700 }" :pagination="{ pageSize: 50 }">
            <a-table-column title="MAC address" data-index="mac_address" :width="170" />
            <a-table-column title="VLAN" data-index="vlan_id" :width="90" />
            <a-table-column title="Duplicates" data-index="count_duplicates" :width="110" />
            <a-table-column title="Interfaces">
              <template #default="{ record }">
                <div v-for="i in record.ifaces" :key="i.id" class="iface-line">
                  <router-link v-if="i.device" :to="{ name: 'device-detail', params: { id: i.device.id } }">{{ i.device.ip }}</router-link>
                  <span> — {{ i.name }}</span>
                </div>
              </template>
            </a-table-column>
            <a-table-column title="First seen" data-index="first" :width="160" />
            <a-table-column title="Last seen" data-index="last" :width="160" />
          </a-table>
        </sdCards>
      </a-col>
    </a-row>
  </Main>
</template>

<style scoped>
.log-filter-label {
  display: block;
  font-size: 12px;
  font-weight: 600;
  color: #5a5f7d;
  margin-bottom: 6px;
}
.log-filter-actions {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  margin-top: 8px;
}
.iface-line {
  font-size: 12px;
}
</style>
