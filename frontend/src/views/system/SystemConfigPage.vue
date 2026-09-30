<script setup lang="ts">
import { ref, reactive, onMounted, computed } from 'vue';
import { DataService } from '@/config/dataService/dataService';
import { notification } from 'ant-design-vue';
import { Main } from '../styled';
import { groupTitle, paramLabel, paramDescription } from './paramMeta';

interface ConfigParam {
  param_name: string;
  type: 'input' | 'select' | 'number' | 'checkbox';
  default?: any;
  variants?: any[];
  regex?: string;
  rebuild_required?: boolean;
  value: string;
}

const activeTab = ref('info');

// --- System information ---
const infoLoading = ref(true);
const ifaceStat = ref<any>(null);
const componentCount = ref<number | null>(null);

async function loadInfo() {
  infoLoading.value = true;
  const [ifaceRes, compRes] = await Promise.allSettled([
    DataService.get('/device-interface/stat-by-types'),
    DataService.get('/system/component'),
  ]);
  if (ifaceRes.status === 'fulfilled') ifaceStat.value = ifaceRes.value.data.data;
  if (compRes.status === 'fulfilled') componentCount.value = (compRes.value.data.data || []).length;
  infoLoading.value = false;
}

// --- Configuration ---
const configLoading = ref(true);
const configSaving = ref(false);
const configGroups = reactive<Record<string, ConfigParam[]>>({});
const filterText = ref('');
const anyRebuildRequired = computed(() =>
  Object.values(configGroups).some((params) => params.some((p) => p.rebuild_required)),
);

async function loadConfig() {
  configLoading.value = true;
  try {
    const { data } = await DataService.get('/system/configuration');
    Object.keys(configGroups).forEach((k) => delete configGroups[k]);
    Object.assign(configGroups, data.data || {});
  } finally {
    configLoading.value = false;
  }
}

function matchesFilter(p: ConfigParam) {
  if (!filterText.value.trim()) return true;
  const q = filterText.value.toLowerCase();
  return p.param_name.toLowerCase().includes(q) || paramLabel(p.param_name).toLowerCase().includes(q);
}
const visibleGroups = computed(() =>
  Object.entries(configGroups)
    .map(([key, params]) => ({ key, params: params.filter(matchesFilter) }))
    .filter((g) => g.params.length),
);

function isChecked(p: ConfigParam) {
  return p.value === '1' || p.value === 'true' || (p.value as any) === true;
}
function setChecked(p: ConfigParam, checked: boolean) {
  p.value = checked ? '1' : '';
}

async function saveConfig() {
  configSaving.value = true;
  const payload: Record<string, any> = {};
  Object.values(configGroups).forEach((params) => {
    params.forEach((p) => {
      payload[p.param_name] = p.value;
    });
  });
  try {
    await DataService.put('/system/configuration', payload);
    notification.success({ message: 'Configuration saved' });
    if (anyRebuildRequired.value) {
      notification.info({
        message: 'Some changes need a rebuild to take effect',
        description: 'cd /opt/support-dms && sudo docker compose up -d --build',
        duration: 0,
      });
    }
  } catch (err: any) {
    notification.error({
      message: 'Could not save configuration',
      description: err?.response?.data?.error?.description || 'Please try again.',
    });
  } finally {
    configSaving.value = false;
  }
}

// --- Components ---
interface ComponentRow {
  id: number;
  key: string;
  name: string;
  enabled: boolean;
  builtIn: boolean;
}
const components = ref<ComponentRow[]>([]);
const componentsLoading = ref(true);
const togglingKey = ref<string | null>(null);

async function loadComponents() {
  componentsLoading.value = true;
  try {
    const { data } = await DataService.get('/system/component');
    components.value = data.data || [];
  } finally {
    componentsLoading.value = false;
  }
}

async function toggleComponent(c: ComponentRow, enabled: boolean) {
  togglingKey.value = c.key;
  try {
    await DataService.put(`/system/component/${c.key}`, { enabled });
    c.enabled = enabled;
    notification.success({ message: `${c.name} ${enabled ? 'enabled' : 'disabled'}` });
  } catch (err: any) {
    notification.error({
      message: `Could not ${enabled ? 'enable' : 'disable'} ${c.name}`,
      description: err?.response?.data?.error?.description || 'Please try again.',
    });
  } finally {
    togglingKey.value = null;
  }
}

