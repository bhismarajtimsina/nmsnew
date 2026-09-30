<!--
  The OLT device dashboard's default tab (real name "onts_tree"): every PON
  port on this OLT, expandable to the ONUs registered under it, with live
  optical readings. Reverse engineered field-for-field (including the exact
  colour thresholds) from the compiled OntsTree/OntsTreeOntOnPortTable
  components in DeviceInfo-CGuBjiZ6.js — the real per-OLT dashboard shell.
-->
<script setup lang="ts">
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { DataService } from '@/config/dataService/dataService';
import { Modal, notification } from 'ant-design-vue';
import { wsClient } from '@/services/wsClient';
import type { DeviceCalling } from '@/composables/useDeviceCalling';
import { signalColor, tempColor, loadColor, portStatColor } from '@/utility/opticalColors';
import { generateExecutionId, pollMacroProgress } from '@/utility/macroProgress';

const props = defineProps<{ deviceId: number; deviceCalling: DeviceCalling; modules?: string[] }>();
function hasModule(name: string) {
  return (props.modules || []).includes(name);
}

interface PonPort {
  id: number;
  name: string;
  description: string;
  type: string;
  pon_port_size: number | null;
  optical: { tx: number | null; temp: number | null };
}
interface OntRow {
  interface: { id: number; name: string; parent: number };
  status: string;
  bind_status: string | null;
  description: string;
  ident: { value: string; type: string } | null;
  optical: { rx: number | null; olt_rx: number | null; distance: number | null; temp: number | null };
}
interface PortNode {
  id: number;
  name: string;
  description: string;
  optical: { tx: number | null; temp: number | null };
  pon_port_size: number | null;
  onts: Record<number, OntRow & { favorite: boolean; display: boolean }>;
  display: boolean;
  display_children: boolean;
  has_children: boolean;
  stat: { count: number; online: number; offline: number };
}

const loading = ref(true);
const errorMessage = ref('');
const ports = ref<PonPort[] | null>(null);
const onts = ref<OntRow[] | null>(null);
const favorite = reactive<Record<number, boolean>>({});

const hideOnline = ref(false);
const onlyFavorite = ref(false);
const searchInput = ref('');
const searchDebounced = ref('');
const foundCount = ref(0);
let debounceTimer: ReturnType<typeof setTimeout> | null = null;
watch(searchInput, (v) => {
  if (debounceTimer) clearTimeout(debounceTimer);
  debounceTimer = setTimeout(() => {
    searchDebounced.value = v;
  }, 200);
});

const tree = ref<Record<number, PortNode>>({});
const expandedKeys = ref<string[]>([]);

function rebuild() {
  const result: Record<number, PortNode> = {};
  if (!ports.value) {
    tree.value = result;
    return;
  }
  const search = searchDebounced.value.toLowerCase();
  const filterActive = search !== '' || hideOnline.value || onlyFavorite.value;
  ports.value.forEach((p) => {
    result[p.id] = {
      id: p.id,
      name: p.name,
      description: p.description,
      optical: p.optical,
      pon_port_size: p.pon_port_size,
      onts: {},
      display: false,
      display_children: filterActive,
      has_children: false,
      stat: { count: 0, online: 0, offline: 0 },
    };
  });
  foundCount.value = 0;
  (onts.value || []).forEach((o) => {
    const parentId = o.interface?.parent;
    if (!parentId || !result[parentId]) return;
    const isFav = !!favorite[o.interface.id];
    const line = [o.interface.name, o.description, o.ident?.value].filter(Boolean).join(' ').toLowerCase();
    const display = !(hideOnline.value && o.status === 'Online') && !(onlyFavorite.value && !isFav) && (search === '' || line.indexOf(search) !== -1);
    result[parentId].onts[o.interface.id] = { ...o, favorite: isFav, display };
    result[parentId].has_children = true;
    result[parentId].stat.count++;
    if (o.status === 'Online') result[parentId].stat.online++;
    else result[parentId].stat.offline++;
    if (display) foundCount.value++;
  });
  for (const key of Object.keys(result)) {
    result[Number(key)].display = Object.values(result[Number(key)].onts).some((o) => o.display);
  }
  tree.value = result;
  expandedKeys.value = Object.values(result)
    .filter((n) => n.display && n.display_children)
    .map((n) => String(n.id));
}
watch([ports, onts, () => ({ ...favorite }), hideOnline, onlyFavorite, searchDebounced], rebuild, { deep: true });

