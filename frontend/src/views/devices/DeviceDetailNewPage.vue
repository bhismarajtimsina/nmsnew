<script setup lang="ts">
/**
 * The device detail page under the new login (Plan 25): read-only, from the CyberSathy-NMS database only. Live
 * device reads (system info, resources, console, macros) belong to later plans and are not offered here. The legacy
 * build keeps DeviceDetailPage.vue; the router picks one or the other (src/router/wcaRoutes.ts).
 */
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, ApiError } from '@/api/client';
import { useAuthStore } from '@/stores/auth';
import ActionConfirmModal from '@/components/actions/ActionConfirmModal.vue';
import {
  actionError,
  actionsApi,
  canRun,
  GATE,
  PORT_ADMIN_ACTION,
  type ActionSpec,
  type Executed,
  type Params,
  type Target,
} from '@/components/actions/actions';
import { Main } from '../styled';
import {
  loadDevice,
  loadEvents,
  loadInterfaces,
  loadPolls,
  pingSummary,
  type DeviceDetail,
  type DeviceEvent,
  type Interface,
  type PollHistory,
  type Section,
} from './deviceDetail';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const can = (permission: string) => auth.can(permission);

const deviceId = computed(() => String(route.params.id));
const loading = ref(true);
const loadError = ref<string | null>(null);
const detail = ref<DeviceDetail | null>(null);
const interfaces = ref<Section<{ items: Interface[]; total: number }> | null>(null);
const events = ref<Section<{ items: DeviceEvent[]; total: number }> | null>(null);
const polls = ref<Section<PollHistory> | null>(null);

const ping = computed(() => pingSummary(detail.value?.overview.ping ?? null));

async function load() {
  loading.value = true;
  loadError.value = null;
  try {
    detail.value = await loadDevice(api, deviceId.value);
  } catch (error) {
    detail.value = null;
    loadError.value =
      error instanceof ApiError && error.status === 404
        ? 'This device does not exist, or is not one you can see.'
        : error instanceof Error
        ? error.message
        : 'Could not load the device.';
    loading.value = false;
    return;
  }
  loading.value = false;
  [interfaces.value, events.value, polls.value] = await Promise.all([
    loadInterfaces(api, deviceId.value, can),
    loadEvents(api, deviceId.value, can),
    loadPolls(api, deviceId.value, can),
  ]);
}

// Device actions: offered only when the user holds the permissions and the API says this build can run them now.
const actions = actionsApi(api);
const actionSpecs = ref<ActionSpec[]>([]);
const canPortAdmin = computed(() => canRun(actionSpecs.value, PORT_ADMIN_ACTION, can));
const canProtect = computed(() => can('interfaces.manage'));
const pending = ref<{ action: string; targets: Target[]; params: Params } | null>(null);
const protecting = ref<string | null>(null);
const protectError = ref<string | null>(null);

async function loadActions() {
  if (!can(GATE)) return;
  try {
    actionSpecs.value = await actions.list();
  } catch {
    actionSpecs.value = []; // no actions offered; the read-only page still works
  }
}

function setAdminState(row: Interface, state: 'up' | 'down') {
  pending.value = { action: PORT_ADMIN_ACTION, targets: [{ interface_id: row.id }], params: { state } };
}

async function toggleProtection(row: Interface, value: boolean) {
  protecting.value = row.id;
  protectError.value = null;
  try {
    row.protected = await actions.setProtection(row.id, value);
  } catch (error) {
    protectError.value = actionError(error);
  } finally {
    protecting.value = null;
  }
}

async function actionFinished(_executed: Executed) {
  interfaces.value = await loadInterfaces(api, deviceId.value, can);
}

onMounted(load);
onMounted(loadActions);
watch(deviceId, load);

