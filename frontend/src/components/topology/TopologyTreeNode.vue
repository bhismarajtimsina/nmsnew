<!--
  Recursive tree row for Topology (tree view). The node shape comes from src/views/links/topologyTree.ts, which maps
  both legacy's tree (matched field-for-field to the production app's own TreeNode) and the new API's onto it: each
  node has its device, its state, and (except the root) the link and the interface pair joining it to its parent.
  Under the new API a device outside the viewer's scope is a placeholder and a device met again in a ring is a repeat;
  neither is expanded.
-->
<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { containsDevice, countBelow, type IfaceBadge, type LinkInfo, type TreeView } from '@/views/links/topologyTree';
import type { Id } from '@/views/links/linksList';

const props = withDefaults(
  defineProps<{
    node: TreeView;
    loadLinkInfo: (linkId: Id) => Promise<LinkInfo | null>;
    highlightDeviceId?: Id | null;
    depth?: number;
  }>(),
  { highlightDeviceId: null, depth: 0 },
);

const expanded = ref(false);
const linkPopoverOpen = ref(false);
const linkInfo = ref<LinkInfo | null>(null);
const linkInfoLoading = ref(false);

const isHighlighted = computed(
  () => props.node.deviceId != null && String(props.node.deviceId) === String(props.highlightDeviceId),
);
const totalCount = computed(() => countBelow(props.node));
const onlineCount = computed(() => countBelow(props.node, true));
const badgeClass = (badge: IfaceBadge) =>
  badge.state === 'up' ? 'is-up' : badge.state === 'down' ? 'is-down' : 'is-unknown';
const badgeTitle = (badge: IfaceBadge) => (badge.description ? `${badge.name} - ${badge.description}` : badge.name);

async function openLinkInfo() {
  if (!props.node.linkId) return;
  linkPopoverOpen.value = true;
  linkInfoLoading.value = true;
  linkInfo.value = await props.loadLinkInfo(props.node.linkId);
  linkInfoLoading.value = false;
}

onMounted(() => {
  if (containsDevice(props.node, props.highlightDeviceId)) expanded.value = true;
});
</script>

<template>
  <li :class="`tree-depth-${depth}`">
    <div class="topo-node" :class="{ 'topo-node--highlight': isHighlighted }">
      <a v-if="node.linkId" class="topo-node__info" title="Link info" @click.prevent="openLinkInfo">
        <unicon name="info-circle"></unicon>
      </a>
      <a-popover v-if="node.linkId" v-model:visible="linkPopoverOpen" trigger="click" placement="top">
        <template #content>
          <div class="topo-link-info">
            <a-skeleton v-if="linkInfoLoading" active :paragraph="{ rows: 2 }" />
            <template v-else-if="linkInfo">
              <p><strong>Utilization:</strong> {{ linkInfo.utilization != null ? linkInfo.utilization + '%' : '—' }}</p>
              <p><strong>Speed:</strong> {{ linkInfo.speed ?? '—' }}</p>
              <p><strong>Created:</strong> {{ linkInfo.createdAt ?? '—' }}</p>
            </template>
            <p v-else>Could not load link info.</p>
          </div>
        </template>
        <span></span>
      </a-popover>

      <span
        v-if="node.parentIface"
        class="topo-node__iface topo-node__iface--src"
        :class="badgeClass(node.parentIface)"
        :title="badgeTitle(node.parentIface)"
      >
        {{ node.parentIface.name }}
      </span>
      <span v-else-if="depth !== 0" class="topo-node__iface topo-node__iface--src is-down" title="n/a">n/a</span>
      <span v-else class="topo-node__iface topo-node__iface--src is-root">S</span>

      <span
        v-if="node.ownIface"
        class="topo-node__iface topo-node__iface--dest"
        :class="badgeClass(node.ownIface)"
        :title="badgeTitle(node.ownIface)"
      >
        {{ node.ownIface.name }}
      </span>
      <span v-else-if="depth !== 0" class="topo-node__iface topo-node__iface--dest is-down" title="n/a">n/a</span>

      <router-link
        v-if="node.visible"
        :to="{ name: 'device-detail', params: { id: node.deviceId } }"
        target="_blank"
        class="topo-node__ip"
        :class="`is-${node.state}`"
        :title="node.title ?? undefined"
      >
        {{ node.ip }}
      </router-link>
      <span v-else class="topo-node__ip" :class="`is-${node.state}`">Outside your scope</span>

      <a
        v-if="node.nodes.length"
        class="topo-node__arrow"
        href="javascript:void(0)"
        @click.prevent="expanded = !expanded"
      >
        {{ expanded ? '▼' : '►' }}
      </a>
      <span v-else class="topo-node__arrow-spacer"></span>

      <span v-if="node.visible" class="topo-node__name">{{ node.name || 'Name not set' }}</span>
      <span v-if="node.repeat" class="topo-node__repeat" title="Already shown elsewhere in this tree (a ring)"
        >repeat</span
      >

      <span
        v-if="totalCount !== 0"
        class="topo-node__stat"
        :class="{ 'is-empty': onlineCount === 0, 'is-partial': onlineCount !== totalCount && onlineCount !== 0 }"
      >
        {{ onlineCount }} / {{ totalCount }}
      </span>
    </div>

    <ul v-if="node.nodes.length" v-show="expanded" class="topo-node__children">
      <TopologyTreeNode
        v-for="(child, idx) in node.nodes"
        :key="`${child.linkId ?? child.deviceId}-${idx}`"
        :node="child"
        :load-link-info="loadLinkInfo"
        :highlight-device-id="highlightDeviceId"
        :depth="depth + 1"
      />
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
.topo-node__ip.is-unknown,
.topo-node__iface.is-unknown {
  background: #b5b9c9;
}
.topo-node__repeat {
  font-size: 11px;
  color: #8c90a4;
  font-style: italic;
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