const visiblePorts = computed(() => Object.values(tree.value).filter((n) => n.display));

function expandAll() {
  expandedKeys.value = visiblePorts.value.map((n) => String(n.id));
}
function collapseAll() {
  expandedKeys.value = [];
}

function loadPct(node: PortNode) {
  if (!node.pon_port_size) return null;
  return Math.round((node.stat.count / node.pon_port_size) * 100);
}

async function loadInfo(from: 'cache' | 'device' = 'cache') {
  loading.value = true;
  errorMessage.value = '';
  try {
    const { data } = await DataService.get(`/component/olts/interfaces/pon-ports/${props.deviceId}`, { from });
    ports.value = data.data;
    props.deviceCalling.setMeta(data.meta);
  } catch (err: any) {
    errorMessage.value = err?.response?.data?.error?.description || err.message;
  }
  try {
    const { data } = await DataService.get(`/component/olts/interfaces/onts/${props.deviceId}`, { from });
    onts.value = data.data;
    props.deviceCalling.setMeta(data.meta);
  } catch (err: any) {
    errorMessage.value = err?.response?.data?.error?.description || err.message;
  }
  try {
    const { data } = await DataService.get(`/interface-marks/favorite/${props.deviceId}`);
    Object.keys(favorite).forEach((k) => delete favorite[Number(k)]);
    (data.data || []).forEach((f: any) => (favorite[f.bind_key] = true));
  } catch {
    // non-fatal
  }
  loading.value = false;
}

function setSearchLine(line: string) {
  searchInput.value = line;
}

// Same {command, output, success} shape the macro/registration terminal
// display already uses (RunMacroModal.vue) — ctrl_ont_delete's Huawei
// module now captures each real console step (login/config/undo
// service-port/interface/ont delete) into this instead of returning
// nothing, so delete can show the same kind of transcript.
interface CommandResult {
  command: string;
  output: string;
  success: boolean;
}
const transcriptOpen = ref(false);
const transcriptTitle = ref('');
const transcriptCommands = ref<CommandResult[]>([]);

// Delete/Clear PON now run as real, admin-editable macros (Configuration →
// Macros) instead of the hardcoded ctrl_ont_delete/ctrl_ont_delete_port
// modules, so the exact command sequence can be tweaked without a code
// deploy. Discovered by a marker tag in display_for (ONU_DELETE /
// PON_CLEAR), not a hardcoded name or id — same pattern
// OntDetailPanel.vue's "Add WAN" button already uses for its own 'WAN' tag
// (a macro is tagged with its real interface context, ONU or PON, PLUS
// this marker, so it's still correctly scoped by
// GetMacrossesListByParameters.php's normal ONU/PON filtering and also
// findable here). If more than one macro carries the same marker tag, the
// first one found is used — an admin wanting to change which macro backs
// a button should keep only one so tagged, the same constraint "Add WAN"
// already has for multiple WAN macros of different purposes. Cached per
// tag for the life of this component — the id is deployment-wide, not
// per-row, so one lookup covers every subsequent delete/clear on this tab.
const DELETE_ONT_MACRO_TAG = 'ONU_DELETE';
const CLEAR_PON_MACRO_TAG = 'PON_CLEAR';
const macroIdCache: Record<string, number> = {};
async function resolveMacroId(anyBindKey: string, tag: string): Promise<number> {
  if (macroIdCache[tag] != null) return macroIdCache[tag];
  const { data } = await DataService.get('/component/macros/list', {
    device_id: props.deviceId,
    interface_bind_key: anyBindKey,
  });
  const macro = (data.data || []).find((m: any) => (m.display_for || []).includes(tag));
  if (!macro) throw new Error(`No macro tagged "${tag}" is available for this device's model — check Configuration → Macros.`);
  macroIdCache[tag] = macro.id;
  return macro.id;
}

