<script setup lang="ts">
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue';
import { useRouter } from 'vue-router';
import { DataService } from '@/config/dataService/dataService';
import { Modal, notification } from 'ant-design-vue';
import { Network } from 'vis-network';
// The library's own stylesheet was never imported anywhere in this project
// — without it, `.vis-tooltip` has no `position: absolute` (it comes ONLY
// from this CSS; vis-network sets left/top/visibility as inline styles in
// JS regardless, but those do nothing on a `position: static` element).
// Root cause of hover tooltips never actually appearing at all, confirmed
// live: not a bug in any of this page's own tooltip content/logic, the
// popup was never positioned or shown in the first place.
import 'vis-network/styles/vis-network.css';
import { DataSet } from 'vis-data';
import { Main } from '../styled';
import { deviceStatusColor, ifaceStatusColor, statusIconUri } from '@/utility/deviceTypeIcons';
import { signalColor, loadColor, tempColor } from '@/utility/opticalColors';

const router = useRouter();
const loading = ref(true);
const containerEl = ref<HTMLDivElement | null>(null);
const settingsOpen = ref(false);
const legendOpen = ref(true);
let network: Network | null = null;
let refreshTimer: ReturnType<typeof setInterval> | null = null;

const settings = reactive({
  utilization: 0,
  hideLevelsAbove: 50,
  hideWithoutLinks: true,
  refreshInterval: 'off',
});

const stats = reactive({ devices: 0, up: 0, down: 0, unknown: 0, links: 0, linksDown: 0 });
const hasData = computed(() => stats.devices > 0);

const refreshIntervals: Record<string, number> = { off: 0, '10s': 10000, '30s': 30000, '1m': 60000, '5m': 300000 };

// Full link objects (already carrying both ends' iface + utilization data,
// straight from the same topology-tree response the graph is drawn from)
// keyed by link id, so a click on an edge can open the detail drawer
// without a second round trip just to re-fetch what's already in hand.
// Ids are usually numeric (real device/link rows) but "external LLDP
// neighbor" pseudo-devices/pseudo-links (see Controller::getExternalNeighbors
// on the backend) use string ids like "ext:25:AABBCC..." — vis-network
// preserves whatever id type a node/edge was given, so these maps and every
// lookup into them must NOT coerce ids to Number().
const linksById = new Map<number | string, any>();
const devicesById = new Map<number | string, any>();
const linkDrawerOpen = ref(false);
const selectedLink = ref<any>(null);
const opticalLoading = ref(false);
const opticalSrc = ref<{ rx_power?: number | null; tx_power?: number | null; present?: boolean | null } | null>(null);
const opticalDest = ref<{ rx_power?: number | null; tx_power?: number | null; present?: boolean | null } | null>(null);

// One "wire optical power" instead of the two separate per-side readings:
// a fiber link only has one real optical measurement worth leading with,
// and plenty of the devices on this network don't expose SFP diagnostics
// on both ends (an OLT's own uplink port, or a model whose module doesn't
// support sfp_optical at all) — so if the source side has nothing, fall
// back to whatever the destination side reports for the same link rather
// than just showing "no data" when the other end actually knows.
function hasOptical(o: any): boolean {
  return !!o && o.present !== false && (o.rx_power != null || o.tx_power != null);
}
function pickOptical(srcOpt: any, destOpt: any): { data: any; side: 'src' | 'dest' } | null {
  if (hasOptical(srcOpt)) return { data: srcOpt, side: 'src' };
  if (hasOptical(destOpt)) return { data: destOpt, side: 'dest' };
  return null;
}
const wireOptical = computed(() => pickOptical(opticalSrc.value, opticalDest.value));

// One fetch, one cache, several troubleshooting signals read off of it:
// optical power, error counters, and the raw port description all live on
// the SAME `/component/switches/interfaces/{device}` row — pulling the
// whole row once (from=store, never live) means the wire hover/click and
// the device hover/click can each pull out whatever piece they need
// without re-requesting it.
const ifaceRowCache = new Map<string, any>();
async function fetchIfaceRow(deviceId: number | string | undefined, ifaceName: string | undefined) {
  // External-neighbor pseudo-devices (string ids) have no real interfaces
  // endpoint behind them — skip rather than firing a request that can only 404.
  if (!deviceId || typeof deviceId !== 'number' || !ifaceName) return null;
  const key = `${deviceId}:${ifaceName}`;
  if (ifaceRowCache.has(key)) return ifaceRowCache.get(key);
  try {
    const { data } = await DataService.get(`/component/switches/interfaces/${deviceId}`, { from: 'store' });
    const rows = data.data || [];
    const row = rows.find((r: any) => r.interface?.name === ifaceName) || null;
    ifaceRowCache.set(key, row);
    return row;
  } catch {
    return null;
  }
}
function hasErrors(row: any): boolean {
  const c = row?.counters;
  return !!c && ((Number(c.in_errors) || 0) > 0 || (Number(c.out_errors) || 0) > 0);
}

// Device hover: CPU/memory/temperature (from the same `/component/switches/
// resources` call the device's own dashboard tab uses) plus interface up/
// down counts (from `/device/{id}`, same call the click-drawer already
// makes) — both `from=store`/cached, only fetched the first time a given
// node is actually hovered or clicked.
const resourcesCache = new Map<number | string, any>();
async function fetchResources(deviceId: number | string) {
  if (isExternalId(deviceId)) return null;
  if (resourcesCache.has(deviceId)) return resourcesCache.get(deviceId);
  try {
    const { data } = await DataService.get(`/component/switches/resources/${deviceId}`, { from: 'store' });
    resourcesCache.set(deviceId, data.data);
    return data.data;
  } catch {
    resourcesCache.set(deviceId, null);
    return null;
  }
}

function confirmDeleteLink(lnk: any) {
  Modal.confirm({
    title: 'Delete this link?',
    okText: 'Delete',
    okType: 'danger',
    onOk: async () => {
      try {
        await DataService.delete(`/component/links/${lnk.id}`);
        notification.success({ message: 'Link deleted' });
        loadGraph();
      } catch (err: any) {
        notification.error({ message: 'Could not delete link', description: err?.response?.data?.error?.description || 'Please try again.' });
      }
    },
  });
}

const srcRow = ref<any>(null);
const destRow = ref<any>(null);
async function openLinkDetail(edgeId: string | number) {
  const lnk = linksById.get(edgeId);
  if (!lnk) return;
  selectedLink.value = lnk;
  linkDrawerOpen.value = true;
  opticalSrc.value = null;
  opticalDest.value = null;
  srcRow.value = null;
  destRow.value = null;
  opticalLoading.value = true;
  const [rs, rd] = await Promise.all([
    fetchIfaceRow(lnk.src_device?.id, lnk.src_iface?.name),
    fetchIfaceRow(lnk.dest_device?.id, lnk.dest_iface?.name),
  ]);
  srcRow.value = rs;
  destRow.value = rd;
  opticalSrc.value = rs?.optical || null;
  opticalDest.value = rd?.optical || null;
  opticalLoading.value = false;
}

