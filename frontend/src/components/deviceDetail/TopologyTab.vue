<!--
  The OLT dashboard's "topology" tab (real component name "UplinkTree"):
  the upward path to this device's core/root device, plus the links
  attached directly to it. Reuses the same tree endpoint and link-list
  pattern already built for the standalone Topology tree page and the
  device edit form's Links card.
-->
<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { DataService } from '@/config/dataService/dataService';
import { Modal, notification } from 'ant-design-vue';
import { wsClient } from '@/services/wsClient';
import TopologyTreeNode from '@/components/topology/TopologyTreeNode.vue';
import { deviceStatusColor, ifaceStatusColor, statusIconUri } from '@/utility/deviceTypeIcons';

const props = defineProps<{ deviceId: number }>();

const upwardTree = ref<any>(null);
const upwardLoading = ref(false);
const upwardRootNotFound = ref(false);
async function loadUpward() {
  upwardLoading.value = true;
  upwardRootNotFound.value = false;
  upwardTree.value = null;
  try {
    const { data } = await DataService.get('/component/links/view/tree', { device_id: props.deviceId, direction: 'up' });
    upwardTree.value = data.data;
  } catch (err: any) {
    if (err?.response?.status === 400 || err?.response?.status === 500) upwardRootNotFound.value = true;
  } finally {
    upwardLoading.value = false;
  }
}

interface LinkRow {
  id: number;
  src_device: { id: number; name: string; ip: string; model?: { type?: string }; model_type?: string | null; status?: string | null };
  dest_device: { id: number; name: string; ip: string; model?: { type?: string }; model_type?: string | null; status?: string | null };
  src_iface: { id: number; name: string; status?: string } | null;
  dest_iface: { id: number; name: string; status?: string } | null;
  source: 'manual' | 'fdb' | 'lldp';
  utilization: number | null;
}
// A link is "down" the same way the topology tree already colours its
// iface pills: either end reporting a real Down status wins, since a link
// with a dead interface is down regardless of any stale utilization it
// logged before that. No status on either end (e.g. an LLDP neighbor whose
// far-end interface never resolved) reads as unknown, not down.
function linkStatus(l: LinkRow): 'up' | 'down' | 'unknown' {
  const statuses = [l.src_iface?.status, l.dest_iface?.status].filter(Boolean);
  if (statuses.some((s) => s === 'Down')) return 'down';
  if (statuses.length && statuses.every((s) => s === 'Up')) return 'up';
  return 'unknown';
}
const links = ref<LinkRow[]>([]);
const linksLoading = ref(false);
async function loadLinks() {
  linksLoading.value = true;
  try {
    const { data } = await DataService.get(`/component/links/by-device/${props.deviceId}`);
    links.value = data.data || [];
  } catch {
    links.value = [];
  } finally {
    linksLoading.value = false;
  }
}
function confirmDeleteLink(link: LinkRow) {
  Modal.confirm({
    title: 'Delete this link?',
    okText: 'Delete',
    okType: 'danger',
    onOk: async () => {
      try {
        await DataService.delete(`/component/links/${link.id}`);
        links.value = links.value.filter((l) => l.id !== link.id);
        notification.success({ message: 'Link deleted' });
      } catch (err: any) {
        notification.error({ message: 'Could not delete link', description: err?.response?.data?.error?.description || 'Please try again.' });
      }
    },
  });
}

// Real LLDP neighbor table, cross-referenced server-side against this
// system's own device inventory by chassis MAC — independent of the
// FDB/MAC-learning-based Links data above, since it comes straight from
// each device's own SNMP-reported Layer-2 neighbors.
//
// Whether this device's model supports LLDP is decided by the BACKEND
// (`core->isModuleExist('lldp_info')`, returned here as `data.supported`)
// — NOT by a `props.modules`-based pre-check on this side. There used to
// be one here, and it was a real bug: `props.modules` comes from a
// SEPARATE, parallel async fetch in the parent (`systemInfo`), so at the
// moment this tab mounted, modules could easily still be `[]` — the
// pre-check would then skip the LLDP fetch entirely and never retry once
// modules actually arrived (nothing here was watching it), so the card
// would silently sit at "no neighbors" forever, even for a device that
// genuinely supports LLDP. Fixed by always asking the backend and trusting
// its answer instead of racing a prop that loads on its own schedule.
interface LldpNeighbor {
  loc_interface: { id: number; name: string } | null;
  rem_chassis_id: string | null;
  rem_interface: string | null;
  matched_device: { id: number; name: string; ip: string; model_type?: string | null; status?: string | null } | null;
}
const lldpSupported = ref(false);
const lldpChassisId = ref<string | null>(null);
const lldpNeighbors = ref<LldpNeighbor[]>([]);
const lldpLoading = ref(false);
async function loadLldp() {
  lldpLoading.value = true;
  try {
    const { data } = await DataService.get(`/component/links/lldp-neighbors/${props.deviceId}`);
    lldpSupported.value = !!data.data?.supported;
    lldpChassisId.value = data.data?.local_chassis_id ?? null;
    lldpNeighbors.value = data.data?.neighbors || [];
  } catch {
    lldpSupported.value = false;
    lldpNeighbors.value = [];
  } finally {
    lldpLoading.value = false;
  }
}