const deregisteringId = ref<number | null>(null);
// onProgress (optional): live step-by-step updates while the execute()
// call below is still in flight — a delete that genuinely takes over a
// minute (confirmed live) used to show nothing but a spinner the whole
// time. Only wired up by the single-delete confirm dialog below; the
// bulk "Delete offline" loop already has its own, arguably more useful
// X/Y-ONTs progress and doesn't need per-step console output on top of it.
async function deregisterOne(
  o: OntRow,
  onProgress?: (commands: CommandResult[]) => void
): Promise<{ ok: true; commands: CommandResult[] } | { ok: false; error: string }> {
  const executionId = generateExecutionId();
  const progress = onProgress ? pollMacroProgress(executionId, (p) => onProgress(p.commands)) : null;
  try {
    const macroId = await resolveMacroId(String(o.interface.id), DELETE_ONT_MACRO_TAG);
    const { data } = await DataService.post('/component/macros/execute', {
      device: { id: props.deviceId },
      macros: { id: macroId },
      interface: { bind_key: String(o.interface.id) },
      preview: false,
      execution_id: executionId,
    });
    const commands: CommandResult[] = data?.data?.commands || [];
    const failed = commands.find((c) => c.success === false);
    // Mirrors OntDelete.php's own "already gone" grace case (still true of
    // the macro's underlying commands): a retry against an ONT a previous
    // attempt already removed device-side shouldn't read as a fresh
    // failure — the end state the caller wanted is already true.
    if (!failed || /does not exist/i.test(failed.output)) {
      return { ok: true, commands };
    }
    return { ok: false, error: `Command "${failed.command}" failed: ${failed.output}` };
  } catch (err: any) {
    return { ok: false, error: err?.response?.data?.error?.description || err.message || 'Unknown error' };
  } finally {
    progress?.stop();
  }
}

// This tab's `onts` list comes from a separate switcher-core poll cache
// (pon_onts_* modules) — dereg only updates our own device_interfaces
// record, not that cache, so reloading with from=cache right after a
// successful dereg re-reads the exact same stale snapshot and the ONT
// never disappears (confirmed live: dereg genuinely succeeds — same
// backend call, 200 response, logged action — the tree just still shows
// it, looking exactly like it silently failed). Removing it from the
// already-loaded list locally is both correct (we know it's gone) and
// faster than a real re-poll, which the page's own "Reload info" is for.
function removeOntFromLocalState(interfaceId: number) {
  if (onts.value) onts.value = onts.value.filter((o) => o.interface.id !== interfaceId);
}

function confirmDeregister(o: OntRow) {
  Modal.confirm({
    title: 'Are you sure you want to delete (de-register) this ONT?',
    content: o.interface.name,
    okText: 'Delete',
    okType: 'danger',
    onOk: async () => {
      deregisteringId.value = o.interface.id;
      // Opened right away, before the request even completes — filled in
      // live as each step finishes instead of appearing all at once (or
      // not at all, for however long a slow delete takes) when it's done.
      transcriptTitle.value = `Delete — ${o.interface.name}`;
      transcriptCommands.value = [];
      transcriptOpen.value = true;
      const result = await deregisterOne(o, (commands) => {
        transcriptCommands.value = commands;
      });
      if (result.ok) {
        notification.success({ message: 'ONT successfully deleted' });
        removeOntFromLocalState(o.interface.id);
        transcriptCommands.value = result.commands;
        transcriptOpen.value = result.commands.length > 0;
      } else {
        notification.error({ message: 'Could not delete ONT', description: 'error' in result ? result.error : 'Unknown error' });
      }
      deregisteringId.value = null;
    },
  });
}

// --- Bulk delete: every offline ONT on ONE PON port, per-port not global --
// De-registers are run one at a time (not in parallel), with a pause
// between each — confirmed live that back-to-back fresh telnet sessions
// with no gap make the OLT trip over its own session cleanup (a batch of
// 108 failed 101/108 with "Configuration console exit... Username or
// password invalid" before this pacing was added). A failure still gets
// one retry after a longer pause before being given up on; failures are
// collected and reported together at the end rather than stopping the rest.
// Scoped to a single port (not a device-wide button) — a device can have
// hundreds of offline ONTs across many ports, and the point of narrowing
// per-port is to let you clean up one port you're actually working on
// without touching every other port at once.
const bulkDeletingPortId = ref<number | null>(null);
const bulkProgress = reactive({ done: 0, total: 0 });

function offlineInPort(node: PortNode) {
  return Object.values(node.onts).filter((o) => o.status !== 'Online');
}
function sleep(ms: number) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