// --- Schedule ---
interface ScheduleRow {
  id: number;
  key: string;
  command: string;
  crontab: string;
  latest: string | null;
  state: 'ENABLED' | 'DISABLED';
  editable: number;
}
const schedule = ref<ScheduleRow[]>([]);
const scheduleLoading = ref(true);
const togglingScheduleId = ref<number | null>(null);

async function loadSchedule() {
  scheduleLoading.value = true;
  try {
    const { data } = await DataService.get('/system/schedule');
    schedule.value = data.data || [];
  } finally {
    scheduleLoading.value = false;
  }
}

async function toggleSchedule(s: ScheduleRow, enabled: boolean) {
  if (!s.editable) return;
  togglingScheduleId.value = s.id;
  const newState = enabled ? 'ENABLED' : 'DISABLED';
  try {
    await DataService.put(`/system/schedule/${s.id}`, { state: newState });
    s.state = newState;
    notification.success({ message: `Job ${enabled ? 'enabled' : 'disabled'}` });
  } catch (err: any) {
    notification.error({
      message: 'Could not update schedule',
      description: err?.response?.data?.error?.description || 'Please try again.',
    });
  } finally {
    togglingScheduleId.value = null;
  }
}

function onTabChange(key: string) {
  if (key === 'config' && !Object.keys(configGroups).length) loadConfig();
  if (key === 'components' && !components.value.length) loadComponents();
  if (key === 'schedule' && !schedule.value.length) loadSchedule();
}

onMounted(loadInfo);
</script>

<template>
  <sdPageHeader :routes="[{ path: '/', breadcrumbName: 'Dashboard' }, { path: '', breadcrumbName: 'System configuration' }]" class="ninjadash-page-header-main" />
  <Main>
    <a-tabs v-model:activeKey="activeTab" @change="onTabChange">
      <a-tab-pane key="info" tab="System information">
        <sdCards title="System info">
          <a-skeleton v-if="infoLoading" active />
          <ul v-else class="sysinfo-list">
            <li>
              <span class="sysinfo-list__label">Interfaces</span>
              <span class="sysinfo-list__value">
                <template v-if="ifaceStat">
                  <span class="sysinfo-pill sysinfo-pill--ok">Up: {{ ifaceStat.up }}</span>
                  <span class="sysinfo-pill sysinfo-pill--fail">Down: {{ ifaceStat.down }}</span>
                  <span
                    v-for="(t, key) in ifaceStat.types"
                    :key="key"
                    class="sysinfo-pill sysinfo-pill--muted"
                  >
                    {{ key }}: {{ t.up }}/{{ t.up + t.down }}
                  </span>
                </template>
                <template v-else>—</template>
              </span>
            </li>
            <li>
              <span class="sysinfo-list__label">Components</span>
              <span class="sysinfo-list__value">{{ componentCount ?? '—' }}</span>
            </li>
          </ul>
        </sdCards>
      </a-tab-pane>

      <a-tab-pane key="config" tab="Configuration" force-render>
        <div class="config-toolbar">
          <sdButton type="primary" :loading="configSaving" :disabled="configLoading" @click="saveConfig">
            <unicon name="save"></unicon> Save
          </sdButton>
          <a-input v-model:value="filterText" placeholder="Start write for filter..." allow-clear style="max-width: 320px" />
        </div>

        <a-skeleton v-if="configLoading" active />
        <div v-else class="config-columns">
          <sdCards
            v-for="group in visibleGroups"
            :key="group.key"
            :title="groupTitle(group.key)"
            class="config-card"
          >
            <div v-for="p in group.params" :key="p.param_name" class="config-field">
              <div class="config-field__label">
                {{ paramLabel(p.param_name) }}<span v-if="p.rebuild_required" class="config-field__star">*</span>
                <p v-if="paramDescription(p.param_name)" class="config-field__desc">{{ paramDescription(p.param_name) }}</p>
              </div>
              <div class="config-field__control">
                <a-switch v-if="p.type === 'checkbox'" :checked="isChecked(p)" @change="(v: boolean) => setChecked(p, v)" />
                <a-select v-else-if="p.type === 'select'" v-model:value="p.value" style="width: 100%">
                  <a-select-option v-for="v in p.variants" :key="v" :value="String(v)">{{ v }}</a-select-option>
                </a-select>
                <a-input v-else v-model:value="p.value" style="width: 100%" />
              </div>
            </div>
          </sdCards>
        </div>

        <p v-if="anyRebuildRequired" class="config-footnote">
          * To apply the changed parameter, you need to rebuild the containers, run command on server:<br />
          <code>cd /opt/support-dms &amp;&amp; sudo docker compose up -d --build</code>
        </p>
      </a-tab-pane>

      <a-tab-pane key="components" tab="Components" force-render>
        <sdCards :headless="true">
          <a-skeleton v-if="componentsLoading" active />
          <a-table v-else :data-source="components" row-key="id" size="small" :pagination="{ pageSize: 20 }">
            <a-table-column title="Name" data-index="name" />
            <a-table-column title="Key" data-index="key" />
            <a-table-column title="Built-in" :width="100">
              <template #default="{ record }">{{ record.builtIn ? 'Yes' : 'No' }}</template>
            </a-table-column>
            <a-table-column title="Enabled" :width="100">
              <template #default="{ record }">
                <a-switch
                  :checked="record.enabled"
                  :loading="togglingKey === record.key"
                  @change="(v: boolean) => toggleComponent(record, v)"
                />
              </template>
            </a-table-column>
          </a-table>
        </sdCards>
      </a-tab-pane>

      <a-tab-pane key="schedule" tab="Schedule" force-render>
        <sdCards :headless="true">
          <a-skeleton v-if="scheduleLoading" active />
          <a-table v-else :data-source="schedule" row-key="id" size="small" :scroll="{ x: 900 }" :pagination="{ pageSize: 20 }">
            <a-table-column title="Key" data-index="key" />
            <a-table-column title="Command" data-index="command" />
            <a-table-column title="Crontab" data-index="crontab" :width="130" />
            <a-table-column title="Last run" data-index="latest" :width="170" />
            <a-table-column title="Enabled" :width="100">
              <template #default="{ record }">
                <a-switch
                  :checked="record.state === 'ENABLED'"
                  :disabled="!record.editable"
                  :loading="togglingScheduleId === record.id"
                  @change="(v: boolean) => toggleSchedule(record, v)"
                />
              </template>
            </a-table-column>
          </a-table>
        </sdCards>
      </a-tab-pane>
    </a-tabs>
  </Main>