const interfaceColumns = [
  { title: 'ifIndex', dataIndex: 'if_index', key: 'if_index', width: 90 },
  { title: 'Name', dataIndex: 'name', key: 'name' },
  { title: 'Description', dataIndex: 'alias', key: 'alias' },
  { title: 'Admin', dataIndex: 'admin_status', key: 'admin_status', width: 100 },
  { title: 'Oper', dataIndex: 'oper_status', key: 'oper_status', width: 100 },
  { title: 'Protected', dataIndex: 'protected', key: 'protected', width: 100 },
  { title: '', key: 'actions', width: 170 },
];
const eventColumns = [
  { title: 'When', dataIndex: 'occurred_at', key: 'occurred_at', width: 190 },
  { title: 'Severity', dataIndex: 'severity', key: 'severity', width: 100 },
  { title: 'Event', dataIndex: 'name', key: 'name' },
  { title: 'Description', dataIndex: 'description', key: 'description' },
  { title: 'Resolved', dataIndex: 'resolved_at', key: 'resolved_at', width: 190 },
];
const pollColumns = [
  { title: 'Started', dataIndex: 'started_at', key: 'started_at', width: 190 },
  { title: 'Profile', dataIndex: 'profile_name', key: 'profile_name' },
  { title: 'Outcome', dataIndex: 'outcome', key: 'outcome', width: 110 },
  { title: 'Rows', dataIndex: 'rows', key: 'rows', width: 80 },
  { title: 'Duration (ms)', dataIndex: 'duration_ms', key: 'duration_ms', width: 120 },
  { title: 'Error', dataIndex: 'error', key: 'error' },
];

function when(value: string | null | undefined): string {
  return value ? new Date(value).toLocaleString() : '';
}
const severityColor: Record<string, string> = { info: 'blue', warning: 'orange', critical: 'red' };
</script>