// Shared by both "Delete all offline" and "Clear PON" below — same paced,
// retry-once, failures-collected loop either way; only which ONTs get
// passed in (and how the trigger confirms first) differs.
async function bulkDeregister(node: PortNode, targets: OntRow[], summaryLabel: string) {
  bulkDeletingPortId.value = node.id;
  bulkProgress.done = 0;
  bulkProgress.total = targets.length;
  const failures: { name: string; error: string }[] = [];
  for (let i = 0; i < targets.length; i++) {
    const o = targets[i];
    deregisteringId.value = o.interface.id;
    let result = await deregisterOne(o);
    if (!result.ok) {
      // A rapid string of fresh telnet sessions can outrun the device's
      // own session cleanup between them, tripping "Configuration console
      // exit... Username or password invalid" (confirmed live: 101/108
      // failed this exact way in one run, with no gap between calls). One
      // retry after a longer pause recovers cleanly once the device has
      // settled.
      await sleep(2000);
      result = await deregisterOne(o);
    }
    if (result.ok) {
      removeOntFromLocalState(o.interface.id);
    } else {
      failures.push({ name: o.interface.name, error: 'error' in result ? result.error : 'Unknown error' });
    }
    bulkProgress.done++;
    if (i < targets.length - 1) await sleep(1200);
  }
  deregisteringId.value = null;
  bulkDeletingPortId.value = null;
  if (!failures.length) {
    notification.success({ message: `${summaryLabel}: all ${targets.length} deleted on ${node.name}` });
  } else {
    notification.error({
      message: `${summaryLabel}: ${targets.length - failures.length}/${targets.length} deleted on ${node.name} — ${failures.length} failed`,
      description: failures
        .slice(0, 5)
        .map((f) => `${f.name}: ${f.error}`)
        .join('\n') + (failures.length > 5 ? `\n…and ${failures.length - 5} more` : ''),
      duration: 0,
    });
  }
}

function confirmBulkDeleteOfflineForPort(node: PortNode) {
  const targets = offlineInPort(node);
  if (!targets.length || bulkDeletingPortId.value !== null) return;
  Modal.confirm({
    title: `Delete all ${targets.length} offline ONT${targets.length === 1 ? '' : 's'} on ${node.name}?`,
    content: 'This de-registers every ONT currently reported offline on this PON port, one at a time. This cannot be undone from here — double-check none of these are just temporarily down before proceeding.',
    okText: `Delete ${targets.length}`,
    okType: 'danger',
    onOk: () => bulkDeregister(node, targets, 'Delete all offline'),
  });
}

// --- Clear PON: every ONT on the port, online included — far more
// dangerous than "Delete all offline" above (that one only ever touches
// ONTs already down; this one will disconnect real, currently-active
// customers on this port). Gated behind typing the exact port name, not
// just a confirm click, since a single misclick here has real customer
// impact and there's no undo.
const clearPonNode = ref<PortNode | null>(null);
const clearPonConfirmText = ref('');
const clearPonOnlineCount = computed(() => (clearPonNode.value ? Object.values(clearPonNode.value.onts).filter((o) => o.status === 'Online').length : 0));
const clearPonTotalCount = computed(() => (clearPonNode.value ? Object.values(clearPonNode.value.onts).length : 0));
const clearPonConfirmMatches = computed(() => !!clearPonNode.value && clearPonConfirmText.value.trim() === clearPonNode.value.name);

