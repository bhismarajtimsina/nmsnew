<script setup lang="ts">
import { ref, reactive, onMounted, onBeforeUnmount, computed } from 'vue';
import { useRouter } from 'vue-router';
import { DataService } from '@/config/dataService/dataService';
import { notification } from 'ant-design-vue';
import { wsClient } from '@/services/wsClient';
import { mergeById, removeById } from '@/utility/listMerge';
import { Main } from '../styled';

interface DeviceRow {
  id: number;
  ip: string;
  name: string;
  description: string;
  model: { id: number; name: string; vendor: string; model: string; type: string; icon: string | null };
  group: { id: number; name: string };
  enabled: boolean;
  pinger: { latency: number } | null;
  ifaces_stat: { up: number; down: number } | null;
}
interface GroupOption {
  id: number;
  name: string;
  description: string;
}
interface ModelOption {
  key: string;
  name: string;
  vendor: string;
  model: string;
  id?: number;
}

const router = useRouter();
const loading = ref(true);
const devices = ref<DeviceRow[]>([]);
const groupOptions = ref<GroupOption[]>([]);
const modelOptions = ref<ModelOption[]>([]);
const filtersOpen = ref(false);
const collapsedGroups = ref<Set<number>>(new Set());

const filters = reactive({
  status: 'all' as 'online' | 'offline' | 'all',
  query: '',
  groups: [] as number[],
  models: [] as string[],
  sort: 'ip' as 'ip' | 'name' | 'location',
  showGroups: true,
});

async function loadOptions() {
  const [groupsRes, modelsRes] = await Promise.allSettled([DataService.get('/dev-dashboard/groups'), DataService.get('/dev-dashboard/models')]);
  if (groupsRes.status === 'fulfilled') groupOptions.value = groupsRes.value.data.data || [];
  if (modelsRes.status === 'fulfilled') modelOptions.value = modelsRes.value.data.data || [];
}

async function load() {
  loading.value = true;
  try {
    const { data } = await DataService.get(`/dev-dashboard/devices?sort=${filters.sort}&down_on_top=yes`, {
      limit: 999999,
      query: filters.query || undefined,
    });
    devices.value = data.data || [];
  } catch (err: any) {
    notification.error({ message: 'Could not load devices', description: err?.response?.data?.error?.description || 'Please try again.' });
  } finally {
    loading.value = false;
  }
}

function clearFilters() {
  filters.status = 'all';
  filters.query = '';
  filters.groups = [];
  filters.models = [];
  filters.sort = 'ip';
  filters.showGroups = true;
  load();
}

const filteredDevices = computed(() =>
  devices.value.filter((d) => {
    if (filters.status === 'online' && !(d.pinger && d.pinger.latency > 0)) return false;
    if (filters.status === 'offline' && d.pinger && d.pinger.latency > 0) return false;
    if (filters.groups.length && !filters.groups.includes(d.group?.id)) return false;
    if (filters.models.length && !filters.models.includes(d.model?.id as any)) return false;
    return true;
  }),
);

interface GroupedRow {
  group: GroupOption;
  devices: DeviceRow[];
  onlineCount: number;
  ifacesUp: number;
  ifacesTotal: number;
}
const groupedDevices = computed<GroupedRow[]>(() => {
  if (!filters.showGroups) {
    return [
      {
        group: { id: 0, name: 'All devices', description: '' },
        devices: filteredDevices.value,
        onlineCount: filteredDevices.value.filter((d) => d.pinger && d.pinger.latency > 0).length,
        ifacesUp: filteredDevices.value.reduce((s, d) => s + (d.ifaces_stat?.up || 0), 0),
        ifacesTotal: filteredDevices.value.reduce((s, d) => s + (d.ifaces_stat?.up || 0) + (d.ifaces_stat?.down || 0), 0),
      },
    ];
  }
  const byGroup = new Map<number, DeviceRow[]>();
  for (const d of filteredDevices.value) {
    const gid = d.group?.id ?? 0;
    if (!byGroup.has(gid)) byGroup.set(gid, []);
    byGroup.get(gid)!.push(d);
  }
  return Array.from(byGroup.entries()).map(([gid, list]) => ({
    group: groupOptions.value.find((g) => g.id === gid) || { id: gid, name: list[0]?.group?.name || 'Unknown', description: '' },
    devices: list,
    onlineCount: list.filter((d) => d.pinger && d.pinger.latency > 0).length,
    ifacesUp: list.reduce((s, d) => s + (d.ifaces_stat?.up || 0), 0),
    ifacesTotal: list.reduce((s, d) => s + (d.ifaces_stat?.up || 0) + (d.ifaces_stat?.down || 0), 0),
  }));
});

function toggleGroup(id: number) {
  if (collapsedGroups.value.has(id)) collapsedGroups.value.delete(id);
  else collapsedGroups.value.add(id);
}
function expandAll() {
  collapsedGroups.value = new Set();
}
function hideAll() {
  collapsedGroups.value = new Set(groupedDevices.value.map((g) => g.group.id));
}

