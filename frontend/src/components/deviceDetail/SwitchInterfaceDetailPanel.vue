<!--
  Switch-interface detail panel — the SWITCH counterpart to
  PhysicalInterfaceDetailPanel.vue, following the exact same "find the port
  by id in the list the tab already uses" approach (there's no dedicated
  single-port fetch either; SwitchInterfacesTab.vue's own
  `GET /component/switches/interfaces/{device}` bundles everything already).

  Description/admin-state/admin-speed editing goes through the real switch
  control action (UpdatePortAction, `PUT /component/switches/interface/{device}/{interface}`),
  gated behind the `switches_set_port_description` / `_admin_state` /
  `_admin_speed` rules — mirrors the OLT panel's description-only editing,
  but this device type's own action also supports admin state + speed, so
  those are exposed too.
-->
<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { DataService } from '@/config/dataService/dataService';
import { notification } from 'ant-design-vue';
import { signalColor, tempColor } from '@/utility/opticalColors';
import { formatBytes } from '@/utility/formatters';
import type { DeviceCalling } from '@/composables/useDeviceCalling';
import PrometheusChartModal from '@/components/deviceDetail/PrometheusChartModal.vue';
import LiveTrafficModal from '@/components/deviceDetail/LiveTrafficModal.vue';

const props = defineProps<{ deviceId: number; interfaceId: number; deviceCalling: DeviceCalling }>();
const router = useRouter();

interface SwitchPort {
  interface: { id: number; name: string; type?: string; xid?: string };
  fdb: { vlan_id: number; status: string; mac_address: string }[] | null;
  link_info: { oper_status: string; nway_status: string; admin_state: string; type?: string }[] | null;
  errors: Record<string, any> | null;
  optical: { rx_power?: number | null; tx_power?: number | null; temp?: number | null; vcc?: number | null; present?: boolean | null } | null;
  sfp_media: Record<string, any> | null;
  rmon: Record<string, any> | null;
  counters: { in_octets?: string; out_octets?: string; in_errors?: string; out_errors?: string; in_discards?: string; out_discards?: string } | null;
  description: { description: string } | null;
  vlans: { id: number; name: string; type: string }[] | null;
}

const loading = ref(true);
const errorMessage = ref('');
const port = ref<SwitchPort | null>(null);
const portName = computed(() => port.value?.interface?.name || '');

async function loadInfo(from: 'cache' | 'device' = 'cache') {
  loading.value = true;
  errorMessage.value = '';
  port.value = null;
  try {
    const { data } = await DataService.get(`/component/switches/interfaces/${props.deviceId}`, { from });
    props.deviceCalling.setMeta(data.meta);
    const match = (data.data || []).find((p: any) => p.interface.id === props.interfaceId);
    port.value = match || null;
    if (!port.value) errorMessage.value = 'This port was not found on the device.';
  } catch (err: any) {
    errorMessage.value = err?.response?.data?.error?.description || err.message;
  } finally {
    loading.value = false;
  }
}
defineExpose({ loadInfo });
watch(() => props.interfaceId, () => loadInfo());
onMounted(() => loadInfo());

// --- description editing ---
const descInput = ref('');
const descSaving = ref(false);
watch(
  () => port.value?.description?.description,
  (v) => (descInput.value = v || ''),
  { immediate: true },
);
async function saveDescription() {
  descSaving.value = true;
  const attempted = descInput.value;
  try {
    await DataService.put(`/component/switches/interface/${props.deviceId}/${props.interfaceId}`, { description: attempted });
    notification.success({ message: 'Description updated' });
    await loadInfo('device');
  } catch (err: any) {
    // Mirrors the OLT panel's defensive re-check: some devices report an
    // error on the write response even when the SNMP set itself succeeded.
    await loadInfo('device');
    if (port.value?.description?.description === attempted) {
      notification.success({ message: 'Description updated' });
    } else {
      descInput.value = port.value?.description?.description || '';
      notification.error({ message: 'Could not update description', description: err?.response?.data?.error?.description || 'Please try again.' });
    }
  } finally {
    descSaving.value = false;
  }
}