function openClearPonConfirm(node: PortNode) {
  if (bulkDeletingPortId.value !== null || !Object.keys(node.onts).length) return;
  clearPonNode.value = node;
  clearPonConfirmText.value = '';
}
// One real device-side bulk command ("ont delete <port> all", via the
// "Clear PON" macro) instead of looping per-ONT like "Delete all offline"
// still does above — genuinely one-shot when the device allows it.
// Confirmed live (see the OltsControl/HuaweiOLT.OntDeletePort backend
// work) that VRP still requires each ONT's service-ports already be
// clear, same precondition as a single delete — on a port where they
// aren't, the device can legitimately report 0 actually removed; this
// does NOT fall back to the per-ONT loop to compensate for that, by
// design, since that fallback would defeat the point of a one-shot
// command and its own confirm-once safety gate above.
async function runClearPon() {
  if (!clearPonNode.value || !clearPonConfirmMatches.value) return;
  const node = clearPonNode.value;
  clearPonNode.value = null;
  bulkDeletingPortId.value = node.id;
  // Opened right away, filled in live — same reasoning as the single
  // delete confirm dialog above (see deregisterOne's onProgress comment).
  transcriptTitle.value = `Clear PON — ${node.name}`;
  transcriptCommands.value = [];
  transcriptOpen.value = true;
  const executionId = generateExecutionId();
  const progress = pollMacroProgress(executionId, (p) => {
    transcriptCommands.value = p.commands;
  });
  try {
    const macroId = await resolveMacroId(String(node.id), CLEAR_PON_MACRO_TAG);
    const { data } = await DataService.post('/component/macros/execute', {
      device: { id: props.deviceId },
      macros: { id: macroId },
      interface: { bind_key: String(node.id) },
      preview: false,
      execution_id: executionId,
    });
    const commands: CommandResult[] = data?.data?.commands || [];
    const last = commands[commands.length - 1];
    const failed = commands.find((c) => c.success === false);
    if (failed) {
      notification.error({ message: 'Clear PON failed', description: `Command "${failed.command}" failed: ${failed.output}`, duration: 0 });
    } else {
      // The device's own summary line, e.g. "Number of ONTs that can be
      // deleted: 67, success: 0" — surfaced as-is rather than assumed.
      const m = last?.output.match(/Number of ONTs that can be deleted:\s*(\d+)\s*,\s*success:\s*(\d+)/i);
      if (m && Number(m[2]) === 0) {
        notification.warning({
          message: `Clear PON: 0/${m[1]} ONTs actually removed on ${node.name}`,
          description: 'The device reported none removed — most likely because they still have active service-port config. This does not retry per-ONT; use "Delete all offline" or delete individually if you need to clear those.',
          duration: 0,
        });
      } else if (m) {
        notification.success({ message: `Clear PON: ${m[2]}/${m[1]} ONTs deleted on ${node.name}` });
      } else {
        notification.success({ message: `Clear PON executed on ${node.name}` });
      }
      // Unlike the old dedicated dereg/clear-pon actions, generic macro
      // execution (ExecuteMacros.php) doesn't know to invalidate this
      // device's pon_onts_* cache afterward — so, unlike a single delete's
      // local-only removal above, this forces one real fresh poll rather
      // than risk showing the same stale pre-clear list a plain from=cache
      // reload would (confirmed earlier this session as a real, confusing
      // "looks like nothing happened" bug for exactly this reason).
      await loadInfo('device');
    }
    transcriptCommands.value = commands;
  } catch (err: any) {
    notification.error({ message: 'Clear PON failed', description: err?.response?.data?.error?.description || err.message, duration: 0 });
  } finally {
    progress.stop();
    bulkDeletingPortId.value = null;
  }
}

defineExpose({ loadInfo, setSearchLine });

// Real-time: a poller cycle finishing for THIS device (any module — cheap
// to just re-read from cache) or an audited action against it (register,
// dereg, description change, ...) means this tree is likely stale — quietly
// re-pull from cache instead of waiting for a manual "Reload info" click.
// Still a cache read, not a live device query, so this doesn't add any
// extra load on the OLT itself.
const unsubPoller = wsClient.subscribe('event:poller:finished', (msg) => {
  if (msg.data?.device?.id === props.deviceId) loadInfo('cache');
});
const unsubAction = wsClient.subscribe('event:sys_action:added', (msg) => {
  if (msg.data?.device?.id === props.deviceId) loadInfo('cache');
});

onMounted(() => loadInfo());
onBeforeUnmount(() => {
  if (debounceTimer) clearTimeout(debounceTimer);
  unsubPoller();
  unsubAction();
});
</script>