function goToDevice(id: number) {
  router.push({ name: 'device-detail', params: { id } });
}

onMounted(async () => {
  await loadOptions();
  load();
});

// Real-time: a device added/edited/removed anywhere (this tab or another
// user entirely) updates this list with no REST call at all. Verified
// EditDeviceAction/AddDeviceAction both build `model`/`group` via real
// storage lookups (not bare id-only stubs), so the pushed record's nested
// objects are fully resolved — safe to merge directly. Object.assign only
// touches fields present in the payload, so this device's poller-derived
// pinger/ifaces_stat (never part of the pushed record) are left exactly as
// they were, not blanked out.
//
// group/model/status filter client-side over the full list (see
// filteredDevices below), so merging into `devices` is safe for those —
// but the free-text search box (filters.query) is sent to the server and
// this list only ever holds its matches. A merge can't know whether a
// pushed record matches that search text, so while a query is active this
// falls back to a real reload instead of risking a device that doesn't
// match appearing (added) or one that's already shown going stale as if
// it still matched (updated).
function onDeviceChanged(record: DeviceRow) {
  if (filters.query) {
    load();
    return;
  }
  mergeById(devices, record);
}
const unsubAdded = wsClient.subscribe('event:device:added', (msg) => onDeviceChanged(msg.data));
const unsubUpdated = wsClient.subscribe('event:device:updated', (msg) => onDeviceChanged(msg.data));
const unsubDeleted = wsClient.subscribe('event:device:deleted', (msg) => removeById(devices, msg.data.id));
onBeforeUnmount(() => {
  unsubAdded();
  unsubUpdated();
  unsubDeleted();
});
</script>

