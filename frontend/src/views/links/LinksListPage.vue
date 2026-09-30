<script setup lang="ts">
import { ref, reactive, onMounted, onBeforeUnmount, computed } from 'vue';
import { DataService } from '@/config/dataService/dataService';
import { notification, Modal } from 'ant-design-vue';
import { wsClient } from '@/services/wsClient';
import { mergeById, removeById } from '@/utility/listMerge';
import { Main } from '../styled';

interface IfaceLite {
  id: number;
  name: string;
  status?: string;
}
interface LinkRow {
  id: number;
  src_device: { id: number; ip: string; name: string };
  dest_device: { id: number; ip: string; name: string };
  src_iface: IfaceLite | null;
  dest_iface: IfaceLite | null;
  utilization: number | null;
  utilization_mbps: number | null;
  speed: number | null;
  created_at: string;
}

const loading = ref(true);
const rows = ref<LinkRow[]>([]);
const total = ref(0);
const page = ref(1);
const limit = ref(50);
const deviceOptions = ref<{ id: number; name: string; ip: string }[]>([]);
const periodOptions = ref<string[]>(['15m']);

const columnFilters = reactive({ srcDevice: '', srcIface: '', destDevice: '', destIface: '' });

const filters = reactive({
  devices: [] as number[],
  period: '15m',
  highUtilization: false,
});

async function loadOptions() {
  const [devRes, cfgRes] = await Promise.allSettled([DataService.get('/device/options'), DataService.get('/component/links/options/configuration')]);
  if (devRes.status === 'fulfilled') deviceOptions.value = (devRes.value.data.data || []).map((d: any) => ({ id: d.id, name: d.name, ip: d.ip }));
  if (cfgRes.status === 'fulfilled') {
    periodOptions.value = cfgRes.value.data.data?.periods || ['15m'];
    filters.period = cfgRes.value.data.data?.calc_util_period || filters.period;
  }
}

async function load() {
  loading.value = true;
  try {
    const { data } = await DataService.put('/component/links/view/list', {
      query: {},
      limit: limit.value,
      page: page.value,
      ascending: 0,
      byColumn: 1,
      filter: { devices: filters.devices.map((id) => ({ id })), period: filters.period, high_utilization: filters.highUtilization },
    });
    rows.value = data.data || [];
    total.value = data.meta?.total ?? data.meta?.total_records ?? rows.value.length;
  } catch (err: any) {
    notification.error({ message: 'Could not load links', description: err?.response?.data?.error?.description || 'Please try again.' });
  } finally {
    loading.value = false;
  }
}

function search() {
  page.value = 1;
  load();
}
function onTableChange(pagination: any) {
  page.value = pagination.current;
  limit.value = pagination.pageSize;
  load();
}

const filteredRows = computed(() =>
  rows.value.filter(
    (r) =>
      (!columnFilters.srcDevice || r.src_device.ip.includes(columnFilters.srcDevice) || r.src_device.name.toLowerCase().includes(columnFilters.srcDevice.toLowerCase())) &&
      (!columnFilters.srcIface || (r.src_iface?.name || '').toLowerCase().includes(columnFilters.srcIface.toLowerCase())) &&
      (!columnFilters.destDevice || r.dest_device.ip.includes(columnFilters.destDevice) || r.dest_device.name.toLowerCase().includes(columnFilters.destDevice.toLowerCase())) &&
      (!columnFilters.destIface || (r.dest_iface?.name || '').toLowerCase().includes(columnFilters.destIface.toLowerCase())),
  ),
);