// Device click drawer: the bulk topology-tree payload already gives name/
// IP/model/group/status for the quick header, but description/MAC/serial/
// interface up-down counts aren't part of that bulk response (same reason
// optical isn't) — fetched via the same `/device/{id}` call the full
// device page itself uses, once per device and cached, only when a node is
// actually clicked.
const deviceDrawerOpen = ref(false);
const selectedDevice = ref<any>(null);
const deviceExtra = ref<any>(null);
const deviceExtraLoading = ref(false);
const deviceCache = new Map<number | string, any>();

async function fetchDeviceExtra(id: number | string) {
  if (isExternalId(id)) return null;
  if (deviceCache.has(id)) return deviceCache.get(id);
  try {
    const { data } = await DataService.get(`/device/${id}`);
    deviceCache.set(id, data.data);
    return data.data;
  } catch {
    return null;
  }
}

const deviceNeighborLinks = computed(() => {
  if (!selectedDevice.value) return [];
  const id = selectedDevice.value.id;
  return Array.from(linksById.values()).filter((l: any) => l.src_device?.id === id || l.dest_device?.id === id);
});

const deviceResources = ref<any>(null);
async function openDeviceDetail(nodeId: string | number) {
  const dev = devicesById.get(nodeId);
  if (!dev) return;
  selectedDevice.value = dev;
  externalNameDraft.value = dev.named ? dev.name : '';
  deviceDrawerOpen.value = true;
  deviceExtraLoading.value = true;
  deviceResources.value = null;
  const [extra, resources] = await Promise.all([fetchDeviceExtra(dev.id), fetchResources(dev.id)]);
  deviceExtra.value = extra;
  deviceResources.value = resources;
  deviceExtraLoading.value = false;
}
function goToSelectedDevice() {
  if (!selectedDevice.value) return;
  deviceDrawerOpen.value = false;
  router.push({ name: 'device-detail', params: { id: selectedDevice.value.id } });
}

// External-neighbor pseudo-devices have no Device row to hold a name, so
// there's a small dedicated table for it (see Controller::setExternalNeighborName)
// keyed by the same "ext:<device_id>:<mac>" id — an empty name clears the
// custom name back to the auto-generated "External device (<mac>)" one.
const externalNameDraft = ref('');
const externalNameSaving = ref(false);
async function saveExternalName() {
  if (!selectedDevice.value || !isExternalId(selectedDevice.value.id)) return;
  externalNameSaving.value = true;
  try {
    const name = externalNameDraft.value.trim();
    await DataService.put('/component/links/external-neighbor-name', { id: selectedDevice.value.id, name });
    notification.success({ message: name ? 'Name saved' : 'Name cleared' });
    await loadGraph();
    const refreshed = devicesById.get(selectedDevice.value.id);
    if (refreshed) selectedDevice.value = refreshed;
  } catch (err: any) {
    notification.error({ message: 'Could not save name', description: err?.response?.data?.error?.description || 'Please try again.' });
  } finally {
    externalNameSaving.value = false;
  }
}
function openNeighborLink(link: any) {
  deviceDrawerOpen.value = false;
  openLinkDetail(link.id);
}
// The link objects' own embedded src_device/dest_device (from the Link
// model's lite serialization) don't carry a live pinger status or a real
// model.type the way the top-level devices list does — both resolved from
// devicesById (the same bulk payload's device list) instead.
function deviceStatus(deviceId: number | string | undefined): string | undefined {
  return deviceId != null ? devicesById.get(deviceId)?.pinger?.status : undefined;
}
function deviceType(deviceId: number | string | undefined): string | undefined {
  return deviceId != null ? devicesById.get(deviceId)?.model?.type : undefined;
}
// External-neighbor pseudo-devices/pseudo-links use string ids like
// "ext:25:AABBCC..." (see Controller::getExternalNeighbors) — there's no
// real Device/interfaces-info/resources endpoint behind one of these, so
// every fetch that takes a device id needs to skip them rather than firing
// a request that can only ever 404.
function isExternalId(id: number | string | undefined | null): boolean {
  return typeof id === 'string' && id.startsWith('ext:');
}
function otherSideOf(link: any, deviceId: number) {
  return link.src_device?.id === deviceId
    ? { device: link.dest_device, iface: link.src_iface, otherIface: link.dest_iface }
    : { device: link.src_device, iface: link.dest_iface, otherIface: link.src_iface };
}

// Device type → icon/colour and status → colour now live in
// `@/utility/deviceTypeIcons` so the graph, the device-detail Topology tab
// and the topology tree all render the exact same icon for the exact same
// device type instead of three slightly-different reimplementations.
const statusColor = deviceStatusColor;

// Link colour reflects whether the link is actually passing traffic right
// now — "Down" on either end (real ifOperStatus, not a guess) always wins
// as red, since a link with a dead interface is down regardless of what
// utilization it logged before it went down. Only a link with both ends
// confirmed "Up" (or an end we have no interface data for at all, e.g. an
// LLDP neighbor whose own interface didn't resolve) is coloured green.
// External-neighbor links (someone else's switch, visible only via LLDP —
// see Controller::getExternalNeighbors) get their own colour family
// entirely, not just a status tweak: teal for up, orange for down — so a
// wire to something you don't manage reads as "different kind of thing"
// at a glance, not just "another green/red link", while still keeping the
// up/down distinction (from the one side we DO have real status for).
function linkStatusColor(l: any) {
  const statuses = [l.src_iface?.status, l.dest_iface?.status].filter(Boolean);
  const down = statuses.some((s) => s === 'Down');
  const up = statuses.length > 0 && statuses.every((s) => s === 'Up');
  if (l.external) {
    if (down) return '#ea580c';
    if (up) return '#0891b2';
    return '#c1c4d6';
  }
  if (down) return '#ef4444';
  if (up) return '#16a34a';
  return '#c1c4d6';
}
function linkIsDown(l: any) {
  return [l.src_iface?.status, l.dest_iface?.status].some((s) => s === 'Down');
}

function el(tag: string, className: string, text?: string) {
  const e = document.createElement(tag);
  e.className = className;
  if (text != null) e.textContent = text;
  return e;
}

function pct(v: number | null | undefined): string | null {
  return v == null ? null : `${Math.round(v)}%`;
}