<template>
  <div class="onts-tree">
    <a-alert v-if="errorMessage" type="error" :message="errorMessage" show-icon style="margin-bottom: 12px" />

    <div v-if="!loading" class="onts-tree__toolbar">
      <div class="onts-tree__toggles">
        <label><a-switch v-model:checked="hideOnline" size="small" /> Hide online</label>
        <label><a-switch v-model:checked="onlyFavorite" size="small" /> Show only favorite</label>
        <a class="onts-tree__expand-link" @click="expandAll">Expand all</a>
        <a class="onts-tree__expand-link" @click="collapseAll">Collapse all</a>
      </div>
      <div class="onts-tree__search">
        <a-input v-model:value="searchInput" placeholder="Type text for filter" allow-clear>
          <template #prefix><unicon name="search"></unicon></template>
        </a-input>
        <span v-if="searchDebounced" class="onts-tree__found">Found ONTs: {{ foundCount }}</span>
      </div>
    </div>

    <a-skeleton v-if="loading" active />

    <template v-else>
      <a-empty v-if="foundCount === 0 && searchDebounced" :description="`ONTs not found by search '${searchDebounced}'`" />

      <a-collapse v-if="visiblePorts.length" v-model:activeKey="expandedKeys" :bordered="false" ghost class="onts-tree__collapse">
        <a-collapse-panel v-for="node in visiblePorts" :key="String(node.id)" :show-arrow="node.has_children">
          <template #header>
            <div class="pon-port__header">
              <span class="pon-port__name"><unicon name="wifi-router" width="15" height="15"></unicon> {{ node.name }}</span>
              <span v-if="node.stat.count !== 0" class="pon-port__badge" :style="{ background: portStatColor(node.stat) }">
                <unicon name="globe" width="12" height="12"></unicon> {{ node.stat.online }}/{{ node.stat.count }}
              </span>
              <span v-if="node.pon_port_size" class="pon-port__badge" title="Port loading" :style="{ background: loadColor(loadPct(node) || 0) }">
                {{ loadPct(node) }}%
              </span>
              <span v-if="node.description" class="pon-port__description">{{ node.description }}</span>
              <span v-if="node.optical.tx != null" class="pon-port__badge pon-port__badge--plain">{{ node.optical.tx }}dBm</span>
              <span v-if="node.optical.temp != null" class="pon-port__badge" :style="{ background: tempColor(node.optical.temp) }">
                {{ Math.round(node.optical.temp) }} C°
              </span>
              <sdButton
                v-if="hasModule('ctrl_ont_delete') && node.stat.offline > 0"
                size="small"
                type="danger"
                class="pon-port__delete-offline"
                :disabled="bulkDeletingPortId !== null"
                :loading="bulkDeletingPortId === node.id"
                @click.stop="confirmBulkDeleteOfflineForPort(node)"
              >
                <unicon name="trash-alt" width="12" height="12"></unicon>
                {{ bulkDeletingPortId === node.id ? `Deleting ${bulkProgress.done}/${bulkProgress.total}…` : `Delete offline (${node.stat.offline})` }}
              </sdButton>
              <sdButton
                v-if="hasModule('ctrl_ont_delete') && Object.keys(node.onts).length > 0"
                size="small"
                type="dark"
                class="pon-port__clear-pon"
                title="Deletes every ONT on this port, including online ones — not just offline"
                :disabled="bulkDeletingPortId !== null"
                :loading="bulkDeletingPortId === node.id"
                @click.stop="openClearPonConfirm(node)"
              >
                <unicon name="trash-alt" width="12" height="12"></unicon>
                Clear PON
              </sdButton>
            </div>
          </template>

          <div class="onu-table-wrap">
            <table class="onu-table">
              <thead>
                <tr>
                  <th></th>
                  <th>Name</th>
                  <th>Status</th>
                  <th>Description</th>
                  <th>Ident</th>
                  <th>OLT RX</th>
                  <th>ONU RX</th>
                  <th>Distance</th>
                  <th>Temp</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="o in node.onts" v-show="o.display" :key="o.interface.id" class="onu-row">
                  <td class="onu-table__fav">
                    <unicon v-if="o.favorite" class="onu-fav-star" name="star" width="15" height="15"></unicon>
                  </td>
                  <td class="onu-table__name">
                    <span class="onu-dot" :style="{ background: o.status === 'Online' ? '#0E4D00' : '#8F0000' }"></span>
                    <router-link
                      :to="{ name: 'device-interface-detail', params: { id: deviceId, interface: o.interface.id }, query: { type: 'ONU' } }"
                      :style="{ color: o.status === 'Online' ? '#0E4D00' : '#8F0000', fontWeight: 600 }"
                    >{{ o.interface.name }}</router-link>
                  </td>
                  <td>
                    <span class="onu-status" :style="{ background: o.status === 'Online' ? '#0E4D00' : '#8F0000' }">{{ o.bind_status || o.status }}</span>
                  </td>
                  <td><span v-if="o.description" class="onu-table__descr">{{ o.description }}</span></td>
                  <td>
                    <span v-if="o.ident?.value" class="onu-table__sn" :title="o.ident.type === 'SN' ? 'Serial number' : 'MAC address'">
                      <small>{{ o.ident.type }}</small> {{ o.ident.value }}
                    </span>
                  </td>
                  <td>
                    <span v-if="o.optical.olt_rx != null" class="onu-signal" title="OLT RX Optical strength" :style="{ background: signalColor(o.optical.olt_rx) }">{{ o.optical.olt_rx }}dBm</span>
                  </td>
                  <td>
                    <span v-if="o.optical.rx != null" class="onu-signal" title="ONU RX Optical strength" :style="{ background: signalColor(o.optical.rx) }">{{ o.optical.rx }}dBm</span>
                  </td>
                  <td>
                    <span v-if="o.optical.distance != null && o.optical.distance !== 5" class="onu-table__ident" title="ONU distance from OLT">{{ o.optical.distance }}m</span>
                  </td>
                  <td>
                    <span v-if="o.optical.temp != null" class="onu-signal" :style="{ background: tempColor(o.optical.temp) }">{{ Math.round(o.optical.temp) }} C°</span>
                  </td>
                  <td>
                    <a v-if="hasModule('ctrl_ont_delete')" class="onu-delete" title="Delete (de-register)" :class="{ 'is-busy': deregisteringId === o.interface.id }" @click="confirmDeregister(o)">
                      <unicon name="trash-alt" width="14" height="14"></unicon>
                    </a>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </a-collapse-panel>
      </a-collapse>

      <a-empty v-if="onts && onts.length === 0" description="No ONTs found on this device" style="padding-bottom: 10px" />
    </template>

    <a-modal v-model:visible="transcriptOpen" :title="transcriptTitle" :footer="null" width="560">
      <p v-if="(deregisteringId !== null || bulkDeletingPortId !== null) && !transcriptCommands.length" class="onts-tree__transcript-hint">
        Connecting to device…
      </p>
      <p v-else-if="deregisteringId !== null || bulkDeletingPortId !== null" class="onts-tree__transcript-hint">
        Running — updating live as each step completes…
      </p>
      <pre class="onts-tree__transcript">{{ transcriptCommands.map((c) => `> ${c.command}\n${c.output}`).join('\n\n') }}</pre>
    </a-modal>

    <a-modal :visible="clearPonNode !== null" :title="`Clear PON — ${clearPonNode?.name}`" :footer="null" width="480" @update:visible="(v) => { if (!v) clearPonNode = null; }">
      <p class="clear-pon__warning">
        This deletes <b>all {{ clearPonTotalCount }} ONT{{ clearPonTotalCount === 1 ? '' : 's' }}</b> on this port —
        <span v-if="clearPonOnlineCount > 0" class="clear-pon__online-count">including {{ clearPonOnlineCount }} currently <b>online</b></span>
        <span v-else>none currently online</span>,
        one at a time. This is not the same as "Delete offline" above — active customer connections on this port will be disconnected. This cannot be undone from here.
      </p>
      <p class="clear-pon__prompt">Type the port name (<code>{{ clearPonNode?.name }}</code>) to confirm:</p>
      <a-input v-model:value="clearPonConfirmText" :placeholder="clearPonNode?.name" @keyup.enter="runClearPon" />
      <div class="clear-pon__actions">
        <sdButton type="light" size="small" @click="clearPonNode = null">Cancel</sdButton>
        <sdButton type="danger" size="small" :disabled="!clearPonConfirmMatches" @click="runClearPon">
          Clear PON ({{ clearPonTotalCount }})
        </sdButton>
      </div>
    </a-modal>
  </div>
