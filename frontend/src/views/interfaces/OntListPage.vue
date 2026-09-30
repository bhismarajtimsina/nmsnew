<script setup lang="ts">
import { ref, reactive, computed, onMounted, onBeforeUnmount, watch } from 'vue';
import { useRouter } from 'vue-router';
import { DataService } from '@/config/dataService/dataService';
import { notification } from 'ant-design-vue';
import { wsClient } from '@/services/wsClient';
import { debounce } from '@/utility/debounce';
import { Main } from '../styled';

interface OntRow {
  ident: string;
  vendor: string | null;
  type: string;
  optical: {
    rx: number | null;
    tx: number | null;
    olt_rx: number | null;
    distance: number | null;
    bad_rx: string | null;
    bad_olt_rx: string | null;
  };
  interface: {
    id: number;
    name: string;
    status: string;
    status_changed: string;
    bind_key: string;
    description: string;
    agreement: string;
    created?: string;
    device: { id: number; name: string; ip: string };
  };
}

// Real dotted paths the backend's generic filter/sort mechanism resolves
// values from (confirmed against GetOntList.php's `isAllowedByFilter` /
// getArrayElementByKey) — picking one here drives a genuine server-side
// filter via `expression`+`value`, not a client-only guess.
const FIELD_OPTIONS = [
  { value: 'any', label: 'Any' },
  { value: 'ident', label: 'Ident' },
  { value: 'interface.name', label: 'Interface name' },
  { value: 'interface.description', label: 'Description' },
  { value: 'interface.status', label: 'Status' },
  { value: 'interface.device.ip', label: 'Device IP' },
  { value: 'interface.device.name', label: 'Device name' },
  { value: 'optical.rx', label: 'ONU RX' },
  { value: 'optical.tx', label: 'ONU TX' },
  { value: 'optical.olt_rx', label: 'Optical OLT RX' },
  { value: 'optical.distance', label: 'Distance' },
];
const EXPRESSION_OPTIONS = [
  { value: '~', label: 'Contains' },
  { value: '=', label: 'Equals' },
  { value: '!=', label: 'Not equals' },
  { value: '>', label: 'Greater than' },
  { value: '<', label: 'Less than' },
  { value: '>=', label: 'Greater or equal' },
  { value: '<=', label: 'Less or equal' },
];

const router = useRouter();
const loading = ref(true);
const rows = ref<OntRow[]>([]);
const total = ref(0);
const page = ref(1);
const limit = ref(50);
const deviceOptions = ref<{ id: number; display_name: string }[]>([]);
const interfaceOptions = ref<{ id: number; name: string }[]>([]);
const sortField = ref<string | null>(null);
const sortAscending = ref(false);

const columnFilters = reactive({ device: '', interface: '', ident: '', description: '', mac: '', status: undefined as string | undefined });

const filters = reactive({
  devices: [] as number[],
  interfaceName: undefined as string | undefined,
  field: 'any',
  expression: '~',
  value: '',
  ontStatus: 'All',
  badRx: false,
  badOltRx: false,
});

async function loadOptions() {
  const { data } = await DataService.get('/device/options', { type: 'OLT' });
  deviceOptions.value = data.data || [];
}

// The Interface picker only makes sense once the search is scoped to one
// device — with all devices in play there could be thousands of interface
// names to choose from, so it stays empty (and disabled) until then.
watch(
  () => filters.devices.slice(),
  async (devices) => {
    interfaceOptions.value = [];
    filters.interfaceName = undefined;
    if (devices.length !== 1) return;
    try {
      const { data } = await DataService.get('/device-interface', { device_id: devices[0], type: 'ONU', limit: 999999 });
      interfaceOptions.value = (data.data || []).map((i: any) => ({ id: i.id, name: i.name }));
    } catch {
      interfaceOptions.value = [];
    }
  },
);
function onInterfacePicked(name: string | undefined) {
  if (!name) return;
  filters.field = 'interface.name';
  filters.expression = '=';
  filters.value = name;
}