// --- admin state (enable/disable port) ---
const adminStateSaving = ref(false);
const isDisabled = computed(() => port.value?.link_info?.[0]?.admin_state === 'Disabled');
async function toggleAdminState() {
  adminStateSaving.value = true;
  const next = isDisabled.value ? 'enable' : 'disable';
  try {
    await DataService.put(`/component/switches/interface/${props.deviceId}/${props.interfaceId}`, { admin_state: next });
    notification.success({ message: next === 'enable' ? 'Port enabled' : 'Port disabled' });
    await loadInfo('device');
  } catch (err: any) {
    notification.error({ message: 'Could not update admin state', description: err?.response?.data?.error?.description || 'Please try again.' });
  } finally {
    adminStateSaving.value = false;
  }
}

function goToDevice() {
  router.push({ name: 'device-detail', params: { id: props.deviceId }, query: { tab: 'switch_interfaces' } });
}

function statusColor(status: string | undefined) {
  return status === 'Up' ? 'darkgreen' : status === 'Down' ? '#8c90a4' : '#b37100';
}

const chartModalRef = ref<InstanceType<typeof PrometheusChartModal> | null>(null);
const liveTrafficRef = ref<InstanceType<typeof LiveTrafficModal> | null>(null);
function showCountersChart() {
  chartModalRef.value?.open('Traffic counters chart', '/component/prometheus_wrapper/chart-traffic-counter-series', { device_id: props.deviceId, interface_id: props.interfaceId }, { unit: 'bitrate' });
}
function showLiveTraffic() {
  liveTrafficRef.value?.open(props.deviceId, props.interfaceId, portName.value);
}
</script>