async function loadInfo() {
  await Promise.all([loadUpward(), loadLinks(), loadLldp()]);
}
defineExpose({ loadInfo });
onMounted(() => loadInfo());

// Real-time: a links-table change anywhere (the generic signal carries no
// device id) or a poller finishing for this device refreshes this tab.
const unsubLinks = wsClient.subscribe('event:storage:c_links:updated', () => loadInfo());
const unsubLinksAdded = wsClient.subscribe('event:storage:c_links:added', () => loadInfo());
const unsubPoller = wsClient.subscribe('event:poller:finished', (msg) => {
  if (msg.data?.device?.id === props.deviceId) loadInfo();
});
onBeforeUnmount(() => {
  unsubLinks();
  unsubLinksAdded();
  unsubPoller();
});
</script>

<template>
  <div>
    <sdCards title="Upward topology" style="margin-bottom: 20px">
      <a-skeleton v-if="upwardLoading" active />
      <div v-else-if="upwardRootNotFound" class="topo-hint">No core device found above this device in the topology.</div>
      <a-empty v-else-if="!upwardTree" description="No data" />
      <div v-else class="topo-tree-wrap">
        <ul class="topo-tree-root">
          <TopologyTreeNode :node="upwardTree.tree" :highlight-device-id="deviceId" :depth="0" />
        </ul>
      </div>
    </sdCards>

    <sdCards title="Links">
      <a-skeleton v-if="linksLoading" active />
      <a-empty v-else-if="!links.length" description="No links" />
      <ul v-else class="topo-links-list">
        <li v-for="l in links" :key="l.id">
          <span class="topo-link-row">
            <span class="topo-link-row__status" :class="`is-${linkStatus(l)}`" :title="linkStatus(l)"></span>
            <img
              class="topo-link-row__icon"
              :class="`is-${(l.src_device.status || 'unknown').toLowerCase()}`"
              :src="statusIconUri(l.src_device.model_type, deviceStatusColor(l.src_device.status))"
              :title="`${l.src_device.model_type || ''} — ${l.src_device.status || 'unknown'}`"
            />
            <router-link :to="{ name: 'device-detail', params: { id: l.src_device.id } }">{{ l.src_device.name || l.src_device.ip }}</router-link>
            <span v-if="l.src_iface" class="topo-link-row__iface" :class="l.src_iface.status === 'Down' ? 'is-down' : l.src_iface.status === 'Up' ? 'is-up' : ''">{{ l.src_iface.name }}</span>
            <unicon name="arrow-right" width="13" height="13"></unicon>
            <span v-if="l.dest_iface" class="topo-link-row__iface" :class="l.dest_iface.status === 'Down' ? 'is-down' : l.dest_iface.status === 'Up' ? 'is-up' : ''">{{ l.dest_iface.name }}</span>
            <img
              class="topo-link-row__icon"
              :class="`is-${(l.dest_device.status || 'unknown').toLowerCase()}`"
              :src="statusIconUri(l.dest_device.model_type, deviceStatusColor(l.dest_device.status))"
              :title="`${l.dest_device.model_type || ''} — ${l.dest_device.status || 'unknown'}`"
            />
            <router-link :to="{ name: 'device-detail', params: { id: l.dest_device.id } }">{{ l.dest_device.name || l.dest_device.ip }}</router-link>
            <a-tag v-if="l.source === 'lldp'" color="purple" class="topo-link-row__tag">LLDP</a-tag>
            <a-tag v-else-if="l.source === 'fdb'" color="blue" class="topo-link-row__tag">FDB</a-tag>
            <span v-if="l.utilization != null" class="topo-links-list__util">{{ l.utilization }}%</span>
          </span>
          <a class="topo-links-list__delete" title="Delete link" @click="confirmDeleteLink(l)"><unicon name="trash-alt"></unicon></a>
        </li>
      </ul>
    </sdCards>

    <sdCards v-if="lldpSupported" title="LLDP neighbors" style="margin-top: 20px">
      <a-skeleton v-if="lldpLoading" active />
      <a-empty v-else-if="!lldpNeighbors.length" description="No LLDP neighbors currently reported by this device" />
      <template v-else>
        <p v-if="lldpChassisId" class="topo-hint" style="padding: 0 0 12px">This device's chassis ID: <strong>{{ lldpChassisId }}</strong></p>
        <ul class="topo-links-list">
          <li v-for="(n, i) in lldpNeighbors" :key="i">
            <span class="topo-link-row">
              <span class="topo-link-row__iface">{{ n.loc_interface?.name || '?' }}</span>
              <unicon name="arrow-right" width="13" height="13"></unicon>
              <template v-if="n.matched_device">
                <img
                  class="topo-link-row__icon"
                  :class="`is-${(n.matched_device.status || 'unknown').toLowerCase()}`"
                  :src="statusIconUri(n.matched_device.model_type, deviceStatusColor(n.matched_device.status))"
                  :title="`${n.matched_device.model_type || ''} — ${n.matched_device.status || 'unknown'}`"
                />
                <router-link :to="{ name: 'device-detail', params: { id: n.matched_device.id } }">
                  {{ n.matched_device.name }} ({{ n.matched_device.ip }})
                </router-link>
              </template>
              <span v-else class="topo-hint-inline"><unicon name="question-circle" width="13" height="13"></unicon>{{ n.rem_chassis_id || 'unknown device' }}</span>
              <span v-if="n.rem_interface" class="topo-links-list__util">port: {{ n.rem_interface }}</span>
            </span>
          </li>
        </ul>
      </template>
    </sdCards>
  </div>
