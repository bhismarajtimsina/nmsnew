<script setup lang="ts">
import { ref, reactive, onMounted, onBeforeUnmount, computed } from 'vue';
import { DataService } from '@/config/dataService/dataService';
import { notification, Modal } from 'ant-design-vue';
import { wsClient } from '@/services/wsClient';
import { api } from '@/api/client';
import { authBackend } from '@/auth/session';
import { mergeById, removeById } from '@/utility/listMerge';
import {
  fromLegacyLink,
  legacySource,
  newApiSource,
  type Id,
  type LinkEnd,
  type LinkRow,
  type Option,
} from './linksList';
import { Main } from '../styled';

// With the new login the page reads and writes the new API (src/views/links/linksList.ts); legacy is unchanged.
const newApi = authBackend() === 'cybersathy';
const source = newApi ? newApiSource(api) : legacySource(DataService as any);

const loading = ref(true);
const rows = ref<LinkRow[]>([]);
const total = ref(0);
const page = ref(1);
const limit = ref(50);
const deviceOptions = ref<Option[]>([]);
const periodOptions = ref<string[]>(['15m']);
const periodSelectable = ref(true);

const columnFilters = reactive({ srcDevice: '', srcIface: '', destDevice: '', destIface: '' });

const filters = reactive({
  devices: [] as Id[],
  period: '15m',
  highUtilization: false,
});

async function loadOptions() {
  const [devRes, periodRes] = await Promise.allSettled([source.devices(), source.periods()]);
  if (devRes.status === 'fulfilled') deviceOptions.value = devRes.value;
  if (periodRes.status === 'fulfilled') {
    periodOptions.value = periodRes.value.options;
    filters.period = periodRes.value.current;
    periodSelectable.value = periodRes.value.selectable;
  }
}

