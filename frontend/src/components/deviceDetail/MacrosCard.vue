<!--
  Runs a "raw macro" (arbitrary CLI command template, built via Configuration
  → Macros — see MacroFormPage.vue) against a live device or one of its
  interfaces. This is the execution counterpart to that admin page: it lists
  macros applicable to this device/interface (GET /component/macros/list,
  filtered server-side by device model + user role + display_for), then
  hands off to RunMacroModal.vue for the actual generate-variables →
  preview → execute flow.

  WAN-add macros (display_for includes 'WAN') used to be excluded here in
  favor of a dedicated "Add WAN" button on the ONU page (OntDetailPanel.vue)
  — that button was removed by request (only ONT registration features are
  needed there), so WAN-tagged macros now show up in this generic list like
  any other macro instead of becoming unreachable.

  Deliberately not built until now (see the removed scoping-out note this
  used to carry in DeviceDetailPage.vue) since "run arbitrary commands
  against a live device" is a materially different feature to sign off on
  than the rest of this app's read-mostly parity work.
-->
<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount } from 'vue';
import { DataService } from '@/config/dataService/dataService';
import RunMacroModal from '@/components/deviceDetail/RunMacroModal.vue';
import { wsClient } from '@/services/wsClient';
import { removeById } from '@/utility/listMerge';

const props = defineProps<{
  deviceId: number;
  interfaceBindKey?: string;
}>();

interface MacroListItem {
  id: number;
  name: string;
  description?: string;
  display_for: string[];
}

const loading = ref(true);
const rows = ref<MacroListItem[]>([]);

async function loadInfo() {
  loading.value = true;
  try {
    const { data } = await DataService.get('/component/macros/list', {
      device_id: props.deviceId,
      ...(props.interfaceBindKey ? { interface_bind_key: props.interfaceBindKey } : {}),
    });
    rows.value = data.data || [];
  } catch {
    rows.value = [];
  } finally {
    loading.value = false;
  }
}
defineExpose({ loadInfo });
onMounted(() => loadInfo());

// Real-time: macros table changed anywhere (no per-device filter available
// in the generic signal) — refetch regardless, still a cheap device-scoped
// list read.
// 'added' stays a full reload — this list is filtered server-side to
// macros applicable to THIS device's model, and the pushed record doesn't
// carry enough to judge that client-side.
// 'updated' is safe to merge directly, but only in place — if the id
// isn't already in this device's list, a global rename elsewhere
// shouldn't make it suddenly appear here. 'deleted' is always safe.
const unsubAdded = wsClient.subscribe('event:storage:c_macros:added', () => loadInfo());
const unsubUpdated = wsClient.subscribe('event:storage:c_macros:updated', (msg) => {
  const idx = rows.value.findIndex((r) => r.id === msg.data.id);
  if (idx !== -1) Object.assign(rows.value[idx], msg.data);
});
const unsubDeleted = wsClient.subscribe('event:storage:c_macros:deleted', (msg) => removeById(rows, msg.data.id));
onBeforeUnmount(() => {
  unsubAdded();
  unsubUpdated();
  unsubDeleted();
});

const modalRef = ref<InstanceType<typeof RunMacroModal> | null>(null);
function openWizard(row: MacroListItem) {
  modalRef.value?.open(row.id);
}
</script>

<template>
  <sdCards title="Macros">
    <a-skeleton v-if="loading" active />
    <a-empty v-else-if="!rows.length" description="No macros configured for this device" />
    <div v-else class="mc-list">
      <div v-for="row in rows" :key="row.id" class="mc-row">
        <div>
          <div class="mc-row__name">{{ row.name }}</div>
          <div v-if="row.description" class="mc-row__desc">{{ row.description }}</div>
        </div>
        <sdButton size="small" type="primary" @click="openWizard(row)"><unicon name="play" width="13" height="13"></unicon> Run</sdButton>
      </div>
    </div>

    <RunMacroModal ref="modalRef" :device-id="deviceId" :interface-bind-key="interfaceBindKey" />
  </sdCards>
</template>

<style scoped>
.mc-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.mc-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 10px 12px;
  border: 1px solid #e6e9f1;
  border-radius: 6px;
}
.mc-row__name {
  font-weight: 600;
  font-size: 13px;
  color: #272b41;
}
.mc-row__desc {
  font-size: 12px;
  color: #8c90a4;
}
</style>