// `resources`/`extra` are resolved lazily (first hover of this node
// triggers the fetch, see the `hoverNode` handler below) — absent on the
// very first render, filled in once available, same lazy pattern as the
// wire tooltip's optical reading below.
function deviceTooltip(d: any, resources?: any, extra?: any) {
  const wrap = el('div', 'topo-tip');
  const head = el('div', 'topo-tip__head');
  const dot = el('span', 'topo-tip__dot');
  dot.style.background = statusColor(d.pinger?.status);
  head.appendChild(dot);
  head.appendChild(el('strong', '', d.name || d.ip));
  wrap.appendChild(head);
  wrap.appendChild(el('div', 'topo-tip__row', d.ip));
  wrap.appendChild(el('div', 'topo-tip__row', d.model?.name || 'unknown model'));
  const status = el('div', 'topo-tip__row');
  status.appendChild(document.createTextNode(d.pinger?.status || 'unknown'));
  if (d.pinger?.latency) status.appendChild(document.createTextNode(` · ${d.pinger.latency}ms`));
  if (d.pinger?.last_change) status.appendChild(document.createTextNode(` · since ${d.pinger.last_change}`));
  wrap.appendChild(status);

  const cpu = pct(resources?.cpu?.util);
  const mem = pct(resources?.memory?.util);
  const temp = resources?.temperatures?.main;
  if (cpu != null || mem != null || temp != null) {
    const parts = [cpu != null ? `CPU ${cpu}` : null, mem != null ? `Mem ${mem}` : null, temp != null ? `${temp}°C` : null].filter(Boolean);
    wrap.appendChild(el('div', 'topo-tip__row', parts.join(' · ')));
  }
  if (extra?.ifaces_stat) {
    wrap.appendChild(el('div', 'topo-tip__row', `Interfaces: ${extra.ifaces_stat.up} up${extra.ifaces_stat.down ? ` / ${extra.ifaces_stat.down} down` : ''}`));
  }
  wrap.appendChild(el('div', 'topo-tip__hint', 'Click the device for full details'));
  return wrap;
}

function fmtMbps(v: number | null | undefined) {
  if (v == null) return null;
  return v >= 1000 ? `${(v / 1000).toFixed(2)} Gbps` : `${v.toFixed(2)} Mbps`;
}

// Hover tooltip: everything here already comes bundled with the same
// topology-tree response the graph itself is built from (src_iface /
// dest_iface each carry their own in/out utilization) — no extra request
// needed just to hover. Optical power isn't in that bulk payload (it's
// per-interface, fetched live-ish per device), so that's reserved for the
// click-through detail panel below instead of the hover, which has to stay
// cheap since it can fire for dozens of edges a second while scanning the graph.
// Both ends shown independently — not just whichever one the fallback
// logic would pick — so a hover actually shows the destination device's
// own optical reading too, not only the source's.
function opticalLine(ifaceName: string | undefined, opt: any): string | null {
  if (!hasOptical(opt)) return null;
  const parts: string[] = [];
  if (opt.rx_power != null) parts.push(`RX ${opt.rx_power}dBm`);
  if (opt.tx_power != null) parts.push(`TX ${opt.tx_power}dBm`);
  if (!parts.length) return null;
  return `Optical (${ifaceName || '?'}): ${parts.join(' / ')}`;
}

function errorsLine(name: string | undefined, row: any): string | null {
  if (!hasErrors(row)) return null;
  const inE = row.counters.in_errors,
    outE = row.counters.out_errors;
  return `⚠ Errors on ${name || '?'}: in ${inE} / out ${outE}`;
}

// `srcRow`/`destRow` are resolved lazily (first hover of this edge
// triggers the fetch, see the `hoverEdge` handler below) so they're absent
// on the very first render of a tooltip and filled in once available.
function linkTooltip(l: any, srcRow?: any, destRow?: any) {
  const wrap = el('div', 'topo-tip');
  wrap.appendChild(el('div', 'topo-tip__head', `${l.src_iface?.name || '?'} → ${l.dest_iface?.name || '?'}`));
  const status = el('div', 'topo-tip__row');
  const sdot = el('span', 'topo-tip__dot');
  sdot.style.background = linkStatusColor(l);
  status.appendChild(sdot);
  status.appendChild(document.createTextNode(linkIsDown(l) ? 'Down' : 'Up'));
  if (l.speed_humanize) status.appendChild(document.createTextNode(` · ${l.speed_humanize}`));
  wrap.appendChild(status);
  wrap.appendChild(el('div', 'topo-tip__row', l.utilization != null ? `Utilization: ${l.utilization}%` : 'No traffic data'));

  const srcIn = fmtMbps(l.src_iface?.utilization?.in_mbps);
  const srcOut = fmtMbps(l.src_iface?.utilization?.out_mbps);
  if (srcIn != null || srcOut != null) {
    wrap.appendChild(el('div', 'topo-tip__row topo-tip__row--sub', `${l.src_iface?.name || 'src'}: ↓${srcIn ?? '—'}  ↑${srcOut ?? '—'}`));
  }
  const destIn = fmtMbps(l.dest_iface?.utilization?.in_mbps);
  const destOut = fmtMbps(l.dest_iface?.utilization?.out_mbps);
  if (destIn != null || destOut != null) {
    wrap.appendChild(el('div', 'topo-tip__row topo-tip__row--sub', `${l.dest_iface?.name || 'dest'}: ↓${destIn ?? '—'}  ↑${destOut ?? '—'}`));
  }
  const srcOptLine = opticalLine(l.src_iface?.name, srcRow?.optical);
  if (srcOptLine) wrap.appendChild(el('div', 'topo-tip__row', srcOptLine));
  const destOptLine = opticalLine(l.dest_iface?.name, destRow?.optical);
  if (destOptLine) wrap.appendChild(el('div', 'topo-tip__row', destOptLine));
  const srcErr = errorsLine(l.src_iface?.name, srcRow);
  if (srcErr) wrap.appendChild(el('div', 'topo-tip__row topo-tip__row--warn', srcErr));
  const destErr = errorsLine(l.dest_iface?.name, destRow);
  if (destErr) wrap.appendChild(el('div', 'topo-tip__row topo-tip__row--warn', destErr));
  wrap.appendChild(el('div', 'topo-tip__hint', 'Click the link for full details'));
  return wrap;
}

