<!--
  Interface detail page — what every interface name across the app now
  links to ("device_iface_dashboard" in the original). Dispatches to the
  rich ONU panel or the simpler physical-port panel based on the `type`
  query hint the caller already knows (every list that links here already
  knows whether the row is an ONU or a physical port, so there's no need
  to re-derive it via the original's own `parse_interface` lookup, which
  turned out to be unreliable against this system's real devices anyway).
-->
<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { DataService } from '@/config/dataService/dataService';
import { notification } from 'ant-design-vue';
import { Main } from '../styled';
import { useDeviceCalling } from '@/composables/useDeviceCalling';
import DeviceCallingCard from '@/components/deviceDetail/DeviceCallingCard.vue';
import OntDetailPanel from '@/components/deviceDetail/OntDetailPanel.vue';
import PhysicalInterfaceDetailPanel from '@/components/deviceDetail/PhysicalInterfaceDetailPanel.vue';
import SwitchInterfaceDetailPanel from '@/components/deviceDetail/SwitchInterfaceDetailPanel.vue';
import StorageInfoCard from '@/components/deviceDetail/StorageInfoCard.vue';
import IfaceDownHistoryCard from '@/components/deviceDetail/IfaceDownHistoryCard.vue';
import InterfaceTagsCard from '@/components/deviceDetail/InterfaceTagsCard.vue';
import InterfaceEventsCard from '@/components/deviceDetail/InterfaceEventsCard.vue';
import MacrosCard from '@/components/deviceDetail/MacrosCard.vue';

const route = useRoute();
const router = useRouter();
const deviceId = computed(() => Number(route.params.id));
const interfaceId = computed(() => Number(route.params.interface));
const ifaceType = computed(() => {
  const t = String(route.query.type || 'ONU').toUpperCase();
  return t === 'SWITCH' ? 'SWITCH' : t === 'PHYSICAL' ? 'PHYSICAL' : 'ONU';
});
const deviceCalling = useDeviceCalling();

const loading = ref(true);
const deviceName = ref('');
const deviceIp = ref('');
const modules = ref<string[]>([]);

interface StoredInterface {
  id: number;
  bind_key: string;
  name: string;
  type: string;
  description: string;
  status: string;
  comment: string;
  poll_enabled: boolean;
  params: Record<string, any> | null;
  created_at: string;
  updated_at: string;
  tags?: string[];
}
const storedInterface = ref<StoredInterface | null>(null);
const favorite = ref(false);

async function loadShell() {
  loading.value = true;
  try {
    const [deviceResp, systemResp, ifacesResp, favResp] = await Promise.allSettled([
      DataService.get(`/device/${deviceId.value}`),
      DataService.get(`/switcher-core/device/system/${deviceId.value}`, { from: 'cache' }),
      DataService.get(`/device-interface/by-device/${deviceId.value}`, { limit: 999999 }),
      DataService.get(`/interface-marks/favorite/${deviceId.value}`),
    ]);
    if (deviceResp.status === 'fulfilled') {
      deviceName.value = deviceResp.value.data.data.name;
      deviceIp.value = deviceResp.value.data.data.ip;
    }
    if (systemResp.status === 'fulfilled') modules.value = systemResp.value.data.data?.meta?.modules || [];
    if (ifacesResp.status === 'fulfilled') {
      storedInterface.value = (ifacesResp.value.data.data || []).find((i: StoredInterface) => Number(i.bind_key) === interfaceId.value) || null;
    }
    if (favResp.status === 'fulfilled' && storedInterface.value) {
      favorite.value = (favResp.value.data.data || []).some((f: any) => Number(f.bind_key) === interfaceId.value);
    }
  } finally {
    loading.value = false;
  }
}

async function toggleFavorite() {
  if (!storedInterface.value) return;
  const next = !favorite.value;
  try {
    await DataService.put(`/interface-marks/favorite/${storedInterface.value.id}`, { favorite: next });
    favorite.value = next;
    notification.success({ message: next ? 'Added to favorites' : 'Removed from favorites' });
  } catch (err: any) {
    notification.error({ message: 'Could not update favorite status', description: err?.response?.data?.error?.description || 'Please try again.' });
  }
}

const qrModalOpen = ref(false);
const qrImage = ref('');
const qrLoading = ref(false);
async function openQr() {
  if (!storedInterface.value) return;
  qrModalOpen.value = true;
  qrLoading.value = true;
  try {
    const { data } = await DataService.get(`/component/qr-generator/qr-code-base64/interface/${storedInterface.value.id}`, { size: 400, with_logo: true, with_label: true });
    qrImage.value = data.data?.qr || '';
  } catch {
    qrImage.value = '';
  } finally {
    qrLoading.value = false;
  }
}

const panelRef = ref<
  InstanceType<typeof OntDetailPanel> | InstanceType<typeof PhysicalInterfaceDetailPanel> | InstanceType<typeof SwitchInterfaceDetailPanel> | null
>(null);
async function reloadAll(from: 'cache' | 'device' = 'device') {
  await loadShell();
  if (panelRef.value && typeof (panelRef.value as any).loadInfo === 'function') await (panelRef.value as any).loadInfo(from);
}

watch([deviceId, interfaceId], () => loadShell());
onMounted(() => loadShell());
</script>

<template>
  <sdPageHeader
    :routes="[
      { path: '/', breadcrumbName: 'Dashboard' },
      { path: '/devices/list', breadcrumbName: 'Devices' },
      { path: `/devices/${deviceId}`, breadcrumbName: deviceName || 'Device' },
      { path: '', breadcrumbName: route.params.interface as string },
    ]"
    class="ninjadash-page-header-main"
  >
    <template #buttons>
      <sdButton v-if="storedInterface" type="light" @click="openQr"><unicon name="qrcode-scan"></unicon> QR code</sdButton>
      <sdButton type="primary" @click="reloadAll('device')"><unicon name="redo"></unicon> Reload info</sdButton>
    </template>
  </sdPageHeader>
  <Main>
    <a-skeleton v-if="loading" active />
    <div v-else class="id-grid">
      <div class="id-grid__main">
        <sdCards :headless="true">
          <OntDetailPanel v-if="ifaceType === 'ONU'" ref="panelRef" :device-id="deviceId" :interface-id="interfaceId" :modules="modules" :device-calling="deviceCalling" />
          <SwitchInterfaceDetailPanel v-else-if="ifaceType === 'SWITCH'" ref="panelRef" :device-id="deviceId" :interface-id="interfaceId" :device-calling="deviceCalling" />
          <PhysicalInterfaceDetailPanel v-else ref="panelRef" :device-id="deviceId" :interface-id="interfaceId" :modules="modules" :device-calling="deviceCalling" />
        </sdCards>

        <template v-if="storedInterface">
          <a-row :gutter="20">
            <a-col :xs="24" :lg="12">
              <StorageInfoCard :stored-interface="storedInterface" @saved="loadShell" />
              <IfaceDownHistoryCard :interface-db-id="storedInterface.id" :interface-type="storedInterface.type" />
            </a-col>
            <a-col :xs="24" :lg="12">
              <InterfaceTagsCard :interface-db-id="storedInterface.id" :favorite="favorite" @toggle-favorite="toggleFavorite" />
              <InterfaceEventsCard :device-id="deviceId" :interface-bind-key="storedInterface.bind_key" :interface-name="storedInterface.name" />
            </a-col>
          </a-row>
          <MacrosCard :device-id="deviceId" :interface-bind-key="storedInterface.bind_key" />
        </template>
      </div>
      <div class="id-grid__side">
        <DeviceCallingCard :device-calling="deviceCalling" />
      </div>
    </div>

    <a-modal v-model:visible="qrModalOpen" title="Interface QR code" :footer="null" width="420px">
      <a-skeleton v-if="qrLoading" active />
      <div v-else-if="qrImage" style="text-align: center">
        <img :src="qrImage" style="max-width: 100%" alt="QR code" />
      </div>
      <p v-else>Could not load QR code.</p>
    </a-modal>
  </Main>
</template>

<style scoped>
.id-grid {
  display: grid;
  grid-template-columns: 17fr 7fr;
  align-items: start;
  gap: 25px;
}
@media (max-width: 767px) {
  .id-grid {
    grid-template-columns: 1fr;
  }
}
</style>