</template>

<style scoped>
.topo-hint {
  font-size: 13px;
  color: #8c90a4;
  text-align: center;
  padding: 20px 0;
}
.topo-tree-wrap {
  overflow-x: auto;
}
.topo-tree-root {
  list-style: none;
  margin: 0;
  padding: 0;
  min-width: 500px;
}
.topo-links-list {
  list-style: none;
  margin: 0;
  padding: 0;
}
.topo-links-list li {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8px 4px;
  border-bottom: 1px solid #f0f1f5;
  font-size: 13px;
}
.topo-links-list__util {
  font-size: 11px;
  color: #8c90a4;
}
.topo-links-list__delete {
  color: #8c90a4;
}
.topo-links-list__delete:hover {
  color: #ef4444;
}
.topo-link-row {
  display: flex;
  align-items: center;
  gap: 7px;
  flex-wrap: wrap;
}
.topo-link-row__icon {
  width: 22px;
  height: 22px;
  border-radius: 50%;
  flex: none;
  /* Bold, colour-matched ring around the device icon — same green-up/
     red-down convention as the link status dot and the graph page, made
     thick enough to read at a glance instead of a hairline. */
  border: 6px solid #c1c4d6;
  box-sizing: content-box;
  padding: 1px;
}
/* Full-strength solid colour — the icon itself is the device-type colour,
   this ring is the up/down status colour; two solid colours side by side,
   not one blended together. */
.topo-link-row__icon.is-up {
  border-color: #16a34a;
  box-shadow: 0 0 0 3px rgba(22, 163, 74, 0.3);
}
.topo-link-row__icon.is-down {
  border-color: #ef4444;
  box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.35);
}
/* Green = up, red = down — same convention as the topology tree's iface
   pills, so a link's health reads identically wherever it's shown. */
.topo-link-row__status {
  width: 9px;
  height: 9px;
  border-radius: 50%;
  flex: none;
  background: #c1c4d6;
}
.topo-link-row__status.is-up {
  background: #16a34a;
}
.topo-link-row__status.is-down {
  background: #ef4444;
  box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.2);
}
.topo-link-row__iface {
  font-size: 11px;
  font-weight: 700;
  padding: 1px 6px;
  border-radius: 3px;
  background: #f4f5f9;
  color: #5a5f7d;
}
.topo-link-row__iface.is-up {
  background: rgb(129, 230, 129);
  color: #1a3d1a;
}
.topo-link-row__iface.is-down {
  background: rgb(255, 80, 80);
  color: #fff;
}
.topo-link-row__tag {
  font-size: 10px;
  line-height: 16px;
  margin: 0;
}
.topo-hint-inline {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  color: #8c90a4;
}
</style>