async function loadGraph() {
  loading.value = true;
  try {
    const { data } = await DataService.get('/component/links/topology-tree', {
      period: '10m',
      utilization: settings.utilization,
      hide_levels_above: settings.hideLevelsAbove,
      hide_without_links: settings.hideWithoutLinks ? 'true' : 'false',
    });
    const topo = data.data || { devices: [], links: [] };

    stats.devices = (topo.devices || []).length;
    stats.up = (topo.devices || []).filter((d: any) => d.pinger?.status === 'Up').length;
    stats.down = (topo.devices || []).filter((d: any) => d.pinger?.status === 'Down').length;
    stats.unknown = stats.devices - stats.up - stats.down;
    stats.links = (topo.links || []).length;
    stats.linksDown = (topo.links || []).filter(linkIsDown).length;
    linksById.clear();
    for (const l of topo.links || []) linksById.set(l.id, l);
    devicesById.clear();
    for (const d of topo.devices || []) devicesById.set(d.id, d);

    const nodes = new DataSet(
      (topo.devices || []).map((d: any) => {
        // Solid status colour disc (no per-type accent colour) with a
        // small white device-type glyph on top — "only red/green based on
        // status", with just enough of an icon to tell a switch from an
        // OLT from an ONU at a glance.
        const fill = statusColor(d.pinger?.status);
        const down = d.pinger?.status === 'Down';
        return {
          id: d.id,
          label: d.ip ? `${d.name || d.ip}\n${d.ip}` : `${d.name}`,
          shape: 'circularImage',
          image: statusIconUri(d.model?.type, fill),
          size: Math.max(d.design?.size || 12, 14),
          color: { border: fill, background: fill },
          borderWidth: 2,
          borderWidthSelected: 3,
          shadow: { enabled: true, size: down ? 16 : 10, x: 0, y: 0, color: down ? 'rgba(239,68,68,0.65)' : 'rgba(22,163,74,0.45)' },
          font: { size: 12, color: '#272b41', vadjust: 4 },
          title: deviceTooltip(d),
        };
      }),
    );
    const edges = new DataSet(
      (topo.links || []).map((l: any) => {
        const color = linkStatusColor(l);
        const down = linkIsDown(l);
        return {
          id: l.id,
          from: l.src_device.id,
          to: l.dest_device.id,
          label: down ? 'DOWN' : l.utilization != null ? `${l.utilization}%` : '',
          color: { color, highlight: '#1868db' },
          width: down ? 3 : 2.5,
          dashes: down ? [4, 3] : false,
          smooth: { enabled: true, type: 'continuous', roundness: 0.3 },
          arrows: { to: { enabled: true, scaleFactor: 0.55 } },
          // font.background turns the label into a small solid pill in the
          // same green/red as the link itself, instead of plain text
          // floating over the canvas — readable at a glance from a distance.
          font: { size: 10, align: 'middle', color: '#fff', background: color, strokeWidth: 0 },
          title: linkTooltip(l),
        };
      }),
    );

    if (!containerEl.value) return;
    if (network) network.destroy();
    network = new Network(
      containerEl.value,
      { nodes, edges },
      {
        physics: { stabilization: true, barnesHut: { gravitationalConstant: -8000, springLength: 140 } },
        interaction: { hover: true, tooltipDelay: 150 },
        nodes: { borderWidth: 3 },
        edges: { smooth: { enabled: true, type: 'continuous', roundness: 0.3 } as any },
      },
    );
    network.on('doubleClick', (params) => {
      // External-neighbor pseudo-nodes (string ids like "ext:25:AABBCC...")
      // have no real device page to jump to.
      if (params.nodes?.length && !isExternalId(params.nodes[0])) {
        router.push({ name: 'device-detail', params: { id: params.nodes[0] } });
      }
    });
    // A plain click lands on an edge only when it's not also on a node
    // (vis reports both arrays; a node click leaves `edges` empty) — that's
    // how a "click the wire" vs "click the device" gesture is told apart.
    // Clicking a node opens its quick-detail drawer; double-click still
    // jumps straight to the full device page like before.
    network.on('click', (params) => {
      if (params.nodes?.length) {
        openDeviceDetail(params.nodes[0]);
      } else if (params.edges?.length) {
        openLinkDetail(params.edges[0]);
      }
    });
    // Optical power/error counters aren't in the bulk payload, so a tooltip
    // is first shown without them — hovering triggers the (cached) fetch
    // in the background. vis-network's popup is one persistent DOM element
    // it reuses for every hover (only its content/visibility change), so
    // just updating the dataset isn't enough to refresh a tooltip that's
    // ALREADY on screen for THIS hover — it would only pick up the new
    // content on the next hover. refreshLiveTooltip patches that same
    // element's content directly, in place, the moment the fetch resolves,
    // so the cursor doesn't have to leave and come back to see it.
    const refreshLiveTooltip = (newContentEl: HTMLElement) => {
      const frame = containerEl.value?.querySelector('.vis-tooltip') as HTMLElement | null;
      if (!frame) return;
      frame.innerHTML = '';
      frame.appendChild(newContentEl);
    };
    network.on('hoverEdge', async (params: any) => {
      const lnk = linksById.get(params.edge);
      if (!lnk) return;
      const [rs, rd] = await Promise.all([
        fetchIfaceRow(lnk.src_device?.id, lnk.src_iface?.name),
        fetchIfaceRow(lnk.dest_device?.id, lnk.dest_iface?.name),
      ]);
      if (hasOptical(rs?.optical) || hasOptical(rd?.optical) || hasErrors(rs) || hasErrors(rd)) {
        const newTitle = linkTooltip(lnk, rs, rd);
        edges.update({ id: lnk.id, title: newTitle } as any);
        refreshLiveTooltip(newTitle);
      }
    });
    // Resources/interface counts aren't in the bulk payload either — same
    // lazy-fetch-then-refresh-the-live-tooltip pattern as edges above.
    network.on('hoverNode', async (params: any) => {
      const dev = devicesById.get(params.node);
      if (!dev) return;
      const [resources, extra] = await Promise.all([fetchResources(dev.id), fetchDeviceExtra(dev.id)]);
      if (resources || extra) {
        const newTitle = deviceTooltip(dev, resources, extra);
        nodes.update({ id: dev.id, title: newTitle } as any);
        refreshLiveTooltip(newTitle);
      }
    });
  } catch (err: any) {
    notification.error({ message: 'Could not load topology', description: err?.response?.data?.error?.description || 'Please try again.' });
  } finally {
    loading.value = false;
  }
}

function applySettings() {
  settingsOpen.value = false;
  loadGraph();
}

function setAutoRefresh() {
  if (refreshTimer) clearInterval(refreshTimer);
  const ms = refreshIntervals[settings.refreshInterval];
  if (ms) refreshTimer = setInterval(loadGraph, ms);
}

function toggleFullscreen() {
  const el = containerEl.value?.parentElement;
  if (!el) return;
  if (document.fullscreenElement) document.exitFullscreen();
  else el.requestFullscreen();
}

function zoomBy(factor: number) {
  if (!network) return;
  const scale = network.getScale() * factor;
  network.moveTo({ scale });
}

function fitView() {
  network?.fit({ animation: { duration: 400, easingFunction: 'easeInOutQuad' } });
}