// "Bad Optical RX" / "Bad Optical OLT RX" are two independent toggles for
// the two real events the backend tracks separately (bad_optical_level_rx /
// bad_optical_level_olt_rx event names, confirmed in GetOntList.php). The
// backend's filter only has one field+expression+value slot though, so:
//  - neither switch on -> use the manual Field/Expression/Value filter as-is
//  - either switch on  -> filter that one optical field for "is set" —
//    bad_rx/bad_olt_rx hold a timestamp string when bad, null otherwise, and
//    `~` "-" matches any date string (they all contain dashes) but never a
//    null/empty value, confirmed live (61 / 823 real matching records)
// The combined `bad_signals` flag (used by the dashboard widget and by
// this filter when both switches are on) means bad_rx ONLY now — the OLT's
// rx of an ONU's upstream signal doesn't count as the ONT itself having a
// "bad signal", per explicit direction — so "both switches on" no longer
// unions the two; it just matches whatever bad_signals now means (bad_rx).
// "Bad Optical OLT RX" alone still works as its own direct field filter.
function buildAdvancedFilter() {
  if (filters.badRx && filters.badOltRx) return { field: 'any', expression: '~', value: null, bad_signals: true };
  if (filters.badRx) return { field: 'optical.bad_rx', expression: '~', value: '-', bad_signals: false };
  if (filters.badOltRx) return { field: 'optical.bad_olt_rx', expression: '~', value: '-', bad_signals: false };
  return { field: filters.field, expression: filters.expression, value: filters.value || null, bad_signals: false };
}

async function load() {
  loading.value = true;
  try {
    const { data } = await DataService.put('/component/analytics/table/ont-list', {
      query: {},
      limit: limit.value,
      page: page.value,
      ascending: sortAscending.value ? 1 : 0,
      byColumn: 1,
      orderBy: sortField.value || undefined,
      filter: {
        devices: filters.devices.map((id) => ({ id })),
        ...buildAdvancedFilter(),
        ont_status: columnFilters.status || filters.ontStatus,
      },
    });
    rows.value = data.data || [];
    total.value = data.meta?.total_records ?? rows.value.length;
    await loadDeviceMacs();
  } catch (err: any) {
    notification.error({ message: 'Could not load ONT list', description: err?.response?.data?.error?.description || 'Please try again.' });
  } finally {
    loading.value = false;
  }
}

// The ONT list has no server-side link from an ONU to the customer
// device's MAC address seen on it, so this pulls it from the FDB history
// instead — `GET /component/fdb_history/{device}?active=yes` returns every
// currently-active FDB entry for the whole device in one call (a DB query,
// not a live SNMP one), keyed by the interface's stored DB id, which is
// the same id the ONT list's own `interface.id` already is — fetched once
// per referenced device and cached for the page's lifetime.
const deviceMacByDevice = reactive<Record<number, Record<number, string | null>>>({});
async function loadDeviceMacs() {
  const deviceIds = [...new Set(rows.value.map((r) => r.interface.device.id))].filter((id) => !deviceMacByDevice[id]);
  await Promise.all(
    deviceIds.map(async (id) => {
      try {
        const { data } = await DataService.get(`/component/fdb_history/${id}`, { active: 'yes' });
        const map: Record<number, string | null> = {};
        (data.data || []).forEach((f: any) => {
          if (!map[f.interface.id]) map[f.interface.id] = f.mac_address;
        });
        deviceMacByDevice[id] = map;
      } catch {
        deviceMacByDevice[id] = {};
      }
    }),
  );
}
function deviceMac(row: OntRow) {
  return deviceMacByDevice[row.interface.device.id]?.[row.interface.id] ?? null;
}

