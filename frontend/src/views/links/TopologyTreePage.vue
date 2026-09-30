<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { DataService } from '@/config/dataService/dataService';
import { notification } from 'ant-design-vue';
import { Main } from '../styled';
import TopologyTreeNode from '@/components/topology/TopologyTreeNode.vue';

const route = useRoute();
const router = useRouter();
const loading = ref(false);
const deviceOptions = ref<{ id: number; display_name: string }[]>([]);
const selectedDevice = ref<number | undefined>(undefined);
const mode = ref<'none' | 'up' | 'down'>('none');
const tree = ref<any>(null);
const rootNotFound = ref(false);

async function loadDevices() {
  const { data } = await DataService.get('/device/options');
  deviceOptions.value = data.data || [];
}

async function go(direction: 'up' | 'down') {
  if (!selectedDevice.value) {
    notification.error({ message: 'Choose a device first' });
    return;
  }
  mode.value = direction;
  rootNotFound.value = false;
  tree.value = null;
  loading.value = true;
  router.replace({ query: { device_id: String(selectedDevice.value), direction } });
  try {
    const { data } = await DataService.get('/component/links/view/tree', { device_id: selectedDevice.value, direction });
    tree.value = data.data;
  } catch (err: any) {
    // The backend has a real bug here — it means to respond 400 with a
    // structured ROOT_DEVICE_NOT_FOUND error (per its own frontend's error
    // handling) when a device has no core/root device above it, but that
    // exception currently escapes uncaught as a raw 500. Detected here so
    // "From core device" still degrades to a clear message instead of a
    // blank/broken page.
    if (direction === 'up' && (err?.response?.status === 400 || err?.response?.status === 500)) {
      rootNotFound.value = true;
    } else {
      notification.error({ message: 'Could not load topology', description: err?.response?.data?.error?.description || 'Please try again.' });
    }
  } finally {
    loading.value = false;
  }
}

onMounted(async () => {
  await loadDevices();
  if (route.query.device_id) {
    selectedDevice.value = Number(route.query.device_id);
    const direction = route.query.direction === 'up' ? 'up' : 'down';
    go(direction);
  }
});
</script>

<template>
  <sdPageHeader :routes="[{ path: '/', breadcrumbName: 'Dashboard' }, { path: '', breadcrumbName: 'Topology (tree view)' }]" class="ninjadash-page-header-main" />
  <Main>
    <a-row :gutter="25">
      <a-col :span="24">
        <sdCards title="Filters" style="margin-bottom: 16px">
          <a-row :gutter="16">
            <a-col :xs="24" :md="14" style="margin-bottom: 12px">
              <label class="log-filter-label">Device</label>
              <a-select
                v-model:value="selectedDevice"
                allow-clear
                show-search
                placeholder="Choose device"
                style="width: 100%"
                :filter-option="(input: string, opt: any) => opt.label.toLowerCase().includes(input.toLowerCase())"
                :options="deviceOptions.map((d: any) => ({ value: d.id, label: d.display_name }))"
              />
            </a-col>
            <a-col :xs="12" :md="5" style="margin-bottom: 12px">
              <sdButton type="primary" block @click="go('up')"><unicon name="angle-double-up"></unicon> From core device</sdButton>
            </a-col>
            <a-col :xs="12" :md="5" style="margin-bottom: 12px">
              <sdButton type="primary" block @click="go('down')"><unicon name="angle-double-down"></unicon> From device</sdButton>
            </a-col>
          </a-row>
        </sdCards>

        <sdCards :headless="true">
          <a-skeleton v-if="loading" active />
          <div v-else-if="mode === 'none'" class="topo-tree-empty">Select a device and specify the direction</div>
          <div v-else-if="rootNotFound" class="topo-tree-empty">
            <unicon name="exclamation-triangle"></unicon>
            No core device found above this device in the topology.
          </div>
          <a-empty v-else-if="!tree" description="No data" />
          <div v-else class="topo-tree-wrap">
            <ul class="topo-tree-root">
              <TopologyTreeNode :node="tree.tree" :highlight-device-id="selectedDevice" :depth="0" />
            </ul>
          </div>
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
.topo-tree-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  text-align: center;
  color: #8c90a4;
  font-weight: 600;
  padding: 60px 20px;
}
.topo-tree-empty :deep(svg) {
  width: 22px;
  height: 22px;
}
.topo-tree-wrap {
  overflow-x: auto;
  padding: 16px;
}
.topo-tree-root {
  list-style: none;
  margin: 0;
  padding: 0;
  min-width: 600px;
}
</style>