onMounted(() => {
  loadGraph();
});
onBeforeUnmount(() => {
  network?.destroy();
  if (refreshTimer) clearInterval(refreshTimer);
});
</script>

<template>
  <sdPageHeader :routes="[{ path: '/', breadcrumbName: 'Dashboard' }, { path: '', breadcrumbName: 'Topology (graph view)' }]" class="ninjadash-page-header-main">
    <template #buttons>
      <div class="topo-stats">
        <span class="topo-stat topo-stat--total"><unicon name="sitemap" width="14" height="14"></unicon>{{ stats.devices }} devices</span>
        <span class="topo-stat topo-stat--up"><span class="topo-stat__dot"></span>{{ stats.up }} up</span>
        <span class="topo-stat topo-stat--down"><span class="topo-stat__dot"></span>{{ stats.down }} down</span>
        <span class="topo-stat topo-stat--links"><unicon name="share-alt" width="14" height="14"></unicon>{{ stats.links }} links</span>
        <span v-if="stats.linksDown" class="topo-stat topo-stat--down"><span class="topo-stat__dot"></span>{{ stats.linksDown }} link{{ stats.linksDown > 1 ? 's' : '' }} down</span>
      </div>
    </template>
  </sdPageHeader>
  <Main>
    <div class="topo-wrap">
      <div class="topo-toolbar">
        <a-select v-model:value="settings.refreshInterval" size="small" style="width: 90px" @change="setAutoRefresh">
          <a-select-option value="off">off</a-select-option>
          <a-select-option value="10s">10s</a-select-option>
          <a-select-option value="30s">30s</a-select-option>
          <a-select-option value="1m">1m</a-select-option>
          <a-select-option value="5m">5m</a-select-option>
        </a-select>
        <button type="button" class="topo-icon-btn" title="Zoom in" @click="zoomBy(1.3)"><unicon name="search-plus"></unicon></button>
        <button type="button" class="topo-icon-btn" title="Zoom out" @click="zoomBy(0.77)"><unicon name="search-minus"></unicon></button>
        <button type="button" class="topo-icon-btn" title="Fit to screen" @click="fitView"><unicon name="crosshairs"></unicon></button>
        <button type="button" class="topo-icon-btn" title="Refresh" @click="loadGraph"><unicon name="redo"></unicon></button>
        <button type="button" class="topo-icon-btn" title="Fullscreen" @click="toggleFullscreen"><unicon name="expand-arrows-alt"></unicon></button>
        <button type="button" class="topo-icon-btn" :class="{ 'topo-icon-btn--active': settingsOpen }" title="Settings" @click="settingsOpen = !settingsOpen"><unicon name="bars"></unicon></button>
      </div>

      <div v-if="settingsOpen" class="topo-settings">
        <div class="topo-settings__row">
          <label>Min utilization to show</label>
          <a-input-number v-model:value="settings.utilization" :min="0" :max="100" size="small" />
        </div>
        <div class="topo-settings__row">
          <label>Hide levels above</label>
          <a-input-number v-model:value="settings.hideLevelsAbove" :min="0" :max="50" size="small" />
        </div>
        <div class="topo-settings__row">
          <label>Hide devices without links</label>
          <a-switch v-model:checked="settings.hideWithoutLinks" />
        </div>
        <sdButton type="primary" size="small" block @click="applySettings">Apply</sdButton>
      </div>

      <div v-if="legendOpen" class="topo-legend">
        <div class="topo-legend__title">
          Legend
          <a class="topo-legend__close" @click="legendOpen = false"><unicon name="multiply" width="12" height="12"></unicon></a>
        </div>
        <div class="topo-legend__row"><span class="topo-legend__swatch" style="background: #16a34a"></span>Device up</div>
        <div class="topo-legend__row"><span class="topo-legend__swatch" style="background: #ef4444"></span>Device down</div>
        <div class="topo-legend__row"><span class="topo-legend__swatch" style="background: #c1c4d6"></span>Unknown</div>
        <div class="topo-legend__divider"></div>
        <div class="topo-legend__row"><span class="topo-legend__line" style="background: #16a34a"></span>Link up</div>
        <div class="topo-legend__row"><span class="topo-legend__line topo-legend__line--dashed" style="background: #ef4444"></span>Link down</div>
        <div class="topo-legend__divider"></div>
        <div class="topo-legend__row"><span class="topo-legend__line" style="background: #0891b2"></span>External neighbor — up</div>
        <div class="topo-legend__row"><span class="topo-legend__line topo-legend__line--dashed" style="background: #ea580c"></span>External neighbor — down</div>
      </div>
      <button v-else type="button" class="topo-icon-btn topo-legend-toggle" title="Show legend" @click="legendOpen = true"><unicon name="info-circle"></unicon></button>

      <a-spin v-if="loading" class="topo-spin" size="large" />
      <div v-else-if="!hasData" class="topo-empty">
        <unicon name="sitemap" width="42" height="42"></unicon>
        <p>No devices to show with the current filters.</p>
      </div>
      <div ref="containerEl" class="topo-canvas"></div>
    </div>

    <!-- Link detail drawer — opened by clicking a wire on the graph. Traffic
         in/out and status come straight from the same bulk topology-tree
         payload the graph itself uses; optical power is fetched on open
         only (from=store, never a live device query) since it isn't part
         of that bulk response. -->
    <a-drawer :visible="linkDrawerOpen" title="Link details" placement="right" width="440" @close="linkDrawerOpen = false">
      <template v-if="selectedLink">
        <div class="link-detail__header">
          <router-link :to="{ name: 'device-detail', params: { id: selectedLink.src_device?.id } }" class="link-detail__device">
            <img class="link-detail__icon" :src="statusIconUri(deviceType(selectedLink.src_device?.id), statusColor(deviceStatus(selectedLink.src_device?.id)))" />
            {{ selectedLink.src_device?.name || selectedLink.src_device?.ip }}
          </router-link>
          <unicon name="arrow-right" width="16" height="16"></unicon>
          <!-- External-neighbor pseudo-devices have no real device page to link to. -->
          <span v-if="isExternalId(selectedLink.dest_device?.id)" class="link-detail__device link-detail__device--external">
            <img class="link-detail__icon" :src="statusIconUri('EXTERNAL', '#c1c4d6')" />
            {{ selectedLink.dest_device?.name }}
          </span>
          <router-link v-else :to="{ name: 'device-detail', params: { id: selectedLink.dest_device?.id } }" class="link-detail__device">
            <img class="link-detail__icon" :src="statusIconUri(deviceType(selectedLink.dest_device?.id), statusColor(deviceStatus(selectedLink.dest_device?.id)))" />
            {{ selectedLink.dest_device?.name || selectedLink.dest_device?.ip }}
          </router-link>
        </div>
        <a-tag v-if="isExternalId(selectedLink.dest_device?.id)" color="orange" style="margin-bottom: 12px">Unmanaged / external neighbor</a-tag>

        <div class="link-detail__meta">
          <a-tag v-if="selectedLink.source === 'lldp'" color="purple">LLDP</a-tag>
          <a-tag v-else-if="selectedLink.source === 'fdb'" color="blue">FDB</a-tag>
          <a-tag v-else color="default">Manual</a-tag>
          <span v-if="selectedLink.speed_humanize" class="link-detail__meta-item">Speed: {{ selectedLink.speed_humanize }}</span>
          <span v-if="selectedLink.utilization != null" class="link-detail__meta-item">Peak utilization: {{ selectedLink.utilization }}%</span>
        </div>

        <div v-if="opticalLoading" class="link-detail__optical-summary">
          <a-skeleton active :paragraph="{ rows: 1 }" />
        </div>
        <div v-else-if="wireOptical" class="link-detail__optical-summary">
          <span class="link-detail__optical-label">
            Wire optical power ({{ wireOptical.side === 'src' ? selectedLink.src_iface?.name : selectedLink.dest_iface?.name }}<template v-if="wireOptical.side === 'dest'"> — far end</template>):
          </span>
          <span v-if="wireOptical.data.rx_power != null" class="link-detail__optical-pill" :style="{ background: signalColor(wireOptical.data.rx_power) }">RX {{ wireOptical.data.rx_power }}dBm</span>
          <span v-if="wireOptical.data.tx_power != null" class="link-detail__optical-pill" :style="{ background: signalColor(wireOptical.data.tx_power) }">TX {{ wireOptical.data.tx_power }}dBm</span>
        </div>
        <p v-else class="link-detail__hint" style="margin-bottom: 16px">No optical data on either end</p>

        <div class="link-detail__ifaces">
          <div class="link-detail__iface-card">
            <div class="link-detail__iface-head">
              <span class="link-detail__iface-dot" :style="{ background: ifaceStatusColor(selectedLink.src_iface?.status) }"></span>
              <strong>{{ selectedLink.src_iface?.name || 'n/a' }}</strong>
              <span class="link-detail__iface-status">{{ selectedLink.src_iface?.status || 'unknown' }}</span>
            </div>
            <p v-if="selectedLink.src_iface?.description" class="link-detail__iface-desc">{{ selectedLink.src_iface.description }}</p>
            <div class="link-detail__row">
              <unicon name="arrow-down" width="13" height="13"></unicon> In: <strong>{{ fmtMbps(selectedLink.src_iface?.utilization?.in_mbps) ?? '—' }}</strong>
              <unicon name="arrow-up" width="13" height="13"></unicon> Out: <strong>{{ fmtMbps(selectedLink.src_iface?.utilization?.out_mbps) ?? '—' }}</strong>
            </div>
            <a-skeleton v-if="opticalLoading" active :paragraph="{ rows: 1 }" />
            <template v-else>
              <div v-if="opticalSrc" class="link-detail__optical">
                <span v-if="opticalSrc.present === false" class="link-detail__optical-pill link-detail__optical-pill--absent">No transceiver</span>
                <template v-else>
                  <span v-if="opticalSrc.rx_power != null" class="link-detail__optical-pill" :style="{ background: signalColor(opticalSrc.rx_power) }">RX {{ opticalSrc.rx_power }}dBm</span>
                  <span v-if="opticalSrc.tx_power != null" class="link-detail__optical-pill" :style="{ background: signalColor(opticalSrc.tx_power) }">TX {{ opticalSrc.tx_power }}dBm</span>
                </template>
              </div>
              <p v-else class="link-detail__hint">No optical data</p>
              <p v-if="hasErrors(srcRow)" class="link-detail__hint link-detail__hint--warn">⚠ Errors: in {{ srcRow.counters.in_errors }} / out {{ srcRow.counters.out_errors }}</p>
            </template>
          </div>

          <div class="link-detail__iface-card">
            <div class="link-detail__iface-head">
              <span class="link-detail__iface-dot" :style="{ background: ifaceStatusColor(selectedLink.dest_iface?.status) }"></span>
              <strong>{{ selectedLink.dest_iface?.name || 'n/a' }}</strong>
              <span class="link-detail__iface-status">{{ selectedLink.dest_iface?.status || 'unknown' }}</span>
            </div>
            <p v-if="selectedLink.dest_iface?.description" class="link-detail__iface-desc">{{ selectedLink.dest_iface.description }}</p>
            <div class="link-detail__row">
              <unicon name="arrow-down" width="13" height="13"></unicon> In: <strong>{{ fmtMbps(selectedLink.dest_iface?.utilization?.in_mbps) ?? '—' }}</strong>
              <unicon name="arrow-up" width="13" height="13"></unicon> Out: <strong>{{ fmtMbps(selectedLink.dest_iface?.utilization?.out_mbps) ?? '—' }}</strong>
            </div>
            <a-skeleton v-if="opticalLoading" active :paragraph="{ rows: 1 }" />
            <template v-else>
              <div v-if="opticalDest" class="link-detail__optical">
                <span v-if="opticalDest.present === false" class="link-detail__optical-pill link-detail__optical-pill--absent">No transceiver</span>
                <template v-else>
                  <span v-if="opticalDest.rx_power != null" class="link-detail__optical-pill" :style="{ background: signalColor(opticalDest.rx_power) }">RX {{ opticalDest.rx_power }}dBm</span>
                  <span v-if="opticalDest.tx_power != null" class="link-detail__optical-pill" :style="{ background: signalColor(opticalDest.tx_power) }">TX {{ opticalDest.tx_power }}dBm</span>
                </template>
              </div>
              <p v-else class="link-detail__hint">No optical data</p>
              <p v-if="hasErrors(destRow)" class="link-detail__hint link-detail__hint--warn">⚠ Errors: in {{ destRow.counters.in_errors }} / out {{ destRow.counters.out_errors }}</p>
            </template>
          </div>
        </div>

        <a-button danger block style="margin-top: 20px" @click="linkDrawerOpen = false; confirmDeleteLink(selectedLink)">Delete this link</a-button>
      </template>
    </a-drawer>

    <!-- Device detail drawer — opened by clicking a device on the graph
         (double-click still jumps straight to the full device page). The
         header (name/IP/model/group/status) comes from the same bulk
         topology-tree payload the graph is built from; description/MAC/
         serial/interface counts aren't in that payload so they're fetched
         from the same `/device/{id}` call the full device page itself
         uses, once per device and cached. -->
    <a-drawer :visible="deviceDrawerOpen" title="Device details" placement="right" width="420" @close="deviceDrawerOpen = false">
      <template v-if="selectedDevice">
        <div class="link-detail__header">
          <img class="link-detail__icon" :src="statusIconUri(selectedDevice.model?.type, statusColor(selectedDevice.pinger?.status))" style="width: 32px; height: 32px" />
          <div>
            <div style="font-weight: 700; color: #272b41">{{ selectedDevice.name || selectedDevice.ip }}</div>
            <div style="font-size: 12px; color: #8c90a4">{{ selectedDevice.ip }}</div>
          </div>
        </div>

        <div class="link-detail__meta">
          <span class="link-detail__meta-item"><span class="link-detail__iface-dot" :style="{ background: statusColor(selectedDevice.pinger?.status) }"></span> {{ selectedDevice.pinger?.status || 'unknown' }}</span>
          <span v-if="selectedDevice.pinger?.latency" class="link-detail__meta-item">{{ selectedDevice.pinger.latency }}ms</span>
          <span class="link-detail__meta-item">{{ selectedDevice.model?.name || 'unknown model' }}</span>
          <span v-if="selectedDevice.group?.name" class="link-detail__meta-item">{{ selectedDevice.group.name }}</span>
        </div>

        <a-skeleton v-if="deviceExtraLoading" active :paragraph="{ rows: 3 }" />
        <template v-else-if="deviceExtra">
          <p v-if="deviceExtra.description" class="link-detail__iface-desc" style="margin-bottom: 14px">{{ deviceExtra.description }}</p>
          <div class="link-detail__row" style="flex-wrap: wrap; gap: 4px 14px">
            <span v-if="deviceExtra.mac">MAC: <strong>{{ deviceExtra.mac }}</strong></span>
            <span v-if="deviceExtra.serial">Serial: <strong>{{ deviceExtra.serial }}</strong></span>
          </div>
          <div v-if="deviceExtra.ifaces_stat" class="link-detail__row">
            Interfaces:
            <a-tag color="green">{{ deviceExtra.ifaces_stat.up }} up</a-tag>
            <a-tag v-if="deviceExtra.ifaces_stat.down" color="red">{{ deviceExtra.ifaces_stat.down }} down</a-tag>
          </div>
          <div v-if="deviceResources?.cpu || deviceResources?.memory || deviceResources?.temperatures" class="link-detail__optical">
            <span v-if="deviceResources.cpu?.util != null" class="link-detail__optical-pill" :style="{ background: loadColor(deviceResources.cpu.util) }">CPU {{ Math.round(deviceResources.cpu.util) }}%</span>
            <span v-if="deviceResources.memory?.util != null" class="link-detail__optical-pill" :style="{ background: loadColor(deviceResources.memory.util) }">Mem {{ Math.round(deviceResources.memory.util) }}%</span>
            <span v-if="deviceResources.temperatures?.main != null" class="link-detail__optical-pill" :style="{ background: tempColor(deviceResources.temperatures.main) }">{{ deviceResources.temperatures.main }}°C</span>
          </div>
        </template>

        <a-alert
          v-if="isExternalId(selectedDevice.id)"
          type="info"
          show-icon
          message="Unmanaged / external device"
          description="Discovered only through the local device's LLDP data — this isn't a device in your inventory, so there's no IP, credentials, or further detail to show beyond what the local side of the link reports."
          style="margin-bottom: 14px"
        />
        <div v-if="isExternalId(selectedDevice.id)" style="display: flex; gap: 8px; margin-bottom: 14px">
          <a-input v-model:value="externalNameDraft" placeholder="Custom name (e.g. ISP-X border switch)" size="small" @keyup.enter="saveExternalName" />
          <sdButton type="primary" size="small" :loading="externalNameSaving" @click="saveExternalName">Save</sdButton>
        </div>

        <div v-if="deviceNeighborLinks.length" style="margin-top: 8px">
          <div class="link-detail__meta-item" style="font-weight: 600; color: #272b41; margin-bottom: 8px">Connected links ({{ deviceNeighborLinks.length }})</div>
          <ul class="topo-links-list">
            <li v-for="lnk in deviceNeighborLinks" :key="lnk.id" class="link-detail__neighbor-row" @click="openNeighborLink(lnk)">
              <span class="link-detail__iface-dot" :style="{ background: linkStatusColor(lnk) }"></span>
              <img
                class="link-detail__icon"
                style="width: 18px; height: 18px"
                :src="statusIconUri(deviceType(otherSideOf(lnk, selectedDevice.id).device?.id), statusColor(deviceStatus(otherSideOf(lnk, selectedDevice.id).device?.id)))"
              />
              {{ otherSideOf(lnk, selectedDevice.id).device?.name || otherSideOf(lnk, selectedDevice.id).device?.ip }}
              <span class="link-detail__meta-item">via {{ otherSideOf(lnk, selectedDevice.id).iface?.name || '?' }}</span>
            </li>
          </ul>
        </div>

        <a-button v-if="!isExternalId(selectedDevice.id)" type="primary" block style="margin-top: 20px" @click="goToSelectedDevice">View full device page →</a-button>
      </template>
    </a-drawer>
  </Main>