// The MAC comes from a client-side FDB join (see loadDeviceMacs above), not
// from the ont-list dataset itself, so it isn't one of the backend's own
// filterable fields (FIELD_OPTIONS) and can't drive a real server-side
// search — this filters only the rows already loaded on the current page,
// same as the other quick column filters below it.
const visibleRows = computed(() => {
  const q = columnFilters.mac.trim().toLowerCase();
  if (!q) return rows.value;
  return rows.value.filter((r) => (deviceMac(r) || '').toLowerCase().includes(q));
});

function search() {
  page.value = 1;
  load();
}
function onTableChange(pagination: any, _filters: any, sorter: any) {
  page.value = pagination.current;
  limit.value = pagination.pageSize;
  if (sorter && sorter.order) {
    sortField.value = sorter.field;
    sortAscending.value = sorter.order === 'ascend';
  } else {
    sortField.value = null;
  }
  load();
}

function goToDevice(id: number) {
  router.push({ name: 'device-detail', params: { id } });
}

function statusClass(status: string) {
  if (['Online', 'Up'].includes(status)) return 'is-success';
  if (status === 'LOS') return 'is-los';
  if (status === 'PowerOff') return 'is-poweroff';
  return 'is-failed';
}
// A real power reading gets a green/red dot depending on whether it's
// currently flagged as a bad signal (the same `bad_rx`/`bad_olt_rx` events
// the backend already tracks) — grey when there's no reading at all yet.
function opticalClass(value: number | null, bad: string | null) {
  if (value == null) return 'is-muted';
  return bad ? 'is-bad' : 'is-good';
}

onMounted(async () => {
  await loadOptions();
  search();
});

// Real-time: this table spans every device, so a poller cycle finishing
// ANYWHERE is a candidate refresh — debounced into a single reload rather
// than one per device, since a fleet of OLTs can all finish around the
// same time. Still just re-reads the same analytics table this page
// already queries, not a live device call.
const reloadDebounced = debounce(() => load(), 3000);
const unsubPoller = wsClient.subscribe('event:poller:finished', () => reloadDebounced.call());
const unsubAction = wsClient.subscribe('event:sys_action:added', () => reloadDebounced.call());
onBeforeUnmount(() => {
  reloadDebounced.cancel();
  unsubPoller();
  unsubAction();
});
</script>