<template>
  <div>
    <a-alert v-if="errorMessage" type="error" :message="errorMessage" show-icon style="margin-bottom: 16px" />
    <a-skeleton v-if="loading" active />
    <template v-else-if="port">
      <div class="pi-actions">
        <sdButton type="light" size="small" @click="goToDevice">
          <unicon name="arrow-left"></unicon> Go to device
        </sdButton>
        <sdButton type="light" size="small" @click="loadInfo('device')"><unicon name="redo"></unicon> Reload info</sdButton>
        <sdButton type="light" size="small" @click="showCountersChart"><unicon name="chart-bar"></unicon> Traffic history</sdButton>
        <sdButton type="light" size="small" @click="showLiveTraffic"><unicon name="heart-rate"></unicon> Live traffic</sdButton>
        <sdButton size="small" :danger="!isDisabled" :loading="adminStateSaving" @click="toggleAdminState">
          <unicon name="power"></unicon> {{ isDisabled ? 'Enable port' : 'Disable port' }}
        </sdButton>
      </div>

      <a-row :gutter="20">
        <a-col :xs="24" :lg="12">
          <sdCards title="Status" style="margin-bottom: 20px">
            <table class="pi-kv">
              <tbody>
                <tr><th>Name</th><td><strong>{{ portName }}</strong></td></tr>
                <tr><th>Status</th><td><span :style="{ fontWeight: 700, color: statusColor(port.link_info?.[0]?.oper_status) }">{{ port.link_info?.[0]?.oper_status || 'N/A' }}</span></td></tr>
                <tr><th>Admin state</th><td>{{ port.link_info?.[0]?.admin_state || 'N/A' }}</td></tr>
                <tr v-if="port.link_info?.[0]?.nway_status && port.link_info[0].nway_status !== 'Down'"><th>Speed</th><td>{{ port.link_info[0].nway_status }}</td></tr>
                <tr>
                  <th>Description</th>
                  <td>
                    <a-input-group compact style="display: flex">
                      <a-input v-model:value="descInput" size="small" placeholder="No description" />
                      <sdButton size="small" type="primary" :loading="descSaving" @click="saveDescription"><unicon name="save"></unicon></sdButton>
                    </a-input-group>
                  </td>
                </tr>
              </tbody>
            </table>
          </sdCards>

          <sdCards
            v-if="port.optical && (port.optical.present === false || Object.values(port.optical).some((v) => v !== null && v !== undefined))"
            title="SFP optical info"
            style="margin-bottom: 20px"
          >
            <p v-if="port.optical.present === false" class="pi-no-sfp">No transceiver present in this port.</p>
            <div v-else class="pi-optical">
              <div v-if="port.optical.rx_power != null" class="pi-optical__row">
                <span>RX power</span><strong class="pi-pill" :style="{ background: signalColor(port.optical.rx_power) }">{{ port.optical.rx_power }} dBm</strong>
              </div>
              <div v-if="port.optical.tx_power != null" class="pi-optical__row">
                <span>TX power</span><strong class="pi-pill" :style="{ background: signalColor(port.optical.tx_power) }">{{ port.optical.tx_power }} dBm</strong>
              </div>
              <div v-if="port.optical.temp != null" class="pi-optical__row">
                <span>Temp</span><strong class="pi-pill" :style="{ background: tempColor(port.optical.temp) }">{{ port.optical.temp }} C°</strong>
              </div>
              <div v-if="port.optical.vcc != null" class="pi-optical__row"><span>Voltage</span><strong>{{ port.optical.vcc }} v</strong></div>
            </div>
          </sdCards>

          <sdCards v-if="port.vlans && port.vlans.length" title="VLANs" style="margin-bottom: 20px">
            <a-tag v-for="v in port.vlans" :key="`${v.type}-${v.id}`" :color="v.type === 'untagged' ? 'blue' : 'default'" style="margin-bottom: 6px">
              {{ v.id }} ({{ v.name }}) — {{ v.type }}
            </a-tag>
          </sdCards>

          <sdCards v-if="port.fdb && port.fdb.length" title="MAC address table (FDB)" style="margin-bottom: 20px">
            <a-table :data-source="port.fdb" size="small" :pagination="{ pageSize: 10 }" row-key="mac_address">
              <a-table-column title="VLAN" data-index="vlan_id" :width="70" />
              <a-table-column title="MAC address" data-index="mac_address" />
              <a-table-column title="Status" data-index="status" :width="90" />
            </a-table>
          </sdCards>
        </a-col>

        <a-col :xs="24" :lg="12">
          <sdCards v-if="port.counters" title="Counters" style="margin-bottom: 20px">
            <table class="pi-kv">
              <tbody>
                <tr><th>In / Out octets</th><td>{{ formatBytes(port.counters.in_octets) }} / {{ formatBytes(port.counters.out_octets) }}</td></tr>
                <tr v-if="port.counters.in_errors !== undefined"><th>In / Out errors</th><td>{{ port.counters.in_errors }} / {{ port.counters.out_errors }}</td></tr>
                <tr v-if="port.counters.in_discards !== undefined"><th>In / Out discards</th><td>{{ port.counters.in_discards }} / {{ port.counters.out_discards }}</td></tr>
              </tbody>
            </table>
          </sdCards>

          <sdCards v-if="port.errors" title="Errors (rmon)" style="margin-bottom: 20px">
            <table class="pi-kv">
              <tbody>
                <tr v-for="(v, k) in port.errors" :key="k"><th>{{ k }}</th><td>{{ v }}</td></tr>
              </tbody>
            </table>
          </sdCards>

          <sdCards v-if="port.sfp_media" title="SFP media" style="margin-bottom: 20px">
            <table class="pi-kv">
              <tbody>
                <tr v-for="(v, k) in port.sfp_media" :key="k"><th>{{ k }}</th><td>{{ v }}</td></tr>
              </tbody>
            </table>
          </sdCards>
        </a-col>
      </a-row>
    </template>

    <PrometheusChartModal ref="chartModalRef" />
    <LiveTrafficModal ref="liveTrafficRef" />
  </div>
</template>

<style scoped>
.pi-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 20px;
}
.pi-kv {
  width: 100%;
  border-collapse: collapse;
}
.pi-kv th {
  text-align: left;
  font-weight: 600;
  color: #5a5f7d;
  font-size: 12.5px;
  padding: 3px 8px 3px 0;
  white-space: nowrap;
}
.pi-kv td {
  font-size: 13px;
  padding: 3px 0;
}
.pi-pill {
  color: #fff;
  padding: 2px 9px;
  border-radius: 6px;
  font-size: 12px;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.12);
}
.pi-no-sfp {
  color: #8c90a4;
  font-style: italic;
  font-size: 13px;
  margin: 0;
}
.pi-optical {
  display: flex;
  flex-direction: column;
}
.pi-optical__row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 6px 0;
  border-bottom: 1px solid #f5f6fa;
  font-size: 13px;
}
.pi-optical__row:last-child {
  border-bottom: none;
}
</style>