function exportCsv() {
  const header = ['Source device', 'Source iface', 'Destination device', 'Dest iface', 'Utilization %', 'Utilization Mbps', 'Speed', 'Created at'];
  const lines = filteredRows.value.map((r) =>
    [
      `${r.src_device.ip} (${r.src_device.name})`,
      r.src_iface?.name || '',
      `${r.dest_device.ip} (${r.dest_device.name})`,
      r.dest_iface?.name || '',
      r.utilization ?? '',
      r.utilization_mbps ?? '',
      r.speed ?? '',
      r.created_at,
    ]
      .map((v) => `"${String(v).replace(/"/g, '""')}"`)
      .join(','),
  );
  const blob = new Blob([[header.join(','), ...lines].join('\n')], { type: 'text/csv' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = 'links.csv';
  a.click();
  URL.revokeObjectURL(url);
}

function confirmDeleteLink(link: LinkRow) {
  Modal.confirm({
    title: `Delete the link between ${link.src_device.ip} and ${link.dest_device.ip}?`,
    okText: 'Delete',
    okType: 'danger',
    onOk: async () => {
      try {
        await DataService.delete(`/component/links/${link.id}`);
        rows.value = rows.value.filter((r) => r.id !== link.id);
        notification.success({ message: 'Link deleted' });
      } catch (err: any) {
        notification.error({ message: 'Could not delete link', description: err?.response?.data?.error?.description || 'Please try again.' });
      }
    },
  });
}

// --- Add link modal ---
const linkModalOpen = ref(false);
const linkSaving = ref(false);
const srcInterfaces = ref<{ id: number; name: string }[]>([]);
const destInterfaces = ref<{ id: number; name: string }[]>([]);
const linkForm = reactive({
  srcDevice: undefined as number | undefined,
  destDevice: undefined as number | undefined,
  srcIface: undefined as number | undefined,
  destIface: undefined as number | undefined,
});

function openLinkModal() {
  linkModalOpen.value = true;
  Object.assign(linkForm, { srcDevice: undefined, destDevice: undefined, srcIface: undefined, destIface: undefined });
  srcInterfaces.value = [];
  destInterfaces.value = [];
}
async function loadInterfacesFor(deviceId: number) {
  try {
    const { data } = await DataService.get('/device-interface', { device_id: deviceId, limit: 999999 });
    return (data.data || []).map((i: any) => ({ id: i.id, name: i.name }));
  } catch {
    return [];
  }
}
async function onSrcDeviceChange(id: number) {
  linkForm.srcDevice = id;
  linkForm.srcIface = undefined;
  srcInterfaces.value = await loadInterfacesFor(id);
}
async function onDestDeviceChange(id: number) {
  linkForm.destDevice = id;
  linkForm.destIface = undefined;
  destInterfaces.value = await loadInterfacesFor(id);
}
async function submitLink() {
  if (!linkForm.srcDevice || !linkForm.destDevice) {
    notification.error({ message: 'Choose both a source and a target device' });
    return;
  }
  linkSaving.value = true;
  try {
    await DataService.post('/component/links', {
      src_device: { id: linkForm.srcDevice },
      dest_device: { id: linkForm.destDevice },
      src_iface: linkForm.srcIface ? { id: linkForm.srcIface } : undefined,
      dest_iface: linkForm.destIface ? { id: linkForm.destIface } : undefined,
    });
    notification.success({ message: 'Link created' });
    linkModalOpen.value = false;
    load();
  } catch (err: any) {
    notification.error({ message: 'Could not create link', description: err?.response?.data?.error?.description || 'Please try again.' });
  } finally {
    linkSaving.value = false;
  }
}

function utilClass(u: number | null) {
  if (u == null) return '';
  if (u >= 85) return 'is-failed';
  if (u >= 60) return 'is-warn';
  return 'is-success';
}

onMounted(async () => {
  await loadOptions();
  search();
});

// Real-time: LinkStorage's generic table-scoped signal (c_links) — no
// richer named event of its own the way devices have. Verified
// AddAction/UpdateAction both resolve src_device/dest_device/src_iface/
// dest_iface via real storage lookups (not bare id-only stubs), so the
// pushed record's nested objects are fully formed.
//
// This list is paginated AND filtered server-side (see load()'s
// limit/page/filter), unlike the devices list above — a newly added link
// could sort anywhere and doesn't necessarily belong on the page currently
// being viewed, so 'added' always reloads for real rather than guessing.
// 'updated' only touches a row already present on this page (found by id),
// which is safe: it's already correctly positioned here, and just
// refreshing its own fields doesn't change that. A link not currently
// shown updating elsewhere is deliberately left alone rather than
// speculatively inserted.
const unsubAdded = wsClient.subscribe('event:storage:c_links:added', () => load());
const unsubUpdated = wsClient.subscribe('event:storage:c_links:updated', (msg) => {
  if (rows.value.some((r) => r.id === msg.data.id)) mergeById(rows, msg.data);
});
const unsubDeleted = wsClient.subscribe('event:storage:c_links:deleted', (msg) => removeById(rows, msg.data.id));
onBeforeUnmount(() => {
  unsubAdded();
  unsubUpdated();
  unsubDeleted();
});
</script>

<template>
  <sdPageHeader :routes="[{ path: '/', breadcrumbName: 'Dashboard' }, { path: '', breadcrumbName: 'Links' }]" class="ninjadash-page-header-main">
    <template #buttons>
      <sdButton type="primary" @click="openLinkModal"><unicon name="plus"></unicon> Add link</sdButton>
    </template>
  </sdPageHeader>
  <Main>
    <a-row :gutter="25">
      <a-col :span="24">
        <sdCards title="Filters" style="margin-bottom: 16px">
          <a-row :gutter="16">
            <a-col :xs="24" :md="12" style="margin-bottom: 12px">
              <label class="log-filter-label">Devices</label>
              <a-select
                v-model:value="filters.devices"
                mode="multiple"
                allow-clear
                placeholder="All devices"
                style="width: 100%"
                :filter-option="(input: string, opt: any) => opt.label.toLowerCase().includes(input.toLowerCase())"
                :options="deviceOptions.map((d) => ({ value: d.id, label: `${d.ip} (${d.name})` }))"
              />
            </a-col>
            <a-col :xs="12" :md="5" style="margin-bottom: 12px">
              <label class="log-filter-label">Time period</label>
              <a-select v-model:value="filters.period" style="width: 100%" :options="periodOptions.map((p) => ({ value: p, label: p }))" />
            </a-col>
            <a-col :xs="12" :md="4" style="margin-bottom: 12px">
              <label class="log-filter-label">Only high utilization</label>
              <div><a-switch v-model:checked="filters.highUtilization" /></div>
            </a-col>
            <a-col :xs="24" :md="3" style="margin-bottom: 12px; display: flex; align-items: flex-end; gap: 8px">
              <sdButton type="primary" block @click="search"><unicon name="search"></unicon></sdButton>
            </a-col>
          </a-row>
          <div class="log-filter-actions">
            <sdButton type="primary" @click="exportCsv"><unicon name="download-alt"></unicon> Export to excel</sdButton>
          </div>
        </sdCards>

        <sdCards :headless="true">
          <div class="col-filter-row">
            <a-input v-model:value="columnFilters.srcDevice" placeholder="Filter by source device" />
            <a-input v-model:value="columnFilters.srcIface" placeholder="Filter by source iface" />
            <a-input v-model:value="columnFilters.destDevice" placeholder="Filter by destination device" />
            <a-input v-model:value="columnFilters.destIface" placeholder="Filter by dest iface" />
          </div>
          <a-table
            :data-source="filteredRows"
            :loading="loading"
            row-key="id"
            size="small"
            :scroll="{ x: 1000 }"
            :pagination="{ current: page, pageSize: limit, total, showSizeChanger: true, pageSizeOptions: ['20', '50', '100', '200'] }"
            @change="onTableChange"
          >
            <a-table-column title="Source device" :width="190">
              <template #default="{ record }">
                <router-link :to="{ name: 'device-detail', params: { id: record.src_device.id } }">{{ record.src_device.ip }}</router-link>
                <div class="log-subtext">{{ record.src_device.name }}</div>
              </template>
            </a-table-column>
            <a-table-column title="Source iface" :width="140">
              <template #default="{ record }">{{ record.src_iface?.name || '—' }}</template>
            </a-table-column>
            <a-table-column title="Destination device" :width="190">
              <template #default="{ record }">
                <router-link :to="{ name: 'device-detail', params: { id: record.dest_device.id } }">{{ record.dest_device.ip }}</router-link>
                <div class="log-subtext">{{ record.dest_device.name }}</div>
              </template>
            </a-table-column>
            <a-table-column title="Dest iface" :width="140">
              <template #default="{ record }">{{ record.dest_iface?.name || '—' }}</template>
            </a-table-column>
            <a-table-column title="Utilization %" :width="130">
              <template #default="{ record }">
                <span v-if="record.utilization != null" class="status-tag" :class="utilClass(record.utilization)">{{ record.utilization }}%</span>
                <span v-else>—</span>
              </template>
            </a-table-column>
            <a-table-column title="Utilization Mbps" :width="140">
              <template #default="{ record }">{{ record.utilization_mbps ?? '—' }}</template>
            </a-table-column>
            <a-table-column title="Speed" :width="100">
              <template #default="{ record }">{{ record.speed ?? '—' }}</template>
            </a-table-column>
            <a-table-column title="Created at" data-index="created_at" :width="160" />
            <a-table-column title="" :width="60">
              <template #default="{ record }">
                <a class="link-delete" title="Delete link" @click="confirmDeleteLink(record)"><unicon name="trash-alt"></unicon></a>
              </template>
            </a-table-column>
          </a-table>
        </sdCards>
      </a-col>
    </a-row>

    <a-modal v-model:visible="linkModalOpen" title="Add link" width="480px">
      <div class="link-form-row">
        <label>Source device</label>
        <a-select
          :value="linkForm.srcDevice"
          allow-clear
          show-search
          style="width: 100%"
          :filter-option="(input: string, opt: any) => opt.label.toLowerCase().includes(input.toLowerCase())"
          :options="deviceOptions.map((d) => ({ value: d.id, label: `${d.ip} (${d.name})` }))"
          @change="onSrcDeviceChange"
        />
      </div>
      <div class="link-form-row">
        <label>Source interface (optional)</label>
        <a-select v-model:value="linkForm.srcIface" allow-clear style="width: 100%" :options="srcInterfaces.map((i) => ({ value: i.id, label: i.name }))" />
      </div>
      <div class="link-form-row">
        <label>Destination device</label>
        <a-select
          :value="linkForm.destDevice"
          allow-clear
          show-search
          style="width: 100%"
          :filter-option="(input: string, opt: any) => opt.label.toLowerCase().includes(input.toLowerCase())"
          :options="deviceOptions.filter((d) => d.id !== linkForm.srcDevice).map((d) => ({ value: d.id, label: `${d.ip} (${d.name})` }))"
          @change="onDestDeviceChange"
        />
      </div>
      <div class="link-form-row">
        <label>Destination interface (optional)</label>
        <a-select v-model:value="linkForm.destIface" allow-clear style="width: 100%" :options="destInterfaces.map((i) => ({ value: i.id, label: i.name }))" />
      </div>
      <template #footer>
        <sdButton type="light" @click="linkModalOpen = false">Close</sdButton>
        <sdButton type="primary" :loading="linkSaving" @click="submitLink">Save</sdButton>
      </template>
    </a-modal>
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
.log-subtext {
  font-size: 11px;
  color: #8c90a4;
}
.col-filter-row {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  padding: 16px 16px 0;
}
.col-filter-row :deep(.ant-input) {
  max-width: 220px;
}
.status-tag {
  display: inline-block;
  padding: 2px 10px;
  border-radius: 4px;
  font-size: 12px;
  font-weight: 700;
  color: #fff;
}
.status-tag.is-success {
  background: #1a7a3a;
}
.status-tag.is-warn {
  background: #ab7d0a;
}
.status-tag.is-failed {
  background: #a60a0a;
}
.link-delete {
  color: #8c90a4;
}
.link-delete:hover {
  color: #e5484d;
}
.link-form-row {
  margin-bottom: 14px;
}
.link-form-row label {
  display: block;
  font-size: 12px;
  font-weight: 600;
  color: #5a5f7d;
  margin-bottom: 6px;
}
</style>