</template>

<style scoped>
.sysinfo-list {
  list-style: none;
  margin: 0;
  padding: 0;
}
.sysinfo-list li {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 10px 0;
  border-bottom: 1px solid #f0f1f5;
  font-size: 13px;
}
.sysinfo-list li:last-child {
  border-bottom: none;
}
.sysinfo-list__label {
  width: 140px;
  flex-shrink: 0;
  color: #8c90a4;
}
.sysinfo-list__value {
  font-weight: 700;
  color: #272b41;
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.sysinfo-pill {
  display: inline-flex;
  padding: 1px 10px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 700;
}
.sysinfo-pill--ok {
  background: rgba(38, 179, 87, 0.12);
  color: #1a9c50;
}
.sysinfo-pill--fail {
  background: rgba(255, 77, 79, 0.12);
  color: #e5484d;
}
.sysinfo-pill--muted {
  background: rgba(140, 144, 164, 0.15);
  color: #6b7086;
}

.config-toolbar {
  display: flex;
  align-items: center;
  gap: 16px;
  margin-bottom: 20px;
}
.config-toolbar :deep(svg) {
  width: 14px;
  height: 14px;
  margin-right: 4px;
}
.config-columns {
  column-count: 3;
  column-gap: 25px;
}
@media (max-width: 1400px) {
  .config-columns {
    column-count: 2;
  }
}
@media (max-width: 767px) {
  .config-columns {
    column-count: 1;
  }
}
.config-card {
  break-inside: avoid;
  margin-bottom: 25px;
  display: inline-block;
  width: 100%;
}
.config-field {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 12px 0;
  border-bottom: 1px solid #f5f6fa;
}
.config-field:last-child {
  border-bottom: none;
}
.config-field__label {
  font-size: 13px;
  font-weight: 700;
  color: #272b41;
  text-align: left;
}
.config-field__star {
  color: #e5484d;
}
.config-field__desc {
  margin: 2px 0 0;
  font-size: 11px;
  font-weight: 400;
  color: #8c90a4;
}
.config-field__control {
  width: 100%;
}
/* Checkboxes read as a toggle row rather than a stacked block — keep the
   switch inline next to its label instead of on its own line below. */
.config-field:has(.ant-switch) {
  flex-direction: row;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
}
.config-field:has(.ant-switch) .config-field__control {
  width: auto;
}
.config-footnote {
  font-size: 12px;
  color: #8c90a4;
  margin-top: 4px;
}
.config-footnote code {
  background: #f5f6fa;
  padding: 2px 8px;
  border-radius: 4px;
}
</style>