</template>

<style scoped>
.topo-wrap {
  position: relative;
  height: calc(100vh - 160px);
  min-height: 500px;
  background:
    radial-gradient(circle, #e4e7f1 1px, transparent 1px) 0 0/22px 22px,
    linear-gradient(180deg, #fbfbfe 0%, #f5f6fa 100%);
  border: 1px solid #eef0f6;
  border-radius: 10px;
  box-shadow: 0 2px 10px rgba(39, 43, 65, 0.05);
  overflow: hidden;
}
.topo-canvas {
  width: 100%;
  height: 100%;
}
.topo-stats {
  display: flex;
  gap: 8px;
  align-items: center;
  flex-wrap: wrap;
}
.topo-stat {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  font-weight: 500;
  padding: 4px 10px;
  border-radius: 20px;
  background: #f4f5f9;
  color: #5a5f7d;
}
.topo-stat :deep(svg) {
  opacity: 0.7;
}
.topo-stat__dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  display: inline-block;
}
.topo-stat--up .topo-stat__dot {
  background: #16a34a;
}
.topo-stat--up {
  background: #eafaf0;
  color: #16a34a;
}
.topo-stat--down .topo-stat__dot {
  background: #ef4444;
}
.topo-stat--down {
  background: #fdecec;
  color: #c62a2a;
}
.topo-toolbar {
  position: absolute;
  top: 14px;
  right: 14px;
  z-index: 5;
  display: flex;
  gap: 8px;
  align-items: center;
}
.topo-icon-btn {
  width: 32px;
  height: 32px;
  border: 1px solid #e6e9f1;
  background: #fff;
  border-radius: 6px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #5a5f7d;
  cursor: pointer;
  transition: all 0.15s ease;
}
.topo-icon-btn:hover {
  color: #1868db;
  border-color: #1868db;
  box-shadow: 0 2px 6px rgba(24, 104, 219, 0.15);
}
.topo-icon-btn--active {
  color: #1868db;
  border-color: #1868db;
  background: #eef4ff;
}
.topo-icon-btn :deep(svg) {
  width: 14px;
  height: 14px;
}
.topo-settings {
  position: absolute;
  top: 56px;
  right: 14px;
  z-index: 5;
  background: #fff;
  border: 1px solid #e6e9f1;
  border-radius: 8px;
  padding: 14px;
  width: 240px;
  box-shadow: 0 8px 24px rgba(39, 43, 65, 0.12);
}
.topo-settings__row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
  gap: 10px;
}
.topo-settings__row label {
  font-size: 12px;
  color: #5a5f7d;
}
.topo-legend {
  position: absolute;
  bottom: 14px;
  left: 14px;
  z-index: 5;
  background: rgba(255, 255, 255, 0.94);
  backdrop-filter: blur(4px);
  border: 1px solid #e6e9f1;
  border-radius: 8px;
  padding: 12px 14px;
  font-size: 12px;
  color: #5a5f7d;
  box-shadow: 0 8px 24px rgba(39, 43, 65, 0.1);
  min-width: 130px;
}
.topo-legend__title {
  font-weight: 600;
  color: #272b41;
  margin-bottom: 8px;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.topo-legend__close {
  cursor: pointer;
  color: #8c90a4;
}
.topo-legend__close:hover {
  color: #ef4444;
}
.topo-legend__row {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 3px 0;
}
.topo-legend__swatch {
  width: 12px;
  height: 12px;
  border-radius: 50%;
  flex: none;
}
.topo-legend__ring {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  border: 2.5px solid;
  flex: none;
  background: #fff;
}
.topo-legend__line {
  width: 16px;
  height: 3px;
  border-radius: 2px;
  flex: none;
}
.topo-legend__line--dashed {
  background-image: repeating-linear-gradient(90deg, currentColor 0 4px, transparent 4px 7px);
  background-color: transparent !important;
  border-top: 3px dashed #ef4444;
  height: 0;
}
.topo-legend__divider {
  border-top: 1px solid #eef0f6;
  margin: 6px 0;
}
.topo-legend-toggle {
  position: absolute;
  bottom: 14px;
  left: 14px;
  z-index: 5;
}
.topo-spin {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  z-index: 3;
}
.topo-empty {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  z-index: 3;
  text-align: center;
  color: #b1b4c4;
}
.topo-empty p {
  margin-top: 10px;
  font-size: 13px;
}

/* Rich HTML tooltip built via `title: HTMLElement` on nodes/edges — vis-network
   inserts this element as-is into its tooltip popup, styled here. */
:global(.vis-tooltip) {
  padding: 0 !important;
  border: 1px solid #e6e9f1 !important;
  border-radius: 8px !important;
  box-shadow: 0 8px 20px rgba(39, 43, 65, 0.16) !important;
  background: #fff !important;
  font-family: inherit !important;
  /* vis-network's own stylesheet caps this at "nowrap", which would clip
     the two-line device/link tooltips built above at whatever the first
     line's width happens to be. */
  white-space: normal !important;
  max-width: 260px;
  /* Defensive: this page's own toolbar/legend/settings panels are also
     z-index:5 (vis-network's own default for .vis-tooltip too) — same
     value means DOM order decides who paints on top, which should already
     favour the tooltip (it's nested inside the canvas, last in the DOM),
     but there's no reason to leave that to chance. */
  z-index: 9999 !important;
}
:global(.topo-tip) {
  padding: 10px 12px;
  font-family: inherit;
  min-width: 150px;
}
:global(.topo-tip__head) {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  color: #272b41;
  margin-bottom: 4px;
}
:global(.topo-tip__row) {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  color: #5a5f7d;
  padding: 1px 0;
}
:global(.topo-tip__dot) {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  flex: none;
}
:global(.topo-tip__row--sub) {
  padding-left: 14px;
  font-size: 11px;
  color: #8c90a4;
}
:global(.topo-tip__row--warn) {
  color: #ef4444;
  font-weight: 600;
}
:global(.topo-tip__hint) {
  margin-top: 6px;
  padding-top: 6px;
  border-top: 1px dashed #eef0f6;
  font-size: 11px;
  font-style: italic;
  color: #b1b4c4;
}

.link-detail__header {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  margin-bottom: 12px;
}
.link-detail__device {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-weight: 600;
  color: #272b41;
}
.link-detail__device--external {
  color: #8c90a4;
  cursor: default;
}
.link-detail__icon {
  width: 24px;
  height: 24px;
  border-radius: 50%;
}
.link-detail__meta {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
  margin-bottom: 18px;
  font-size: 12px;
  color: #5a5f7d;
}
.link-detail__meta-item {
  color: #5a5f7d;
}
.link-detail__ifaces {
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.link-detail__iface-card {
  border: 1px solid #eef0f6;
  border-radius: 8px;
  padding: 12px 14px;
}
.link-detail__iface-head {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 6px;
}
.link-detail__iface-dot {
  width: 9px;
  height: 9px;
  border-radius: 50%;
  flex: none;
}
.link-detail__iface-status {
  margin-left: auto;
  font-size: 11px;
  color: #8c90a4;
}
.link-detail__iface-desc {
  font-size: 12px;
  color: #8c90a4;
  margin: 0 0 8px;
}
.link-detail__row {
  display: flex;
  align-items: center;
  gap: 5px;
  font-size: 13px;
  color: #272b41;
  margin-bottom: 8px;
}
.link-detail__row :deep(svg) {
  opacity: 0.6;
}
.link-detail__optical {
  display: flex;
  gap: 8px;
}
.link-detail__optical-pill {
  font-size: 11px;
  font-weight: 700;
  color: #fff;
  padding: 2px 8px;
  border-radius: 4px;
}
.link-detail__optical-pill--absent {
  background: #c1c4d6 !important;
  color: #272b41;
}
.link-detail__hint {
  font-size: 12px;
  color: #b1b4c4;
  margin: 0;
}
.link-detail__hint--warn {
  color: #ef4444;
  font-weight: 600;
  margin-top: 6px;
}
.link-detail__optical-summary {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 16px;
  padding: 8px 10px;
  background: #f8f9fc;
  border-radius: 6px;
}
.link-detail__optical-label {
  font-size: 12px;
  color: #5a5f7d;
}
.link-detail__neighbor-row {
  display: flex;
  align-items: center;
  gap: 8px;
  cursor: pointer;
  border-radius: 4px;
}
.link-detail__neighbor-row:hover {
  background: #f4f5f9;
}
</style>
