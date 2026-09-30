<!--
  The non-ONU interface detail panel — covers both physical (uplink/GE)
  ports and PON ports themselves (as opposed to the ONUs registered under
  them, which get the rich OntDetailPanel instead). The original app's
  generic InterfaceInfo component covers this case, but there's no
  dedicated single-port fetch on the backend for either kind — only the
  list endpoints the Physical-ports tab and the ONTs-tree's port headers
  already use — so, like `deviceStore.getInterfaceByBindKey` does
  throughout the original app for its client-side "stored interface"
  lookups, this finds the port by id in those same lists rather than
  inventing an endpoint that doesn't exist. Tries the physical list first,
  then the PON-ports list.

  Description here was read-only until found missing: the original has a
  real, separate control action for this (ChangePortDescription.php,
  `PUT /component/olts_control/olt/interface/description/{device}/{interface}`,
  gated on module `ctrl_port_descr` — present for every device on this
  system) — distinct from the ONU one (DescriptionOnuAction.php) already
  wired up on OntDetailPanel.
-->
<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { DataService } from '@/config/dataService/dataService';
import { Modal, notification } from 'ant-design-vue';
import { tempColor, signalColor } from '@/utility/opticalColors';
import { formatBytes } from '@/utility/formatters';
import type { DeviceCalling } from '@/composables/useDeviceCalling';
import PrometheusChartModal from '@/components/deviceDetail/PrometheusChartModal.vue';
import LiveTrafficModal from '@/components/deviceDetail/LiveTrafficModal.vue';

const props = defineProps<{ deviceId: number; interfaceId: number; modules: string[]; deviceCalling: DeviceCalling }>();
const router = useRouter();
function hasModule(name: string) {
  return props.modules.includes(name);
}

interface PhysicalPort {
  kind: 'physical';
  interface: { id: number; name: string };
  oper_status: string;
  nway_status: string;
  admin_state: string;
  description: string;
  counters: { in_errors: string; out_errors: string; in_discards: string; out_discards: string; in_octets: string; out_octets: string } | null;
  optical: { tx_power: number | null; rx_power: number | null; temp: number | null; vcc: number | null; tx_bias: number | null } | null;
  medium_type: string | null;
  sfp_media: string | null;
}
interface PonPort {
  kind: 'pon';
  id: number;
  name: string;
  description: string;
  pon_port_size: number | null;
  optical: { tx: number | null; temp: number | null };
}

const loading = ref(true);
const errorMessage = ref('');
const port = ref<PhysicalPort | PonPort | null>(null);
const portName = computed(() => (port.value?.kind === 'physical' ? port.value.interface.name : port.value?.name));

async function loadInfo(from: 'cache' | 'device' = 'cache') {
  loading.value = true;
  errorMessage.value = '';
  port.value = null;
  try {
    const { data } = await DataService.get(`/component/olts/interfaces/physical/${props.deviceId}`, { from });
    props.deviceCalling.setMeta(data.meta);
    const match = (data.data || []).find((p: any) => p.interface.id === props.interfaceId);
    if (match) port.value = { ...match, kind: 'physical' };
  } catch (err: any) {
    errorMessage.value = err?.response?.data?.error?.description || err.message;
  }
  if (!port.value) {
    try {
      const { data } = await DataService.get(`/component/olts/interfaces/pon-ports/${props.deviceId}`, { from });
      props.deviceCalling.setMeta(data.meta);
      const match = (data.data || []).find((p: any) => p.id === props.interfaceId);
      if (match) port.value = { ...match, kind: 'pon' };
    } catch {
      /* fall through */
    }
  }
  if (!port.value) errorMessage.value = errorMessage.value || 'This port was not found on the device.';
  loading.value = false;
}
defineExpose({ loadInfo });
watch(() => props.interfaceId, () => loadInfo());
onMounted(() => loadInfo());

// --- description editing (can be set, or cleared back to empty) ---
const descInput = ref('');
const descSaving = ref(false);
watch(
  () => port.value?.description,
  (v) => (descInput.value = v || ''),
  { immediate: true },
);
async function saveDescription() {
  descSaving.value = true;
  const attempted = descInput.value;
  try {
    await DataService.put(`/component/olts_control/olt/interface/description/${props.deviceId}/${props.interfaceId}`, { description: attempted });
    notification.success({ message: 'Description updated' });
    await loadInfo('device');
  } catch (err: any) {
    // Confirmed live: clearing a physical port's description to "" makes
    // the real SNMP write succeed on the device, but the backend's own
    // response-building throws afterward for that specific case, so the
    // API call still comes back as an error even though the change took.
    // Re-check the device's actual value before reporting failure.
    await loadInfo('device');
    if (port.value?.description === attempted) {
      notification.success({ message: 'Description updated' });
    } else {
      descInput.value = port.value?.description || '';
      notification.error({ message: 'Could not update description', description: err?.response?.data?.error?.description || 'Please try again.' });
    }
  } finally {
    descSaving.value = false;
  }
}

const resetting = ref(false);
function confirmResetPort() {
  Modal.confirm({
    title: 'Are you sure you want to reset this port?',
    okText: 'Reset',
    okType: 'danger',
    onOk: async () => {
      resetting.value = true;
      try {
        await DataService.put(`/component/olts_control/olt/reset-port/${props.deviceId}/${props.interfaceId}`, {});
        notification.success({ message: 'Port successfully reset' });
        await loadInfo('device');
      } catch (err: any) {
        notification.error({ message: 'Could not reset port', description: err?.response?.data?.error?.description || 'Please try again.' });
      } finally {
        resetting.value = false;
      }
    },
  });
}