</template>

<style scoped>
.clear-pon__warning {
  color: #272b41;
  line-height: 1.6;
}
.clear-pon__online-count {
  color: #e5484d;
  font-weight: 700;
}
.clear-pon__prompt {
  margin-top: 14px;
  margin-bottom: 6px;
  font-size: 13px;
  color: #8c90a4;
}
.clear-pon__actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 16px;
}
.onts-tree__transcript {
  background: #272b41;
  color: #e6e9f1;
  padding: 12px;
  border-radius: 6px;
  font-size: 12px;
  white-space: pre-wrap;
  word-break: break-word;
}
.onts-tree__transcript-hint {
  font-size: 12px;
  color: #8c90a4;
  margin-bottom: 8px;
}
.onts-tree__toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 16px;
}
.onts-tree__toggles {
  display: flex;
  gap: 20px;
}
.onts-tree__toggles label {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  color: #5a5f7d;
}
.onts-tree__search {
  display: flex;
  align-items: center;
  gap: 10px;
  flex: 1;
  max-width: 360px;
  min-width: 220px;
}
.onts-tree__found {
  font-size: 12px;
  color: #8c90a4;
  white-space: nowrap;
}
.onts-tree__expand-link {
  font-size: 12.5px;
  color: #1868db;
  font-weight: 600;
}
.onts-tree__expand-link:hover {
  text-decoration: underline;
}