<template>
  <sdPageHeader :routes="[{ path: '/', breadcrumbName: 'Dashboard' }, { path: '', breadcrumbName: 'ONT list' }]" class="ninjadash-page-header-main" />
  <Main>
    <a-row :gutter="25">
      <a-col :span="24">
        <sdCards title="Filters" style="margin-bottom: 16px">
          <a-row :gutter="16">
            <a-col :xs="24" :md="7" style="margin-bottom: 12px">
              <label class="log-filter-label">Devices</label>
              <a-select
                v-model:value="filters.devices"
                mode="multiple"
                allow-clear
                placeholder="All OLTs"
                style="width: 100%"
                :filter-option="(input: string, opt: any) => opt.label.toLowerCase().includes(input.toLowerCase())"
                :options="deviceOptions.map((d: any) => ({ value: d.id, label: d.display_name }))"
              />
            </a-col>
            <a-col :xs="24" :md="6" style="margin-bottom: 12px">
              <label class="log-filter-label">Interface</label>
              <a-select
                v-model:value="filters.interfaceName"
                allow-clear
                show-search
                :disabled="filters.devices.length !== 1"
                :placeholder="filters.devices.length === 1 ? 'Pick an interface...' : 'Choose exactly one device first'"
                style="width: 100%"
                :filter-option="(input: string, opt: any) => opt.label.toLowerCase().includes(input.toLowerCase())"
                :options="interfaceOptions.map((i) => ({ value: i.name, label: i.name }))"
                @change="onInterfacePicked"
              />
            </a-col>
            <a-col :xs="10" :md="4" style="margin-bottom: 12px">
              <label class="log-filter-label">Field name</label>
              <a-select v-model:value="filters.field" style="width: 100%" :options="FIELD_OPTIONS" />
            </a-col>
            <a-col :xs="8" :md="3" style="margin-bottom: 12px">
              <label class="log-filter-label">Expression</label>
              <a-select v-model:value="filters.expression" style="width: 100%" :options="EXPRESSION_OPTIONS" />
            </a-col>
            <a-col :xs="6" :md="4" style="margin-bottom: 12px">
              <label class="log-filter-label">Value</label>
              <a-input v-model:value="filters.value" placeholder="Value" @press-enter="search" />
            </a-col>
            <a-col :xs="24" :md="24" style="margin-bottom: 4px; display: flex; align-items: center; gap: 24px; flex-wrap: wrap">
              <sdButton type="primary" @click="search"><unicon name="search"></unicon> Search</sdButton>
              <div class="ont-quick-toggle">
                <span class="log-filter-label" style="margin: 0">Bad ONU RX</span>
                <a-switch v-model:checked="filters.badRx" @change="search" />
              </div>
              <div class="ont-quick-toggle">
                <span class="log-filter-label" style="margin: 0">Bad Optical OLT RX</span>
                <a-switch v-model:checked="filters.badOltRx" @change="search" />
              </div>
              <div class="ont-quick-toggle">
                <span class="log-filter-label" style="margin: 0">Status</span>
                <a-select v-model:value="filters.ontStatus" style="width: 140px" @change="search">
                  <a-select-option value="All">All</a-select-option>
                  <a-select-option value="Online">Online</a-select-option>
                  <a-select-option value="Offline">Offline</a-select-option>
                  <a-select-option value="LOS">LOS</a-select-option>
                  <a-select-option value="PowerOff">PowerOff</a-select-option>
                </a-select>
              </div>
            </a-col>
          </a-row>
        </sdCards>

        <sdCards :headless="true">
          <div class="col-filter-row">
            <a-select v-model:value="columnFilters.status" allow-clear placeholder="Choose status" style="width: 150px" @change="search">
              <a-select-option value="Online">Online</a-select-option>
              <a-select-option value="Offline">Offline</a-select-option>
              <a-select-option value="LOS">LOS</a-select-option>
              <a-select-option value="PowerOff">PowerOff</a-select-option>
            </a-select>
            <a-input v-model:value="columnFilters.device" placeholder="Filter by device" @change="search" />
            <a-input v-model:value="columnFilters.interface" placeholder="Filter by interface" @change="search" />
            <a-input v-model:value="columnFilters.ident" placeholder="Filter by ident" @change="search" />
            <a-input v-model:value="columnFilters.description" placeholder="Filter by description" @change="search" />
            <a-input v-model:value="columnFilters.mac" placeholder="Filter by MAC (loaded rows)" title="Filters the rows already loaded on this page — MAC isn't a searchable backend field" />
          </div>
          <a-table
            :data-source="visibleRows"
            :loading="loading"
            row-key="interface.id"
            size="small"
            class="ont-table"
            :scroll="{ x: 1450 }"
            :pagination="{ current: page, pageSize: limit, total, showSizeChanger: true, pageSizeOptions: ['20', '50', '100', '200'] }"
            @change="onTableChange"
          >
            <a-table-column title="Status" :width="100" :sorter="true" data-index="interface.status">
              <template #default="{ record }"><span class="status-tag" :class="statusClass(record.interface.status)">{{ record.interface.status }}</span></template>
            </a-table-column>
            <a-table-column title="Device" :width="190">
              <template #default="{ record }">
                <a @click="goToDevice(record.interface.device.id)">{{ record.interface.device.ip }}</a>
                <div class="log-subtext">{{ record.interface.device.name }}</div>
              </template>
            </a-table-column>
            <a-table-column title="Interface" :width="150">
              <template #default="{ record }">
                <router-link :to="{ name: 'device-interface-detail', params: { id: record.interface.device.id, interface: record.interface.bind_key }, query: { type: 'ONU' } }">{{ record.interface.name }}</router-link>
              </template>
            </a-table-column>
            <a-table-column title="Ident" :width="180">
              <template #default="{ record }">{{ record.ident }}</template>
            </a-table-column>
            <a-table-column title="Description" :width="160">
              <template #default="{ record }">{{ record.interface.description }}</template>
            </a-table-column>
            <a-table-column title="Last change" :width="160" :sorter="true" data-index="interface.status_changed">
              <template #default="{ record }">{{ record.interface.status_changed }}</template>
            </a-table-column>
            <a-table-column title="ONU RX" :width="110" :sorter="true" data-index="optical.rx">
              <template #default="{ record }">
                <span class="optical-reading" :class="opticalClass(record.optical.rx, record.optical.bad_rx)">
                  <span class="optical-dot"></span>{{ record.optical.rx ?? 'N/A' }}
                </span>
              </template>
            </a-table-column>
            <a-table-column title="ONU TX" :width="100" :sorter="true" data-index="optical.tx">
              <template #default="{ record }">{{ record.optical.tx ?? 'N/A' }}</template>
            </a-table-column>
            <a-table-column title="Device MAC" :width="140">
              <template #default="{ record }">
                <span v-if="deviceMac(record)" title="MAC address seen on this ONT's FDB table">{{ deviceMac(record) }}</span>
              </template>
            </a-table-column>
            <a-table-column title="Optical OLT RX" :width="130" :sorter="true" data-index="optical.olt_rx">
              <template #default="{ record }">
                <span class="optical-reading" :class="opticalClass(record.optical.olt_rx, record.optical.bad_olt_rx)">
                  <span class="optical-dot"></span>{{ record.optical.olt_rx ?? 'N/A' }}
                </span>
              </template>
            </a-table-column>
            <a-table-column title="Distance" :width="100" :sorter="true" data-index="optical.distance">
              <template #default="{ record }">{{ record.optical.distance ?? 'N/A' }}</template>
            </a-table-column>
            <a-table-column title="Model" :width="120">
              <template #default="{ record }">{{ record.vendor || 'N/A' }}</template>
            </a-table-column>
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
.log-subtext {
  font-size: 11px;
  color: #8c90a4;
}
.ont-quick-toggle {
  display: flex;
  align-items: center;
  gap: 10px;
}
.col-filter-row {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  padding: 16px 16px 0;
}
.col-filter-row :deep(.ant-input) {
  max-width: 200px;
}
.status-tag {
  display: inline-block;
  padding: 2px 10px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.3px;
  color: #fff;
}
.status-tag.is-success {
  background: #1a7a3a;
}
.status-tag.is-failed {
  background: #a60a0a;
}
.status-tag.is-los {
  background: #a60a0a;
}
.status-tag.is-poweroff {
  background: #5a5f7d;
}
.optical-reading {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}
.optical-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  flex-shrink: 0;
}
.optical-reading.is-good {
  color: #1a7a3a;
}
.optical-reading.is-good .optical-dot {
  background: #30a46c;
  box-shadow: 0 0 0 3px rgba(48, 164, 108, 0.18);
}
.optical-reading.is-bad {
  color: #a60a0a;
}
.optical-reading.is-bad .optical-dot {
  background: #e5484d;
  box-shadow: 0 0 0 3px rgba(229, 72, 77, 0.18);
}
.optical-reading.is-muted {
  color: #b4b7c9;
}
.optical-reading.is-muted .optical-dot {
  background: #d8dae5;
}

/* Table polish — zebra rows, a clearer hover, and a slightly bolder header
   so a dense 2,400+ row optics table reads more like a real product and
   less like a raw data dump. */
.ont-table :deep(.ant-table-thead > tr > th) {
  background: #f7f8fb;
  font-weight: 700;
  color: #272b41;
}
.ont-table :deep(.ant-table-tbody > tr:nth-child(even) > td) {
  background: #fbfbfd;
}
.ont-table :deep(.ant-table-tbody > tr:hover > td) {
  background: rgba(24, 104, 219, 0.06) !important;
}
.ont-table :deep(.ant-table-tbody > tr > td) {
  transition: background 0.15s ease;
}
</style>