const chartModalRef = ref<InstanceType<typeof PrometheusChartModal> | null>(null);
const liveTrafficRef = ref<InstanceType<typeof LiveTrafficModal> | null>(null);
function showCountersChart() {
  chartModalRef.value?.open('Traffic counters chart', '/component/prometheus_wrapper/chart-traffic-counter-series', { device_id: props.deviceId, interface_id: props.interfaceId }, { unit: 'bitrate' });
}
function showLiveTraffic() {
  liveTrafficRef.value?.open(props.deviceId, props.interfaceId, portName.value || '');
}
</script>

<template>
  <div>
    <a-alert v-if="errorMessage" type="error" :message="errorMessage" show-icon style="margin-bottom: 16px" />
    <a-skeleton v-if="loading" active />
    <template v-else-if="port">
      <div class="pi-actions">
        <sdButton
          type="light"
          size="small"
          @click="router.push({ name: 'device-detail', params: { id: deviceId }, query: { tab: port?.kind === 'pon' ? 'onts_tree' : 'card_interfaces' } })"
        >
          <unicon name="arrow-left"></unicon> Go to device
        </sdButton>
        <sdButton type="light" size="small" @click="loadInfo('device')"><unicon name="redo"></unicon> Reload info</sdButton>
        <sdButton type="light" size="small" @click="showCountersChart"><unicon name="chart-bar"></unicon> Traffic history</sdButton>
        <sdButton type="light" size="small" @click="showLiveTraffic"><unicon name="heart-rate"></unicon> Live traffic</sdButton>
        <sdButton size="small" danger :loading="resetting" @click="confirmResetPort"><unicon name="history"></unicon> Reset port</sdButton>
      </div>

      <a-row :gutter="20">
        <a-col :xs="24" :lg="12">
          <sdCards title="Status" style="margin-bottom: 20px">
            <table class="pi-kv">
              <tbody>
                <tr><th>Name</th><td><strong>{{ portName }}</strong></td></tr>
                <template v-if="port.kind === 'physical'">
                  <tr><th>Status</th><td><span :style="{ fontWeight: 700, color: port.oper_status === 'Up' ? 'darkgreen' : 'darkred' }">{{ port.oper_status }}</span></td></tr>
                  <tr v-if="port.admin_state"><th>Admin state</th><td>{{ port.admin_state }}</td></tr>
                  <tr v-if="port.nway_status && port.nway_status !== 'Down'"><th>Speed</th><td>{{ port.nway_status }}</td></tr>
                </template>
                <template v-else>
                  <tr><th>Type</th><td>PON port</td></tr>
                </template>
                <tr>
                  <th>Description</th>
                  <td>
                    <a-input-group v-if="hasModule('ctrl_port_descr')" compact style="display: flex">
                      <a-input v-model:value="descInput" size="small" placeholder="No description" />
                      <sdButton size="small" type="primary" :loading="descSaving" @click="saveDescription"><unicon name="save"></unicon></sdButton>
                    </a-input-group>
                    <template v-else>{{ port.description || '-' }}</template>
                  </td>
                </tr>
                <template v-if="port.kind === 'physical'">
                  <tr v-if="port.medium_type"><th>Medium</th><td>{{ port.medium_type }}</td></tr>
                  <tr v-if="port.sfp_media"><th>SFP media</th><td>{{ port.sfp_media }}</td></tr>
                </template>
                <tr v-else-if="port.pon_port_size"><th>Port size</th><td>{{ port.pon_port_size }} ONUs</td></tr>
              </tbody>
            </table>
          </sdCards>

          <sdCards v-if="port.kind === 'physical' && port.optical && Object.values(port.optical).some((v) => v !== null)" title="SFP optical info" style="margin-bottom: 20px">
            <div class="pi-optical">
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
              <div v-if="port.optical.tx_bias != null" class="pi-optical__row"><span>TX bias</span><strong>{{ port.optical.tx_bias }}</strong></div>
            </div>
          </sdCards>

          <sdCards v-if="port.kind === 'pon' && (port.optical.tx != null || port.optical.temp != null)" title="Optical info" style="margin-bottom: 20px">
            <table class="pi-kv">
              <tbody>
                <tr v-if="port.optical.tx != null"><th>TX</th><td>{{ port.optical.tx }} dBm</td></tr>
                <tr v-if="port.optical.temp != null"><th>Temp</th><td><strong class="pi-pill" :style="{ background: tempColor(port.optical.temp) }">{{ Math.round(port.optical.temp) }} C°</strong></td></tr>
              </tbody>
            </table>
          </sdCards>
        </a-col>

        <a-col v-if="port.kind === 'physical' && port.counters" :xs="24" :lg="12">
          <sdCards title="Counters" style="margin-bottom: 20px">
            <table class="pi-kv">
              <tbody>
                <tr><th>In / Out octets</th><td>{{ formatBytes(port.counters.in_octets) }} / {{ formatBytes(port.counters.out_octets) }}</td></tr>
                <tr><th>In / Out errors</th><td>{{ port.counters.in_errors }} / {{ port.counters.out_errors }}</td></tr>
                <tr><th>In / Out discards</th><td>{{ port.counters.in_discards }} / {{ port.counters.out_discards }}</td></tr>
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