<template>
  <sdPageHeader :routes="[{ path: '/', breadcrumbName: 'Dashboard' }, { path: '', breadcrumbName: 'Devices' }]" class="ninjadash-page-header-main">
    <template #buttons>
      <sdButton type="light" @click="expandAll">Expand all</sdButton>
      <sdButton type="light" @click="hideAll">Hide all</sdButton>
      <sdButton type="default" @click="load"><unicon name="redo"></unicon> Reload info</sdButton>
      <button type="button" class="dl-filter-toggle" @click="filtersOpen = !filtersOpen">
        <unicon name="filter"></unicon> Filters
      </button>
    </template>
  </sdPageHeader>
  <Main>
    <a-row :gutter="25">
      <a-col v-if="filtersOpen" :span="24">
        <sdCards title="Filters" style="margin-bottom: 16px">
          <a-row :gutter="16">
            <a-col :xs="24" :md="4" style="margin-bottom: 12px">
              <label class="log-filter-label">Status</label>
              <a-radio-group v-model:value="filters.status">
                <a-radio value="online">Online</a-radio>
                <a-radio value="offline">Offline</a-radio>
                <a-radio value="all">All</a-radio>
              </a-radio-group>
            </a-col>
            <a-col :xs="24" :md="6" style="margin-bottom: 12px">
              <label class="log-filter-label">Search query</label>
              <a-input v-model:value="filters.query" placeholder="Search..." @press-enter="load" />
            </a-col>
            <a-col :xs="12" :md="4" style="margin-bottom: 12px">
              <label class="log-filter-label">Device groups</label>
              <a-select v-model:value="filters.groups" mode="multiple" allow-clear placeholder="All groups" style="width: 100%" :options="groupOptions.map((g) => ({ value: g.id, label: g.name }))" />
            </a-col>
            <a-col :xs="12" :md="4" style="margin-bottom: 12px">
              <label class="log-filter-label">Device models</label>
              <a-select v-model:value="filters.models" mode="multiple" allow-clear placeholder="All models" style="width: 100%" :options="modelOptions.map((m) => ({ value: m.id, label: `${m.vendor} ${m.model}` }))" />
            </a-col>
            <a-col :xs="12" :md="3" style="margin-bottom: 12px">
              <label class="log-filter-label">Sort by</label>
              <a-select v-model:value="filters.sort" style="width: 100%" @change="load">
                <a-select-option value="ip">IP</a-select-option>
                <a-select-option value="name">Name</a-select-option>
                <a-select-option value="location">Location</a-select-option>
              </a-select>
            </a-col>
            <a-col :xs="12" :md="3" style="margin-bottom: 12px">
              <label class="log-filter-label">Show groups</label>
              <a-radio-group v-model:value="filters.showGroups">
                <a-radio :value="true">Yes</a-radio>
                <a-radio :value="false">No</a-radio>
              </a-radio-group>
            </a-col>
          </a-row>
          <div class="log-filter-actions">
            <sdButton type="danger" @click="clearFilters"><unicon name="times"></unicon> Clear filters</sdButton>
            <sdButton type="primary" @click="load"><unicon name="search"></unicon> Search</sdButton>
          </div>
        </sdCards>
      </a-col>

      <a-col :span="24">
        <a-skeleton v-if="loading" active />
        <template v-else>
          <div v-for="g in groupedDevices" :key="g.group.id" class="dl-group" style="margin-bottom: 16px">
            <div class="dl-group__header" @click="toggleGroup(g.group.id)">
              <div>
                <strong>{{ g.group.name }}</strong>
                <div class="dl-group__desc">{{ g.group.description }}</div>
              </div>
              <div class="dl-group__stats">
                <span><unicon name="server-network"></unicon> {{ g.onlineCount }}/{{ g.devices.length }}</span>
                <span><unicon name="wifi-router"></unicon> {{ g.ifacesUp }}/{{ g.ifacesTotal }}</span>
                <unicon :name="collapsedGroups.has(g.group.id) ? 'angle-down' : 'angle-up'"></unicon>
              </div>
            </div>
            <div v-show="!collapsedGroups.has(g.group.id)" class="dl-group__grid">
              <div v-for="d in g.devices" :key="d.id" class="dl-card" :class="d.pinger && d.pinger.latency > 0 ? 'is-online' : 'is-offline'" @click="goToDevice(d.id)">
                <div class="dl-card__top">
                  <img v-if="d.model?.icon" :src="d.model.icon" class="dl-card__icon" alt="" />
                  <unicon v-else name="server-network" class="dl-card__icon-fallback"></unicon>
                  <div class="dl-card__title">{{ d.name }}<span v-if="!d.enabled" class="dl-card__disabled">(disabled)</span></div>
                </div>
                <div class="dl-card__ip"><unicon name="desktop"></unicon> {{ d.ip }}</div>
                <div class="dl-card__ifaces">
                  <unicon name="wifi-router"></unicon>
                  <span class="up">{{ d.ifaces_stat?.up ?? 0 }}</span>/<span class="down">{{ d.ifaces_stat?.down ?? 0 }}</span>/{{ (d.ifaces_stat?.up ?? 0) + (d.ifaces_stat?.down ?? 0) }}
                </div>
                <div class="dl-card__model">{{ d.model?.name }}</div>
              </div>
            </div>
          </div>
          <a-empty v-if="!groupedDevices.length || !filteredDevices.length" description="No devices found" />
        </template>
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
.dl-filter-toggle {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  height: 34px;
  padding: 0 14px;
  border: 1px solid #e6e9f1;
  background: #fff;
  border-radius: 6px;
  color: #5a5f7d;
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
}
.dl-filter-toggle:hover {
  color: #1868db;
  border-color: #1868db;
}
.dl-group__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: #1a7a3a;
  color: #fff;
  padding: 12px 16px;
  border-radius: 6px 6px 0 0;
  cursor: pointer;
}
.dl-group__desc {
  font-size: 12px;
  opacity: 0.85;
}
.dl-group__stats {
  display: flex;
  align-items: center;
  gap: 16px;
  font-weight: 700;
}
.dl-group__stats span {
  display: inline-flex;
  align-items: center;
  gap: 5px;
}
.dl-group__stats :deep(svg) {
  width: 15px;
  height: 15px;
}
.dl-group__grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
  gap: 12px;
  padding: 12px;
  background: #fff;
  border: 1px solid #eceef4;
  border-top: none;
  border-radius: 0 0 6px 6px;
}
.dl-card {
  border: 1px solid #1a7a3a;
  border-left: 4px solid #1a7a3a;
  border-radius: 6px;
  padding: 10px 12px;
  cursor: pointer;
  background: #f6fbf7;
}
.dl-card.is-offline {
  border-color: #a60a0a;
  border-left-color: #a60a0a;
  background: #fdf4f4;
}
.dl-card:hover {
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}
.dl-card__top {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 4px;
}
.dl-card__icon {
  width: 28px;
  height: 28px;
  object-fit: contain;
}
.dl-card__icon-fallback {
  width: 22px;
  height: 22px;
  color: #8c90a4;
}
.dl-card__title {
  font-weight: 700;
  font-size: 13px;
  color: #272b41;
}
.dl-card__ip {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  color: #5a5f7d;
  margin-bottom: 4px;
}
.dl-card__ip :deep(svg) {
  width: 12px;
  height: 12px;
}
.dl-card__ifaces {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 12px;
  color: #5a5f7d;
  margin-bottom: 4px;
}
.dl-card__ifaces :deep(svg) {
  width: 12px;
  height: 12px;
}
.dl-card__ifaces .up {
  color: #1a7a3a;
  font-weight: 700;
}
.dl-card__ifaces .down {
  color: #a60a0a;
  font-weight: 700;
}
.dl-card__model {
  font-size: 12px;
  color: #5a5f7d;
  font-weight: 600;
}
.dl-card__disabled {
  color: #a60a0a;
  margin-left: 6px;
}
</style>