async function load() {
  loading.value = true;
  try {
    const result = await source.list({ ...filters, page: page.value, limit: limit.value });
    rows.value = result.rows;
    total.value = result.total;
  } catch (err) {
    notification.error({ message: 'Could not load links', description: source.errorMessage(err) });
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

// A device outside the caller's scope is shown as such, never by name (new API only).
const deviceLabel = (e: LinkEnd) => (e.visible ? `${e.ip ?? ''} (${e.name ?? ''})` : 'Outside your scope');
const matches = (text: string | null, filter: string) =>
  !filter || (text || '').toLowerCase().includes(filter.toLowerCase());
const matchesDevice = (e: LinkEnd, filter: string) =>
  !filter || (e.visible && (matches(e.ip, filter) || matches(e.name, filter)));

const filteredRows = computed(() =>
  rows.value.filter(
    (r) =>
      matchesDevice(r.src, columnFilters.srcDevice) &&
      matches(r.src.interface, columnFilters.srcIface) &&
      matchesDevice(r.dest, columnFilters.destDevice) &&
      matches(r.dest.interface, columnFilters.destIface),
  ),
);

function exportCsv() {
  const header = [
    'Source device',
    'Source iface',
    'Destination device',
    'Dest iface',
    'Utilization %',
    'Utilization Mbps',
    'Speed',
    'Created at',
  ];
  const lines = filteredRows.value.map((r) =>
    [
      deviceLabel(r.src),
      r.src.interface || '',
      deviceLabel(r.dest),
      r.dest.interface || '',
      r.utilization ?? '',
      r.utilizationMbps ?? '',
      r.speed ?? '',
      r.createdAt,
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
  if (!link.editable) return;
  Modal.confirm({
    title: `Delete the link between ${link.src.ip} and ${link.dest.ip}?`,
    okText: 'Delete',
    okType: 'danger',
    onOk: async () => {
      try {
        await source.remove(link.id);
        rows.value = rows.value.filter((r) => r.id !== link.id);
        total.value = Math.max(0, total.value - 1);
        notification.success({ message: 'Link deleted' });
      } catch (err) {
        notification.error({ message: 'Could not delete link', description: source.errorMessage(err) });
      }
    },
  });
}

// --- Add link modal ---
const linkModalOpen = ref(false);
const linkSaving = ref(false);
const srcInterfaces = ref<{ id: Id; name: string }[]>([]);
const destInterfaces = ref<{ id: Id; name: string }[]>([]);
const linkForm = reactive({
  srcDevice: undefined as Id | undefined,
  destDevice: undefined as Id | undefined,
  srcIface: undefined as Id | undefined,
  destIface: undefined as Id | undefined,
});

function openLinkModal() {
  linkModalOpen.value = true;
  Object.assign(linkForm, { srcDevice: undefined, destDevice: undefined, srcIface: undefined, destIface: undefined });
  srcInterfaces.value = [];
  destInterfaces.value = [];
}
async function loadInterfacesFor(deviceId: Id) {
  try {
    return await source.interfaces(deviceId);
  } catch {
    return [];
  }
}
async function onSrcDeviceChange(id: Id) {
  linkForm.srcDevice = id;
  linkForm.srcIface = undefined;
  srcInterfaces.value = id ? await loadInterfacesFor(id) : [];
}
async function onDestDeviceChange(id: Id) {
  linkForm.destDevice = id;
  linkForm.destIface = undefined;
  destInterfaces.value = id ? await loadInterfacesFor(id) : [];
}
async function submitLink() {
  if (!linkForm.srcDevice || !linkForm.destDevice) {
    notification.error({ message: 'Choose both a source and a target device' });
    return;
  }
  linkSaving.value = true;
  try {
    await source.create({
      srcDevice: linkForm.srcDevice,
      destDevice: linkForm.destDevice,
      srcIface: linkForm.srcIface,
      destIface: linkForm.destIface,
    });
    notification.success({ message: 'Link created' });
    linkModalOpen.value = false;
    load();
  } catch (err) {
    notification.error({ message: 'Could not create link', description: source.errorMessage(err) });
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

const stateClass = (state: LinkRow['state']) =>
  state === 'up' ? 'is-success' : state === 'down' ? 'is-failed' : 'is-unknown';

onMounted(async () => {
  await loadOptions();
  search();
});

// Real-time, legacy only: LinkStorage's generic table-scoped signal (c_links). The list is paginated and filtered
// server-side, so 'added' reloads rather than guessing where a new link belongs; 'updated' only refreshes a row
// already on this page. The new API publishes no link notices yet, so the page reloads after its own changes.
const noop = () => {};
const unsubAdded = newApi ? noop : wsClient.subscribe('event:storage:c_links:added', () => load());
const unsubUpdated = newApi
  ? noop
  : wsClient.subscribe('event:storage:c_links:updated', (msg) => {
      if (rows.value.some((r) => r.id === msg.data.id)) mergeById(rows, fromLegacyLink(msg.data));
    });
const unsubDeleted = newApi
  ? noop
  : wsClient.subscribe('event:storage:c_links:deleted', (msg) => removeById(rows, msg.data.id));
onBeforeUnmount(() => {
  unsubAdded();
  unsubUpdated();
  unsubDeleted();
});
</script>

<template>
  <sdPageHeader
    :routes="[
      { path: '/', breadcrumbName: 'Dashboard' },
      { path: '', breadcrumbName: 'Links' },
    ]"
    class="ninjadash-page-header-main"
  >
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
              <a-select
                v-model:value="filters.period"
                style="width: 100%"
                :disabled="!periodSelectable"
                :options="periodOptions.map((p) => ({ value: p, label: p }))"
              />
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
            :pagination="{
              current: page,
              pageSize: limit,
              total,
              showSizeChanger: true,
              pageSizeOptions: ['20', '50', '100', '200'],
            }"
            @change="onTableChange"
          >
            <a-table-column title="Source device" :width="190">
              <template #default="{ record }">
                <template v-if="record.src.visible">
                  <router-link :to="{ name: 'device-detail', params: { id: record.src.deviceId } }">{{
                    record.src.ip
                  }}</router-link>
                  <div class="log-subtext">{{ record.src.name }}</div>
                </template>
                <span v-else class="log-subtext">Outside your scope</span>
              </template>
            </a-table-column>
            <a-table-column title="Source iface" :width="140">
              <template #default="{ record }">{{ record.src.interface || '—' }}</template>
            </a-table-column>
            <a-table-column title="Destination device" :width="190">
              <template #default="{ record }">
                <template v-if="record.dest.visible">
                  <router-link :to="{ name: 'device-detail', params: { id: record.dest.deviceId } }">{{
                    record.dest.ip
                  }}</router-link>
                  <div class="log-subtext">{{ record.dest.name }}</div>
                </template>
                <span v-else class="log-subtext">Outside your scope</span>
              </template>
            </a-table-column>
            <a-table-column title="Dest iface" :width="140">
              <template #default="{ record }">{{ record.dest.interface || '—' }}</template>
            </a-table-column>
            <a-table-column v-if="newApi" title="State" :width="90">
              <template #default="{ record }">
                <span class="status-tag" :class="stateClass(record.state)">{{ record.state }}</span>
              </template>
            </a-table-column>
            <a-table-column title="Utilization %" :width="130">
              <template #default="{ record }">
                <span v-if="record.utilization != null" class="status-tag" :class="utilClass(record.utilization)"
                  >{{ record.utilization }}%</span
                >
                <span v-else>—</span>
              </template>
            </a-table-column>
            <a-table-column title="Utilization Mbps" :width="140">
              <template #default="{ record }">{{ record.utilizationMbps ?? '—' }}</template>
            </a-table-column>
            <a-table-column title="Speed" :width="100">
              <template #default="{ record }">{{ record.speed ?? '—' }}</template>
            </a-table-column>
            <a-table-column title="Created at" data-index="createdAt" :width="160" />
            <a-table-column title="" :width="60">
              <template #default="{ record }">
                <a v-if="record.editable" class="link-delete" title="Delete link" @click="confirmDeleteLink(record)"
                  ><unicon name="trash-alt"></unicon
                ></a>
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
        <a-select
          v-model:value="linkForm.srcIface"
          allow-clear
          style="width: 100%"
          :options="srcInterfaces.map((i) => ({ value: i.id, label: i.name }))"
        />
      </div>
      <div class="link-form-row">
        <label>Destination device</label>
        <a-select
          :value="linkForm.destDevice"
          allow-clear
          show-search
          style="width: 100%"
          :filter-option="(input: string, opt: any) => opt.label.toLowerCase().includes(input.toLowerCase())"
          :options="
            deviceOptions
              .filter((d) => d.id !== linkForm.srcDevice)
              .map((d) => ({ value: d.id, label: `${d.ip} (${d.name})` }))
          "
          @change="onDestDeviceChange"
        />
      </div>
      <div class="link-form-row">
        <label>Destination interface (optional)</label>
        <a-select
          v-model:value="linkForm.destIface"
          allow-clear
          style="width: 100%"
          :options="destInterfaces.map((i) => ({ value: i.id, label: i.name }))"
        />
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
.status-tag.is-unknown {
  background: #8c90a4;
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
