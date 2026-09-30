<!--
  Recursive tree row for Topology (tree view) — matches the real production
  app's own TreeNode component field-for-field (reverse-engineered from its
  compiled bundle, then confirmed live against `GET /component/links/view/tree`):
  each node carries device_id/device_ip/name/latency/nodes[], plus (for every
  node except the root) the link and interface pair that connects it to its
  parent — src/dest iface name+status, coloured green when Up/Online.
-->
<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { DataService } from '@/config/dataService/dataService';

interface TreeNode {
  device_id: number;
  device_ip: string;
  name: string;
  model_name?: string;
  location?: string;
  latency: number;
  nodes: TreeNode[];
  link_id?: number | null;
  src_iface_name?: string;
  src_iface_description?: string;
  src_iface_status?: string;
  dest_iface_name?: string;
  dest_iface_description?: string;
  dest_iface_status?: string;
}

const props = withDefaults(defineProps<{ node: TreeNode; highlightDeviceId?: number | null; depth?: number }>(), {
  highlightDeviceId: null,
  depth: 0,
});

const expanded = ref(false);
const linkPopoverOpen = ref(false);
const linkInfo = ref<any>(null);
const linkInfoLoading = ref(false);

const isHighlighted = computed(() => props.node.device_id === props.highlightDeviceId);
const isUp = computed(() => props.node.latency > 0);

function countNodes(node: TreeNode, onlyOnline = false): number {
  if (!node.nodes || !node.nodes.length) return 0;
  let count = onlyOnline ? node.nodes.filter((n) => n.latency > 0).length : node.nodes.length;
  for (const child of node.nodes) count += countNodes(child, onlyOnline);
  return count;
}
const totalCount = computed(() => countNodes(props.node));
const onlineCount = computed(() => countNodes(props.node, true));

function containsHighlighted(node: TreeNode): boolean {
  if (!node.nodes) return false;
  for (const child of node.nodes) {
    if (child.device_id === props.highlightDeviceId || containsHighlighted(child)) return true;
  }
  return false;
}

function ifaceStatusOk(status?: string) {
  return status === 'Up' || status === 'Online';
}

async function openLinkInfo() {
  if (!props.node.link_id) return;
  linkPopoverOpen.value = true;
  linkInfoLoading.value = true;
  try {
    const { data } = await DataService.get(`/component/links/${props.node.link_id}`, { with_stat: 'yes' });
    linkInfo.value = data.data;
  } catch {
    linkInfo.value = null;
  } finally {
    linkInfoLoading.value = false;
  }
}

onMounted(() => {
  if (containsHighlighted(props.node)) expanded.value = true;
});
</script>

