<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { DataService } from '@/config/dataService/dataService';
import { notification } from 'ant-design-vue';
import { api } from '@/api/client';
import { authBackend } from '@/auth/session';
import { Main } from '../styled';
import TopologyTreeNode from '@/components/topology/TopologyTreeNode.vue';
import { legacySource, newApiSource, type DeviceOption, type TreeView } from './topologyTree';
import type { Id } from './linksList';

// With the new login the page reads the new API (src/views/links/topologyTree.ts); legacy is unchanged.
const source = authBackend() === 'cybersathy' ? newApiSource(api) : legacySource(DataService as any);

const route = useRoute();
const router = useRouter();
const loading = ref(false);
const deviceOptions = ref<DeviceOption[]>([]);
const selectedDevice = ref<Id | undefined>(undefined);
const mode = ref<'none' | 'up' | 'down'>('none');
const tree = ref<TreeView | null>(null);
const rootNotFound = ref(false);
const truncated = ref(false);

async function loadDevices() {
  try {
    deviceOptions.value = await source.devices();
  } catch (err) {
    notification.error({ message: 'Could not load devices', description: source.errorMessage(err) });
  }
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
    const result = await source.tree(selectedDevice.value, direction);
    tree.value = result.tree;
    rootNotFound.value = result.rootNotFound;
    truncated.value = result.truncated;
  } catch (err) {
    notification.error({ message: 'Could not load topology', description: source.errorMessage(err) });
  } finally {
    loading.value = false;
  }
}

onMounted(async () => {
  await loadDevices();
  if (typeof route.query.device_id === 'string' && route.query.device_id) {
    selectedDevice.value = source.parseId(route.query.device_id);
    go(route.query.direction === 'up' ? 'up' : 'down');
  }
});
</script>

<template>
  <sdPageHeader
    :routes="[
      { path: '/', breadcrumbName: 'Dashboard' },
      { path: '', breadcrumbName: 'Topology (tree view)' },
    ]"
    class="ninjadash-page-header-main"
  />
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
                :options="deviceOptions.map((d) => ({ value: d.id, label: d.label }))"
              />
            </a-col>
            <a-col :xs="12" :md="5" style="margin-bottom: 12px">
              <sdButton type="primary" block @click="go('up')"
                ><unicon name="angle-double-up"></unicon> From core device</sdButton
              >
            </a-col>
            <a-col :xs="12" :md="5" style="margin-bottom: 12px">
              <sdButton type="primary" block @click="go('down')"
                ><unicon name="angle-double-down"></unicon> From device</sdButton
              >
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
            <a-alert
              v-if="truncated"
              type="warning"
              show-icon
              style="margin-bottom: 12px"
              message="The tree is deeper than 15 levels; the deepest devices are not shown."
            />
            <ul class="topo-tree-root">
              <TopologyTreeNode
                :node="tree"
                :load-link-info="source.linkInfo"
                :highlight-device-id="selectedDevice"
                :depth="0"
              />
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