<template>
  <sdPageHeader
    :routes="[
      { path: '/', breadcrumbName: 'Dashboard' },
      { path: '/devices/list', breadcrumbName: 'Devices' },
      { path: '', breadcrumbName: detail?.overview.name || 'Device' },
    ]"
    class="ninjadash-page-header-main"
  >
    <template #buttons>
      <sdButton type="light" @click="router.push({ name: 'devices-list' })"
        ><unicon name="arrow-left"></unicon> Back</sdButton
      >
      <sdButton type="default" :disabled="loading" @click="load"><unicon name="redo"></unicon> Reload</sdButton>
    </template>
  </sdPageHeader>
  <Main>
    <a-skeleton v-if="loading" active />
    <a-result v-else-if="loadError" status="warning" :title="loadError" />
    <template v-else-if="detail">
      <sdCards :headless="true" style="margin-bottom: 16px">
        <div class="ddn-head">
          <div>
            <h2 class="ddn-head__name">
              {{ detail.overview.name }}
              <a-tag v-if="!detail.overview.polling_enabled" color="default">polling off</a-tag>
            </h2>
            <div class="ddn-head__ip">{{ detail.overview.management_ip }}</div>
          </div>
          <a-tag :color="ping.tone === 'ok' ? 'green' : ping.tone === 'bad' ? 'red' : 'default'">{{
            ping.label
          }}</a-tag>
        </div>
        <a-descriptions :column="{ xs: 1, md: 2, xl: 3 }" size="small">
          <a-descriptions-item label="Type">{{ detail.device.device_type }}</a-descriptions-item>
          <a-descriptions-item label="Vendor">{{ detail.device.vendor || '—' }}</a-descriptions-item>
          <a-descriptions-item label="Model">{{ detail.overview.model?.name || '—' }}</a-descriptions-item>
          <a-descriptions-item label="Group">{{ detail.overview.group?.name || '—' }}</a-descriptions-item>
          <a-descriptions-item label="Hostname">{{ detail.device.hostname || '—' }}</a-descriptions-item>
          <a-descriptions-item label="Status">{{ detail.device.status }}</a-descriptions-item>
          <a-descriptions-item label="Interfaces">
            <span class="ddn-up">{{ detail.overview.interfaces.up }} up</span> /
            <span class="ddn-down">{{ detail.overview.interfaces.down }} down</span>
          </a-descriptions-item>
          <a-descriptions-item label="Last ping">{{
            when(detail.overview.ping?.last_checked_at) || '—'
          }}</a-descriptions-item>
          <a-descriptions-item label="Added">{{ when(detail.device.created_at) }}</a-descriptions-item>
        </a-descriptions>
      </sdCards>

      <sdCards :headless="true">
        <a-tabs>
          <a-tab-pane key="interfaces" tab="Interfaces">
            <a-empty
              v-if="interfaces?.state === 'forbidden'"
              description="You do not have permission to view interfaces."
            />
            <a-alert v-else-if="interfaces?.state === 'error'" type="error" :message="interfaces.message" />
            <template v-else-if="interfaces?.state === 'ok'">
              <p v-if="interfaces.data.total > interfaces.data.items.length" class="ddn-note">
                Showing the first {{ interfaces.data.items.length }} of {{ interfaces.data.total }} interfaces.
              </p>
              <a-alert
                v-if="protectError"
                type="error"
                :message="protectError"
                closable
                style="margin-bottom: 8px"
                @close="protectError = null"
              />
              <a-table
                :columns="interfaceColumns"
                :data-source="interfaces.data.items"
                row-key="id"
                size="small"
                :pagination="{ pageSize: 50 }"
              >
                <template #bodyCell="{ column, record }">
                  <template v-if="column.key === 'protected'">
                    <a-switch
                      v-if="canProtect"
                      size="small"
                      :checked="record.protected"
                      :loading="protecting === record.id"
                      @change="(value: boolean) => toggleProtection(record, value)"
                    />
                    <a-tag v-else-if="record.protected" color="gold">protected</a-tag>
                  </template>
                  <template v-else-if="column.key === 'actions' && canPortAdmin">
                    <a-space>
                      <sdButton size="small" type="light" @click="setAdminState(record, 'up')">Enable</sdButton>
                      <a-tooltip :title="record.protected ? 'Protected: this port cannot be shut down from here' : ''">
                        <sdButton
                          size="small"
                          type="danger"
                          :disabled="record.protected"
                          @click="setAdminState(record, 'down')"
                          >Disable</sdButton
                        >
                      </a-tooltip>
                    </a-space>
                  </template>
                </template>
              </a-table>
            </template>
            <a-skeleton v-else active />
          </a-tab-pane>
          <a-tab-pane key="events" tab="Events">
            <a-empty v-if="events?.state === 'forbidden'" description="You do not have permission to view events." />
            <a-alert v-else-if="events?.state === 'error'" type="error" :message="events.message" />
            <a-table
              v-else-if="events?.state === 'ok'"
              :columns="eventColumns"
              :data-source="events.data.items"
              row-key="id"
              size="small"
              :pagination="false"
            >
              <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'occurred_at' || column.key === 'resolved_at'">{{
                  when(record[column.key])
                }}</template>
                <a-tag v-else-if="column.key === 'severity'" :color="severityColor[record.severity]">{{
                  record.severity
                }}</a-tag>
              </template>
            </a-table>
            <a-skeleton v-else active />
          </a-tab-pane>
          <a-tab-pane key="polls" tab="Polling">
            <a-empty
              v-if="polls?.state === 'forbidden'"
              description="You do not have permission to view polling history."
            />
            <a-alert v-else-if="polls?.state === 'error'" type="error" :message="polls.message" />
            <template v-else-if="polls?.state === 'ok'">
              <p v-if="polls.data.state?.breaker_open" class="ddn-note ddn-down">
                Polling is paused after repeated failures until {{ when(polls.data.state.breaker_open_until) }}:
                {{ polls.data.state.last_error }}
              </p>
              <a-table
                :columns="pollColumns"
                :data-source="polls.data.results"
                row-key="id"
                size="small"
                :pagination="false"
              >
                <template #bodyCell="{ column, record }">
                  <template v-if="column.key === 'started_at'">{{ when(record.started_at) }}</template>
                </template>
              </a-table>
            </template>
            <a-skeleton v-else active />
          </a-tab-pane>
        </a-tabs>
      </sdCards>
    </template>
  </Main>
  <ActionConfirmModal
    v-if="pending"
    :open="pending !== null"
    :action="pending.action"
    :targets="pending.targets"
    :params="pending.params"
    @close="pending = null"
    @finished="actionFinished"
  />
</template>

<style scoped>
.ddn-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
  margin-bottom: 12px;
}
.ddn-head__name {
  margin: 0;
  font-size: 18px;
}
.ddn-head__ip {
  color: #8c90a4;
}
.ddn-up {
  color: #20c997;
}
.ddn-down {
  color: #ff4d4f;
}
.ddn-note {
  margin-bottom: 8px;
}
</style>