.onts-tree__collapse :deep(.ant-collapse-item) {
  background: #fafbfe;
  border: 1px solid #eef0f6;
  border-radius: 10px !important;
  margin-bottom: 10px;
  overflow: hidden;
  transition: box-shadow 0.15s ease, border-color 0.15s ease;
}
.onts-tree__collapse :deep(.ant-collapse-item:hover) {
  border-color: #d6ddf0;
  box-shadow: 0 2px 10px rgba(24, 104, 219, 0.08);
}
.onts-tree__collapse :deep(.ant-collapse-item-active) {
  border-color: #c3cef5;
  box-shadow: 0 4px 14px rgba(24, 104, 219, 0.1);
}
.onts-tree__collapse :deep(.ant-collapse-header) {
  padding: 12px 16px !important;
  align-items: center !important;
}
.onts-tree__collapse :deep(.ant-collapse-content-box) {
  padding: 0 16px 14px !important;
}
.onts-tree__collapse :deep(.ant-collapse-arrow) {
  color: #8c90a4;
}

.pon-port__header {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  width: 100%;
}
.pon-port__delete-offline {
  margin-left: auto;
}
.pon-port__clear-pon {
  margin-left: 6px;
}
.pon-port__name {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-weight: 700;
  color: #272b41;
  min-width: 100px;
}
.pon-port__name :deep(svg) {
  color: #8c90a4;
}
.pon-port__badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  color: #fff;
  font-size: 11px;
  font-weight: 700;
  padding: 2px 9px;
  border-radius: 999px;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.12);
}
.pon-port__badge--plain {
  background: #5a5f7d;
}
.pon-port__description {
  font-size: 12px;
  color: #8c90a4;
}
.onu-table-wrap {
  overflow-x: auto;
  border-radius: 8px;
  border: 1px solid #f0f1f5;
}
.onu-table {
  width: 100%;
  min-width: 760px;
  border-collapse: collapse;
  background: #fff;
}
.onu-table thead th {
  text-align: left;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.3px;
  color: #8c90a4;
  background: #f6f7fb;
  padding: 7px 6px;
  border-bottom: 1px solid #eef0f6;
}
.onu-table td {
  padding: 6px;
  font-size: 12.5px;
  vertical-align: middle;
  border-bottom: 1px solid #f5f6fa;
}
.onu-row:hover {
  background: #f6f9ff;
}
.onu-row:last-child td {
  border-bottom: none;
}
.onu-dot {
  display: inline-block;
  width: 6px;
  height: 6px;
  border-radius: 50%;
  margin-right: 6px;
}
.onu-table__fav {
  width: 17px;
  padding: 0 !important;
}
/* The app's global `.unicon svg { fill: <theme gray> }` rule otherwise
   wins over the `fill` prop on this icon, leaving it grey — override it. */
.onu-fav-star :deep(svg) {
  fill: darkgoldenrod !important;
}
.onu-table__name {
  min-width: 150px;
  white-space: nowrap;
}
.onu-table__name a {
  font-weight: 600;
}
.onu-table__descr {
  color: #5a5f7d;
}
.onu-table__ident {
  color: #8c90a4;
  font-size: 11px;
}
.onu-table__sn {
  color: #272b41;
  font-size: 12px;
  font-weight: 600;
  white-space: nowrap;
}
.onu-table__sn small {
  color: #8c90a4;
  font-weight: 700;
  margin-right: 3px;
}
.onu-delete {
  color: #8c90a4;
}
.onu-delete:hover {
  color: #e5484d;
}
.onu-delete.is-busy {
  opacity: 0.5;
  pointer-events: none;
}
.onu-status {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 999px;
  color: #fff;
  font-size: 10.5px;
  font-weight: 700;
  text-transform: uppercase;
}
.onu-signal {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 6px;
  color: #fff;
  font-size: 11px;
  font-weight: 700;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.12);
}
</style>