<template>
  <li :class="`tree-depth-${depth}`">
    <div class="topo-node" :class="{ 'topo-node--highlight': isHighlighted }">
      <a v-if="node.link_id" class="topo-node__info" title="Link info" @click.prevent="openLinkInfo">
        <unicon name="info-circle"></unicon>
      </a>
      <a-popover v-if="node.link_id" v-model:visible="linkPopoverOpen" trigger="click" placement="top">
        <template #content>
          <div class="topo-link-info">
            <a-skeleton v-if="linkInfoLoading" active :paragraph="{ rows: 2 }" />
            <template v-else-if="linkInfo">
              <p><strong>Utilization:</strong> {{ linkInfo.utilization != null ? linkInfo.utilization + '%' : '—' }}</p>
              <p><strong>Speed:</strong> {{ linkInfo.speed ?? '—' }}</p>
              <p><strong>Created:</strong> {{ linkInfo.created_at ?? '—' }}</p>
            </template>
            <p v-else>Could not load link info.</p>
          </div>
        </template>
        <span></span>
      </a-popover>

      <span
        v-if="node.src_iface_name"
        class="topo-node__iface topo-node__iface--src"
        :class="ifaceStatusOk(node.src_iface_status) ? 'is-up' : 'is-down'"
        :title="`${node.src_iface_name} - ${node.src_iface_description || ''}`"
      >
        {{ node.src_iface_name }}
      </span>
      <span v-else-if="depth !== 0" class="topo-node__iface topo-node__iface--src is-down" title="n/a">n/a</span>
      <span v-else class="topo-node__iface topo-node__iface--src is-root">S</span>

      <span
        v-if="node.dest_iface_name"
        class="topo-node__iface topo-node__iface--dest"
        :class="ifaceStatusOk(node.dest_iface_status) ? 'is-up' : 'is-down'"
        :title="`${node.dest_iface_name} - ${node.dest_iface_description || ''}`"
      >
        {{ node.dest_iface_name }}
      </span>
      <span v-else-if="depth !== 0" class="topo-node__iface topo-node__iface--dest is-down" title="n/a">n/a</span>

      <router-link
        :to="{ name: 'device-detail', params: { id: node.device_id } }"
        target="_blank"
        class="topo-node__ip"
        :class="isUp ? 'is-up' : 'is-down'"
        :title="node.model_name"
      >
        {{ node.device_ip }}
      </router-link>

      <a v-if="node.nodes?.length" class="topo-node__arrow" href="javascript:void(0)" @click.prevent="expanded = !expanded">
        {{ expanded ? '▼' : '►' }}
      </a>
      <span v-else class="topo-node__arrow-spacer"></span>

      <span class="topo-node__name">{{ node.name || 'Name not set' }}</span>

      <span v-if="totalCount !== 0" class="topo-node__stat" :class="{ 'is-empty': onlineCount === 0, 'is-partial': onlineCount !== totalCount && onlineCount !== 0 }">
        {{ onlineCount }} / {{ totalCount }}
      </span>
    </div>

    <ul v-if="node.nodes?.length" v-show="expanded" class="topo-node__children">
      <TopologyTreeNode v-for="(child, idx) in node.nodes" :key="child.device_ip + '-' + idx" :node="child" :highlight-device-id="highlightDeviceId" :depth="depth + 1" />
    </ul>
  </li>
</template>

<style scoped>
.topo-node {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 4px 6px;
  border-radius: 4px;
  white-space: nowrap;
}
.topo-node--highlight {
  background: rgb(255, 191, 0);
}
.topo-node__info {
  color: #5a5f7d;
  display: flex;
}
.topo-node__info :deep(svg) {
  width: 13px;
  height: 13px;
}
.topo-node__iface {
  font-size: 11px;
  font-weight: 700;
  color: #fff;
  border-radius: 3px;
  padding: 1px 6px;
}
.topo-node__iface.is-up {
  background: rgb(129, 230, 129);
  color: #1a3d1a;
}
.topo-node__iface.is-down {
  background: rgb(255, 80, 80);
}
.topo-node__iface.is-root {
  background: #dadada;
  color: #272b41;
}
.topo-node__ip {
  font-weight: 700;
  padding: 1px 8px;
  border-radius: 3px;
  color: #fff !important;
}
.topo-node__ip.is-up {
  background: rgb(129, 230, 129);
  color: #1a3d1a !important;
}
.topo-node__ip.is-down {
  background: rgb(255, 80, 80);
}
.topo-node__arrow {
  color: #5a5f7d;
  font-size: 11px;
  width: 14px;
  display: inline-block;
  text-align: center;
}
.topo-node__arrow-spacer {
  width: 14px;
  display: inline-block;
}
.topo-node__name {
  color: #272b41;
}
.topo-node__stat {
  font-size: 11px;
  font-weight: 700;
  border-radius: 999px;
  padding: 1px 8px;
  background: rgba(26, 122, 58, 0.12);
  color: #1a7a3a;
}
.topo-node__stat.is-partial {
  background: rgba(212, 160, 23, 0.15);
  color: #ab7d0a;
}
.topo-node__stat.is-empty {
  background: rgba(166, 10, 10, 0.12);
  color: #a60a0a;
}
.topo-node__children {
  list-style: none;
  margin: 0;
  padding-left: 26px;
  border-left: 1px dashed #e6e9f1;
}
.topo-link-info p {
  margin: 0 0 4px;
  font-size: 12px;
}
</style>
