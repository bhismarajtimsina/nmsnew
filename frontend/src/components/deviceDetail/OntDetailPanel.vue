<!--
  The rich ONU/ONT detail panel — the real page an ONU name links to
  throughout the original app ("device_iface_dashboard" route, resolved to
  its OntInfo.vue component for ONU-type interfaces). Reverse engineered
  field-for-field from OntInfo-BKDhnQFG.js: status/ident/description,
  optical readings, per-UNI-port table, vendor info, generic configuration,
  IP hosts, FDB, and down-history — plus the real control actions
  (reboot/reset/delete/enable-disable), each gated on the device model
  actually supporting that module and each confirmed before executing,
  exactly like the original.

  Also wired up: the optical/traffic history-chart popups and the live
  in/out traffic view (both real Prometheus/live-traffic endpoints,
  confirmed enabled on this system).

  Not built here (kept out of scope, same reasoning as the device page):
  attachments, billing info (module not enabled on this system). The
  dedicated "Add WAN" button (driven by WAN-tagged macros) was removed by
  request — only ONT registration features are needed here. Any WAN-tagged
  macro is still runnable through the generic macro list further down the
  page (MacrosCard.vue no longer excludes WAN-tagged ones, now that
  there's no dedicated button to avoid duplicating).
-->
<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { DataService } from '@/config/dataService/dataService';
import { Modal, notification } from 'ant-design-vue';
import { signalColor, tempColor } from '@/utility/opticalColors';
import { formatBytes } from '@/utility/formatters';
import { generateExecutionId, pollMacroProgress } from '@/utility/macroProgress';
import type { DeviceCalling } from '@/composables/useDeviceCalling';
import PrometheusChartModal from '@/components/deviceDetail/PrometheusChartModal.vue';
import LiveTrafficModal from '@/components/deviceDetail/LiveTrafficModal.vue';

const props = defineProps<{
  deviceId: number;
  interfaceId: number;
  modules: string[];
  deviceCalling: DeviceCalling;
}>();

const router = useRouter();
const route = useRoute();

interface OntData {
  interface: { id: number; name: string; type: string };
  vendor: Record<string, any>;
  status: { online: string; admin: string; bind: string; _conf_status: string | null };
  uni: { num: number; status: string; name?: string; admin_state?: string; speed?: string; duplex?: string; vlan?: string; [k: string]: any }[];
  description: string;
  fdb: { vlan_id: number; mac_address: string; _virtual_port?: string; status?: string | null }[] | null;
  ident: { value: string; type: string } | null;
  reasons: {
    history_table: { reg_time: string; dereg_time: string; down_reason: string }[] | null;
    last_reg: string | null;
    last_reg_since: string | null;
    last_dereg: string | null;
    last_dereg_since: string | null;
    last_change: string | null;
    last_change_since: string | null;
    last_down_reason: string | null;
  };
  counters: Record<string, string | null> | null;
  optical: { olt_rx: number | null; olt_tx: number | null; rx: number | null; tx: number | null; voltage: number | null; temp: number | null; distance: number | null } | null;
  configuration: Record<string, any> | null;
  profiles: any;
  ip_host: { host_id: string; mac_address: string; current_ip_address: string; current_mask: string; current_gateway: string; current_primary_dns: string; current_second_dns: string }[] | null;
}

const loading = ref(true);
const errorMessage = ref('');
const data = ref<OntData | null>(null);

function hasModule(name: string) {
  return props.modules.includes(name);
}

async function loadInfo(from: 'cache' | 'device' = 'cache') {
  loading.value = true;
  errorMessage.value = '';
  try {
    const { data: resp } = await DataService.get(`/component/olts/interfaces/ont/${props.deviceId}/${props.interfaceId}`, { from });
    data.value = resp.data;
    props.deviceCalling.setMeta(resp.meta);
    await loadFdbFallback();
  } catch (err: any) {
    errorMessage.value = err?.response?.data?.error?.description || err.message;
  } finally {
    loading.value = false;
  }
}

// Confirmed live: on this system the ONT detail endpoint's own `fdb` field
// comes back null for every ONU sampled (Huawei and BDCOM alike), even
// straight from the device (`from=device`) — but the separate FDB-history
// component (a DB-backed poller result, not tied to this endpoint) DOES
// have the real active binding for the exact same device/interface pair,
// keyed by the same bind_key this panel already addresses the ONT by. So
// this is a genuine gap in that one endpoint's own FDB field, not
// something wrong on the frontend — worked around here by falling back to
// FDB history whenever the endpoint's own field comes back empty.
async function loadFdbFallback() {
  if (data.value?.fdb?.length) return;
  try {
    const { data: resp } = await DataService.get(`/component/fdb_history/${props.deviceId}/${props.interfaceId}`, { active: 'yes' });
    const entries = (resp.data || []).map((f: any) => ({ vlan_id: f.vlan_id, mac_address: f.mac_address, status: f.active ? 'active' : null }));
    if (entries.length && data.value) data.value.fdb = entries;
  } catch {
    // leave fdb as-is (null) — the FDB card simply won't show, same as before
  }
}
defineExpose({ loadInfo });

const statusColor = computed(() => (data.value?.status.online !== 'Online' ? '#8F0000' : '#0E4D00'));
const hasOptical = computed(() => data.value?.optical && Object.entries(data.value.optical).some(([k, v]) => v !== null));
// The vendor object always carries a non-null `interface` sub-object
// (matching what interface this vendor data is for), so it must be
// excluded here — otherwise this is always true and the card shows up
// visibly empty whenever the model's own vendor-info poll came back all
// null (e.g. confirmed live: BDCOM's `pon_onts_vendor` module fails to
// parse on this system, so every field here is null except `interface`).
const hasVendor = computed(() => data.value?.vendor && Object.entries(data.value.vendor).some(([k, v]) => k !== 'interface' && v !== null));

// --- description editing ---
const descInput = ref('');
const descSaving = ref(false);
watch(() => data.value?.description, (v) => (descInput.value = v || ''), { immediate: true });
async function saveDescription() {
  descSaving.value = true;
  const attempted = descInput.value;
  try {
    await DataService.put(`/component/olts_control/ont/description/${props.deviceId}/${props.interfaceId}`, { description: attempted });
    notification.success({ message: 'Description updated' });
    await loadInfo('device');
  } catch (err: any) {
    // Confirmed live on the physical-port equivalent of this same action:
    // clearing a description to "" can make the real SNMP write succeed
    // on the device while the backend's own response-building throws
    // afterward — so a caught error here doesn't necessarily mean nothing
    // changed. Re-check the device's actual value before reporting failure.
    await loadInfo('device');
    if (data.value?.description === attempted) {
      notification.success({ message: 'Description updated' });
    } else {
      descInput.value = data.value?.description || '';
      notification.error({ message: 'Could not update description', description: err?.response?.data?.error?.description || 'Please try again.' });
    }
  } finally {
    descSaving.value = false;
  }
}

// --- action buttons ---
const actionBusy = ref<string | null>(null);
async function runAction(key: string, fn: () => Promise<void>) {
  if (actionBusy.value !== null) return;
  actionBusy.value = key;
  try {
    await fn();
  } finally {
    actionBusy.value = null;
  }
}
function rebootOnt() {
  Modal.confirm({
    title: 'Are you sure you want to reboot this ONT?',
    okText: 'Reboot',
    okType: 'danger',
    onOk: () =>
      runAction('reboot', async () => {
        try {
          await DataService.put(`/component/olts_control/ont/reboot/${props.deviceId}/${props.interfaceId}`, {});
          notification.success({ message: 'ONT successfully rebooted' });
          await loadInfo('device');
        } catch (err: any) {
          notification.error({ message: 'Could not reboot ONT', description: err?.response?.data?.error?.description });
        }
      }),
  });
}
function resetOnt() {
  Modal.confirm({
    title: 'Are you sure you want to reset this ONT?',
    okText: 'Reset',
    okType: 'danger',
    onOk: () =>
      runAction('reset', async () => {
        try {
          await DataService.put(`/component/olts_control/ont/reset/${props.deviceId}/${props.interfaceId}`, {});
          notification.success({ message: 'ONT successfully reset' });
          await loadInfo('device');
        } catch (err: any) {
          notification.error({ message: 'Could not reset ONT', description: err?.response?.data?.error?.description });
        }
      }),
  });
}
// Delete now runs as a real, admin-editable macro (Configuration → Macros)
// instead of the hardcoded ctrl_ont_delete module, same change as
// OntsTreeTab.vue's delete button. Discovered by its ONU_DELETE marker tag
// via the same /component/macros/list lookup pattern as OntsTreeTab.vue's
// own delete/clear-pon resolution — see that file's matching comment for
// the full reasoning.
const DELETE_ONT_MACRO_TAG = 'ONU_DELETE';
async function resolveDeleteOntMacroId(): Promise<number> {
  const { data } = await DataService.get('/component/macros/list', {
    device_id: props.deviceId,
    interface_bind_key: String(props.interfaceId),
  });
  const macro = (data.data || []).find((m: any) => (m.display_for || []).includes(DELETE_ONT_MACRO_TAG));
  if (!macro) throw new Error(`No macro tagged "${DELETE_ONT_MACRO_TAG}" is available for this device's model — check Configuration → Macros.`);
  return macro.id;
}
// Live step-by-step progress while the execute() call below is still in
// flight — a delete that genuinely takes over a minute (confirmed live)
// used to leave this whole button showing nothing but a spinner. See
// utility/macroProgress.ts for the full mechanism.
const deleteProgress = ref<{ done: number; total: number; lastCommand: string } | null>(null);
function deleteOnt() {
  Modal.confirm({
    title: 'Are you sure you want to delete (de-register) this ONT?',
    okText: 'Delete',
    okType: 'danger',
    onOk: () =>
      runAction('dereg', async () => {
        const executionId = generateExecutionId();
        deleteProgress.value = null;
        const progress = pollMacroProgress(executionId, (p) => {
          deleteProgress.value = { done: p.done, total: p.total, lastCommand: p.commands[p.commands.length - 1]?.command || '' };
        });
        try {
          const macroId = await resolveDeleteOntMacroId();
          const { data: resp } = await DataService.post('/component/macros/execute', {
            device: { id: props.deviceId },
            macros: { id: macroId },
            interface: { bind_key: String(props.interfaceId) },
            preview: false,
            execution_id: executionId,
          });
          const commands: { command: string; output: string; success: boolean }[] = resp?.data?.commands || [];
          const failed = commands.find((c) => c.success === false);
          // Mirrors OntDelete.php's own "already gone" grace case — a
          // retry against an ONT a previous attempt already removed
          // device-side shouldn't read as a fresh failure.
          if (failed && !/does not exist/i.test(failed.output)) {
            notification.error({ message: 'Could not delete ONT', description: `Command "${failed.command}" failed: ${failed.output}`, duration: 0 });
            return;
          }
          notification.success({ message: 'ONT successfully deleted' });
          router.push({ name: 'device-detail', params: { id: props.deviceId }, query: { tab: 'onts_tree' } });
        } catch (err: any) {
          notification.error({ message: 'Could not delete ONT', description: err?.response?.data?.error?.description || err.message });
        } finally {
          progress.stop();
          deleteProgress.value = null;
        }
      }),
  });
}
function toggleEnable() {
  const target = data.value?.status.admin === 'Enabled' ? 'disable' : 'enable';
  Modal.confirm({
    title: `Are you sure you want to ${target} this ONT?`,
    okText: target === 'disable' ? 'Disable' : 'Enable',
    okType: target === 'disable' ? 'danger' : 'primary',
    onOk: () =>
      runAction('state', async () => {
        try {
          await DataService.put(`/component/olts_control/ont/disable/${props.deviceId}/${props.interfaceId}`, { state: target });
          notification.success({ message: `ONT successfully ${target}d` });
          await loadInfo('device');
        } catch (err: any) {
          notification.error({ message: `Could not ${target} ONT`, description: err?.response?.data?.error?.description });
        }
      }),
  });
}

// --- UNI port admin-state toggle ---
const uniBusy = ref(false);
async function toggleUni(u: OntData['uni'][number]) {
  const action = u.admin_state === 'Enabled' ? 'disable' : 'enable';
  uniBusy.value = true;
  try {
    await DataService.put(`/component/olts_control/ont/uni-control/${props.deviceId}/${props.interfaceId}`, { action: 'admin_state', state: action, num: u.num });
    notification.success({ message: 'UNI port state changed' });
    setTimeout(() => loadInfo('device'), 5000);
  } catch {
    await loadInfo('cache');
  } finally {
    uniBusy.value = false;
  }
}
function uniExtraFields(u: OntData['uni'][number]) {
  return Object.entries(u).filter(([k, v]) => !['num', 'status', 'name', 'admin_state', 'speed', 'duplex', 'vlan'].includes(k) && v !== null);
}

// --- history charts + live traffic ---
const chartModalRef = ref<InstanceType<typeof PrometheusChartModal> | null>(null);
const liveTrafficRef = ref<InstanceType<typeof LiveTrafficModal> | null>(null);
function showOpticalChart() {
  chartModalRef.value?.open('Optical level chart', '/component/prometheus_wrapper/chart-optical-signals-series', { device_id: props.deviceId, interface_id: props.interfaceId, step: '30m' });
}
function showTempChart() {
  chartModalRef.value?.open('Temperature chart', '/component/prometheus_wrapper/chart-optical-temp-series', { device_id: props.deviceId, interface_id: props.interfaceId, step: '30m' });
}
function showVoltageChart() {
  chartModalRef.value?.open('Voltage chart', '/component/prometheus_wrapper/chart-optical-voltage-series', { device_id: props.deviceId, interface_id: props.interfaceId, step: '30m' });
}
function showCountersChart() {
  chartModalRef.value?.open('Traffic counters chart', '/component/prometheus_wrapper/chart-traffic-counter-series', { device_id: props.deviceId, interface_id: props.interfaceId }, { unit: 'bitrate' });
}
function showLiveTraffic() {
  liveTrafficRef.value?.open(props.deviceId, props.interfaceId, data.value?.interface.name || '');
}

watch(() => props.interfaceId, () => {
  loadInfo();
});
onMounted(() => {
  const from = (route.query.from as 'cache' | 'device') || 'cache';
  loadInfo(from);
});
</script>

<template>
  <div>
    <a-alert v-if="errorMessage" type="error" :message="errorMessage" show-icon style="margin-bottom: 16px" />
    <a-skeleton v-if="loading" active />
    <template v-else-if="data">
      <div class="ont-actions">
        <sdButton type="light" size="small" @click="router.push({ name: 'device-detail', params: { id: deviceId }, query: { tab: 'onts_tree' } })">
          <unicon name="arrow-left"></unicon> Go to device
        </sdButton>
        <sdButton type="light" size="small" :loading="actionBusy === null && loading" @click="loadInfo('device')"><unicon name="redo"></unicon> Reload info</sdButton>
        <sdButton v-if="hasModule('ctrl_ont_reboot')" size="small" danger :loading="actionBusy === 'reboot'" @click="rebootOnt"><unicon name="redo"></unicon> Reboot</sdButton>
        <sdButton v-if="hasModule('ctrl_ont_delete')" size="small" danger :loading="actionBusy === 'dereg'" @click="deleteOnt"><unicon name="trash-alt"></unicon> Delete</sdButton>
        <sdButton v-if="hasModule('ctrl_ont_reset')" size="small" danger :loading="actionBusy === 'reset'" @click="resetOnt"><unicon name="history"></unicon> Reset</sdButton>
        <sdButton v-if="hasModule('ctrl_ont_disable')" size="small" :type="data.status.admin === 'Enabled' ? 'default' : 'primary'" :loading="actionBusy === 'state'" @click="toggleEnable">
          <unicon :name="data.status.admin === 'Enabled' ? 'wifi-slash' : 'wifi'"></unicon> {{ data.status.admin === 'Enabled' ? 'Disable' : 'Enable' }}
        </sdButton>
      </div>
      <p v-if="actionBusy === 'dereg'" class="ont-actions__progress">
        <span v-if="!deleteProgress">Connecting to device…</span>
        <span v-else>Step {{ deleteProgress.done }} of {{ deleteProgress.total }}: <code>{{ deleteProgress.lastCommand }}</code></span>
      </p>

      <a-row :gutter="20">
        <a-col :xs="24" :lg="12">
          <sdCards title="ONT status" style="margin-bottom: 20px">
            <div class="ont-status-row">
              <div class="ont-status-icon" :style="{ color: statusColor }">
                <unicon name="wifi-router" width="46" height="46"></unicon>
                <div>{{ data.status.online }}</div>
              </div>
              <table class="ont-kv">
                <tbody>
                  <tr>
                    <th>Interface</th>
                    <td><strong>{{ data.interface.name }}</strong> <small class="ont-kv__muted">({{ data.interface.id }})</small></td>
                  </tr>
                  <tr v-if="data.ident">
                    <th>{{ data.ident.type === 'SN' ? 'Serial' : 'MAC address' }}</th>
                    <td><strong>{{ data.ident.value }}</strong></td>
                  </tr>
                  <tr v-if="hasModule('ctrl_ont_descr')">
                    <th>Description</th>
                    <td>
                      <a-input-group compact style="display: flex">
                        <a-input v-model:value="descInput" size="small" />
                        <sdButton size="small" type="primary" :loading="descSaving" @click="saveDescription"><unicon name="save"></unicon></sdButton>
                      </a-input-group>
                    </td>
                  </tr>
                  <tr v-else>
                    <th>Description</th>
                    <td>{{ data.description }}</td>
                  </tr>
                  <tr><td colspan="2"><hr /></td></tr>
                  <tr v-if="data.status.admin"><th>Admin status</th><td>{{ data.status.admin }}</td></tr>
                  <tr v-if="data.status.bind"><th>Bind status</th><td>{{ data.status.bind }}</td></tr>
                  <tr v-if="data.status._conf_status"><th>Conf status</th><td>{{ data.status._conf_status }}</td></tr>
                  <tr><td colspan="2"><hr /></td></tr>
                  <tr v-if="data.reasons.last_down_reason"><th>Last down reason</th><td>{{ data.reasons.last_down_reason }}</td></tr>
                  <tr v-if="data.reasons.last_change">
                    <th>Last change</th>
                    <td>{{ data.reasons.last_change_since }}<br /><small>{{ data.reasons.last_change }}</small></td>
                  </tr>
                  <tr v-if="data.reasons.last_reg_since && data.status.online === 'Online'"><th>Online since</th><td>{{ data.reasons.last_reg_since }}</td></tr>
                  <tr v-if="data.reasons.last_dereg_since && data.status.online !== 'Online'"><th>Offline since</th><td>{{ data.reasons.last_dereg_since }}</td></tr>
                  <tr v-if="data.reasons.last_dereg"><th>Last dereg</th><td>{{ data.reasons.last_dereg }}</td></tr>
                  <tr v-if="data.reasons.last_reg"><th>Last reg</th><td>{{ data.reasons.last_reg }}</td></tr>
                </tbody>
              </table>
            </div>
          </sdCards>

          <sdCards v-if="hasOptical" title="Optical info" style="margin-bottom: 20px">
            <template #button>
              <a title="Signal strength history" @click="showOpticalChart"><unicon name="chart-bar"></unicon></a>
            </template>
            <div v-if="data.optical" class="ont-optical">
              <div v-if="data.optical.distance != null && data.optical.distance !== 5" class="ont-optical__row">
                <span>Distance</span><strong>{{ data.optical.distance }} m</strong>
              </div>
              <div v-if="data.optical.olt_rx != null" class="ont-optical__row">
                <span>OLT RX</span><strong class="ont-optical__pill" :style="{ background: signalColor(data.optical.olt_rx) }">{{ data.optical.olt_rx }} dBm</strong>
              </div>
              <div v-if="data.optical.olt_tx != null" class="ont-optical__row">
                <span>OLT TX</span><strong>{{ data.optical.olt_tx }} dBm</strong>
              </div>
              <div v-if="data.optical.rx != null" class="ont-optical__row">
                <span>ONU RX</span><strong class="ont-optical__pill" :style="{ background: signalColor(data.optical.rx) }">{{ data.optical.rx }} dBm</strong>
              </div>
              <div v-if="data.optical.tx != null" class="ont-optical__row">
                <span>ONU TX</span><strong>{{ data.optical.tx }} dBm</strong>
              </div>
              <div v-if="data.optical.temp != null" class="ont-optical__row">
                <span>Temp</span>
                <span class="ont-optical__right">
                  <strong class="ont-optical__pill" :style="{ background: tempColor(data.optical.temp) }">{{ data.optical.temp }} C°</strong>
                  <a title="Temperature history" @click="showTempChart"><unicon name="chart-bar" width="14" height="14"></unicon></a>
                </span>
              </div>
              <div v-if="data.optical.voltage != null" class="ont-optical__row">
                <span>Voltage</span>
                <span class="ont-optical__right">
                  <strong>{{ data.optical.voltage }} v</strong>
                  <a title="Voltage history" @click="showVoltageChart"><unicon name="chart-bar" width="14" height="14"></unicon></a>
                </span>
              </div>
            </div>
            <div v-else class="ont-hint">
              Signals not available while the ONU is offline.
              <a title="Signal strength history" @click="showOpticalChart"><unicon name="chart-bar"></unicon></a>
            </div>
          </sdCards>

          <sdCards v-if="data.counters" title="Counters" style="margin-bottom: 20px">
            <template #button>
              <a class="ont-icon-btn ont-icon-btn--chart" title="Traffic history" @click="showCountersChart"><unicon name="chart-bar" width="15" height="15"></unicon></a>
              <a class="ont-icon-btn ont-icon-btn--live" title="Live traffic" @click="showLiveTraffic"><unicon name="heart-rate" width="15" height="15"></unicon></a>
            </template>
            <table class="ont-kv">
              <tbody>
                <tr><th>In / Out octets</th><td>{{ formatBytes(data.counters.in_octets) }} / {{ formatBytes(data.counters.out_octets) }}</td></tr>
                <tr><th>In / Out errors</th><td>{{ data.counters.in_errors }} / {{ data.counters.out_errors }}</td></tr>
                <tr><th>In / Out discards</th><td>{{ data.counters.in_discards }} / {{ data.counters.out_discards }}</td></tr>
              </tbody>
            </table>
          </sdCards>
        </a-col>

        <a-col :xs="24" :lg="12">
          <sdCards v-if="data.uni?.length" title="UNI ports" style="margin-bottom: 20px">
            <a-table :data-source="data.uni" row-key="num" size="small" :pagination="false">
              <a-table-column title="#" data-index="num" :width="50" />
              <a-table-column title="Status" :width="100">
                <template #default="{ record }">
                  <span :style="{ fontWeight: 700, color: record.status === 'Up' ? '#0E4D00' : '#7E0101' }">
                    {{ !record.admin_state || record.admin_state === 'Enabled' ? record.status : record.admin_state }}
                  </span>
                </template>
              </a-table-column>
              <a-table-column v-if="hasModule('ctrl_ont_uni_admin_state')" title="Admin state" :width="110">
                <template #default="{ record }">
                  <a-switch v-if="record.admin_state" :checked="record.admin_state === 'Enabled'" size="small" :loading="uniBusy" @change="toggleUni(record)" />
                </template>
              </a-table-column>
              <a-table-column v-if="data.uni.some((u) => u.speed)" title="Speed" :width="100" data-index="speed" />
              <a-table-column v-if="data.uni.some((u) => u.duplex)" title="Duplex" :width="100" data-index="duplex" />
              <a-table-column v-if="data.uni.some((u) => u.vlan)" title="VLAN" :width="80" data-index="vlan" />
              <a-table-column title="Extra info">
                <template #default="{ record }">
                  <div v-for="[k, v] in uniExtraFields(record)" :key="k"><b>{{ k }}</b>: {{ v }}</div>
                </template>
              </a-table-column>
            </a-table>
          </sdCards>

          <sdCards v-if="hasVendor" title="Vendor info" style="margin-bottom: 20px">
            <table class="ont-kv">
              <tbody>
                <tr v-if="data.vendor.vendor"><th>Vendor</th><td>{{ data.vendor.vendor }}</td></tr>
                <tr v-if="data.vendor.model"><th>Model</th><td>{{ data.vendor.model }}</td></tr>
                <tr v-if="data.vendor.model_id"><th>Model ID</th><td>{{ data.vendor.model_id }}</td></tr>
                <tr v-if="data.vendor.omcc_version"><th>OMCC version</th><td>{{ data.vendor.omcc_version }}</td></tr>
                <tr v-if="data.vendor.ver_hardware"><th>Hardware ver</th><td>{{ data.vendor.ver_hardware }}</td></tr>
                <tr v-if="data.vendor.ver_software"><th>Software ver</th><td>{{ data.vendor.ver_software }}</td></tr>
                <tr v-if="data.vendor.ver_firmware"><th>Firmware ver</th><td>{{ data.vendor.ver_firmware }}</td></tr>
              </tbody>
            </table>
          </sdCards>

          <sdCards v-if="data.configuration" title="Configuration" style="margin-bottom: 20px">
            <table class="ont-kv">
              <tbody>
                <tr v-for="(v, k) in data.configuration" v-show="v" :key="k"><th>{{ k }}</th><td>{{ v }}</td></tr>
              </tbody>
            </table>
          </sdCards>

          <sdCards v-if="data.ip_host?.length" title="ONU IP host" style="margin-bottom: 20px">
            <a-table :data-source="data.ip_host" row-key="host_id" size="small" :pagination="false">
              <a-table-column title="Host ID" data-index="host_id" />
              <a-table-column title="MAC">
                <template #default="{ record }"><strong>{{ record.mac_address }}</strong></template>
              </a-table-column>
              <a-table-column title="IP">
                <template #default="{ record }">
                  <strong>{{ record.current_ip_address }}</strong> <small>{{ record.current_mask }}</small>
                </template>
              </a-table-column>
              <a-table-column title="Gateway" data-index="current_gateway" />
              <a-table-column title="DNS">
                <template #default="{ record }"><small>{{ record.current_primary_dns }} / {{ record.current_second_dns }}</small></template>
              </a-table-column>
            </a-table>
          </sdCards>

          <sdCards v-if="data.fdb?.length" title="FDB" style="margin-bottom: 20px">
            <a-table :data-source="data.fdb" row-key="mac_address" size="small" :pagination="{ pageSize: 10 }">
              <a-table-column title="MAC address" data-index="mac_address" />
              <a-table-column title="VLAN" data-index="vlan_id" />
              <a-table-column title="Status">
                <template #default="{ record }">{{ record.status || 'n/a' }}</template>
              </a-table-column>
              <a-table-column title="Port/VP">
                <template #default="{ record }">{{ record._virtual_port || 'n/a' }}</template>
              </a-table-column>
            </a-table>
          </sdCards>

          <sdCards v-if="data.reasons.history_table?.length" title="Down history" style="margin-bottom: 20px">
            <a-table :data-source="data.reasons.history_table" row-key="reg_time" size="small" :pagination="{ pageSize: 10 }">
              <a-table-column title="Registered" data-index="reg_time" />
              <a-table-column title="Deregistered" data-index="dereg_time" />
              <a-table-column title="Down reason">
                <template #default="{ record }"><strong>{{ record.down_reason }}</strong></template>
              </a-table-column>
            </a-table>
          </sdCards>
        </a-col>
      </a-row>
    </template>

    <PrometheusChartModal ref="chartModalRef" />
    <LiveTrafficModal ref="liveTrafficRef" />
  </div>
</template>

<style scoped>
.ont-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 20px;
}
.ont-actions__progress {
  font-size: 12px;
  color: #8c90a4;
  margin: -12px 0 20px;
}
.ont-actions__progress code {
  font-size: 11px;
}
.ont-status-row {
  display: flex;
  gap: 16px;
}
.ont-status-icon {
  flex: 0 0 70px;
  text-align: center;
  font-weight: 700;
  font-size: 12px;
}
.ont-kv {
  width: 100%;
  border-collapse: collapse;
}
.ont-kv th {
  text-align: left;
  font-weight: 600;
  color: #5a5f7d;
  font-size: 12.5px;
  padding: 3px 8px 3px 0;
  vertical-align: top;
  white-space: nowrap;
}
.ont-kv td {
  font-size: 13px;
  padding: 3px 0;
  vertical-align: top;
}
.ont-kv hr {
  margin: 3px 0;
  border: none;
  border-top: 1px solid #eef0f6;
}
.ont-kv__muted {
  color: #8c90a4;
}
.ont-optical__row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 5px 0;
  border-bottom: 1px solid #f5f6fa;
  font-size: 13px;
}
.ont-optical__row:last-child {
  border-bottom: none;
}
.ont-optical__pill {
  color: #fff;
  padding: 2px 9px;
  border-radius: 6px;
  font-size: 12px;
}
.ont-optical__right {
  display: flex;
  align-items: center;
  gap: 8px;
}
.ont-icon-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border-radius: 8px;
  color: #fff;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.15);
  transition: transform 0.1s ease, box-shadow 0.1s ease;
}
/* The app's global `.unicon svg { fill: <theme gray> }` rule otherwise
   wins over these buttons' own color, leaving the glyph a barely-visible
   grey on a solid background — force it white here. */
.ont-icon-btn :deep(svg) {
  fill: #fff !important;
}
.ont-icon-btn + .ont-icon-btn {
  margin-left: 6px;
}
.ont-icon-btn:hover {
  color: #fff;
  transform: translateY(-1px);
  box-shadow: 0 3px 8px rgba(0, 0, 0, 0.22);
}
.ont-icon-btn--chart {
  background: #1868db;
}
.ont-icon-btn--live {
  background: #30a46c;
  animation: ont-live-pulse 1.8s ease-in-out infinite;
}
@keyframes ont-live-pulse {
  0%,
  100% {
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.15), 0 0 0 0 rgba(48, 164, 108, 0.5);
  }
  50% {
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.15), 0 0 0 5px rgba(48, 164, 108, 0);
  }
}
.ont-hint {
  color: #8c90a4;
  font-size: 13px;
  text-align: center;
  padding: 10px 0;
}
</style>
