<script setup lang="ts">
import { ref, reactive, onMounted, computed } from 'vue';
import { useRouter } from 'vue-router';
import { DataService } from '@/config/dataService/dataService';
import { notification } from 'ant-design-vue';
import L from 'leaflet';
import 'leaflet.fullscreen/dist/Control.FullScreen.css';
import { FullScreen } from 'leaflet.fullscreen';
import '@geoman-io/leaflet-geoman-free/dist/leaflet-geoman.css';
import '@geoman-io/leaflet-geoman-free';
import { Main } from './styled';
import { typeMeta, ifaceStatusColor } from '@/utility/deviceTypeIcons';
import { loadColor } from '@/utility/opticalColors';

interface DeviceMarker {
  id: number;
  ip: string;
  name: string;
  description: string;
  model?: { name: string; type?: string };
  group?: { name: string };
  pinger?: { latency: number; last_change: string } | null;
  coordinates: any;
}
interface OntMarker {
  id: number;
  name: string;
  ident: string | null;
  status?: string;
  device: { id: number; name: string; ip: string };
  optical?: { rx: number | null; olt_rx: number | null; tx: number | null };
  coordinates: any;
}
interface LinkLine {
  id: number;
  coordinates: { src: any; dest: any };
  devices: { src: { id: number; ip: string; name: string }; dest: { id: number; ip: string; name: string } };
  source?: 'manual' | 'fdb' | 'lldp';
  interfaces?: { src: { name: string; status?: string } | null; dest: { name: string; status?: string } | null };
}

const router = useRouter();
const loading = ref(true);
const devices = ref<DeviceMarker[]>([]);
const onts = ref<OntMarker[]>([]);
const links = ref<LinkLine[]>([]);
const groupOptions = ref<{ id: number; name: string }[]>([]);
const filtersOpen = ref(false);
const mapRef = ref<any>(null);

const layers = reactive({ devices: true, onts: true, links: true });
const selectedGroups = ref<number[]>([]);
const statusFilter = ref<'all' | 'online' | 'offline'>('all');
const searchOpen = ref(false);
const searchDeviceId = ref<number | undefined>(undefined);
const deviceMarkerRefs = new Map<number, L.Marker>();
function registerDeviceMarkerRef(id: number, obj: L.Marker) {
  deviceMarkerRefs.set(id, obj);
}

// `coordinates` on the backend is whatever was written to the device's
// `coordinates` field — for real seeded data that's a reverse-geocode
// result object ({lat, lon} as strings, plus address metadata); parsed
// defensively since a plain [lat, lng] array was also a plausible shape.
function toLatLng(coordinates: any): [number, number] | null {
  if (!coordinates) return null;
  if (Array.isArray(coordinates) && coordinates.length >= 2) {
    const [lat, lng] = coordinates.map(Number);
    return Number.isFinite(lat) && Number.isFinite(lng) ? [lat, lng] : null;
  }
  const lat = Number(coordinates.lat);
  const lng = Number(coordinates.lon ?? coordinates.lng);
  return Number.isFinite(lat) && Number.isFinite(lng) ? [lat, lng] : null;
}

function groupBody() {
  return selectedGroups.value.length ? selectedGroups.value.map((id) => ({ id })) : undefined;
}

async function load() {
  loading.value = true;
  try {
    const [devRes, ontRes, linkRes] = await Promise.allSettled([
      DataService.put('/maps/devices', { groups: groupBody() }),
      DataService.put('/maps/onts', { groups: groupBody() }),
      DataService.put('/maps/device-links', { groups: groupBody() }),
    ]);
    if (devRes.status === 'fulfilled') devices.value = devRes.value.data.data || [];
    if (ontRes.status === 'fulfilled') onts.value = ontRes.value.data.data || [];
    if (linkRes.status === 'fulfilled') links.value = linkRes.value.data.data || [];
  } catch (err: any) {
    notification.error({ message: 'Could not load map data', description: err?.response?.data?.error?.description || 'Please try again.' });
  } finally {
    loading.value = false;
  }
}

async function loadGroups() {
  try {
    const { data } = await DataService.get('/maps/groups');
    groupOptions.value = data.data || [];
  } catch {
    // ignore — the filter panel will just show no group options
  }
}

function deviceIsOnline(d: DeviceMarker): boolean | null {
  if (!d.pinger) return null;
  return d.pinger.latency > 0;
}
function ontIsOnline(o: OntMarker): boolean | null {
  if (!o.status) return null;
  return o.status === 'Online';
}
function passesStatusFilter(online: boolean | null): boolean {
  if (statusFilter.value === 'all') return true;
  if (online === null) return false;
  return statusFilter.value === 'online' ? online : !online;
}

// Multiple devices/ONTs genuinely can share one exact coordinate (a whole
// building's worth of equipment, or several devices whose location was
// typed in by hand) — plain Leaflet stacks markers exactly on top of each
// other in that case, so every one past the first is completely hidden
// behind it and looks like it's just missing from the map. This nudges
// each marker sharing a coordinate into a small ring around the real point
// (first one stays exactly on it) so all of them stay visible and clickable.
function spreadOverlapping<T>(items: { latlng: [number, number] }[]): void {
  const groups = new Map<string, typeof items>();
  for (const item of items) {
    const key = `${item.latlng[0].toFixed(6)},${item.latlng[1].toFixed(6)}`;
    if (!groups.has(key)) groups.set(key, []);
    groups.get(key)!.push(item);
  }
  for (const group of groups.values()) {
    if (group.length < 2) continue;
    const radius = 0.00045; // roughly 40-50m — separates pins clearly at typical zoom levels without materially misplacing them
    group.forEach((item, i) => {
      if (i === 0) return;
      const angle = (2 * Math.PI * i) / group.length;
      item.latlng = [item.latlng[0] + radius * Math.cos(angle), item.latlng[1] + radius * Math.sin(angle)];
    });
  }
}

const deviceMarkers = computed(() => {
  const items = devices.value
    .map((d) => ({ d, latlng: toLatLng(d.coordinates), online: deviceIsOnline(d) }))
    .filter((x): x is { d: DeviceMarker; latlng: [number, number]; online: boolean | null } => !!x.latlng && passesStatusFilter(x.online));
  spreadOverlapping(items);
  return items;
});
const ontMarkers = computed(() => {
  const items = onts.value
    .map((o) => ({ o, latlng: toLatLng(o.coordinates), online: ontIsOnline(o) }))
    .filter((x): x is { o: OntMarker; latlng: [number, number]; online: boolean | null } => !!x.latlng && passesStatusFilter(x.online));
  spreadOverlapping(items);
  return items;
});
const linkLines = computed(() =>
  links.value
    .map((l) => ({ l, src: toLatLng(l.coordinates.src), dest: toLatLng(l.coordinates.dest) }))
    .filter((x): x is { l: LinkLine; src: [number, number]; dest: [number, number] } => !!x.src && !!x.dest),
);

const mapCenter = computed<[number, number]>(() => {
  if (deviceMarkers.value.length) return deviceMarkers.value[0].latlng;
  return [27.7, 85.3]; // Nepal, roughly — a sane default given this ISP's coverage area
});

// This ISP's whole network is in Nepal, so the map is boxed to Nepal's real
// extent (with a little padding) — panning/zooming out can't wander off to
// the rest of the world. [south-west, north-east] corners.
const nepalBounds: [[number, number], [number, number]] = [
  [26.0, 79.5],
  [30.7, 88.5],
];

function escapeHtml(s: string) {
  return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

// Colored-dot marker icons — green for online, red for offline, grey when
// status can't be determined — matching the size difference between the
// two marker types (devices are the "important" layer, ONTs are denser).
// Devices additionally get a small name/IP label under the dot (matching
// the topology graph's node labels); ONTs stay label-less since there can
// be hundreds of them on one map and labelling every one would just be
// visual noise.
//
// Device pins are split half/half: one half is the solid status colour
// (up/down/unknown), the other half is that device's type colour — same
// accent used for its icon on the topology graph — so "is it ok" and
// "what kind of device is this" both read off one pin without needing to
// open its popup. ONTs don't have a meaningful "device type" the same way,
// so they stay a plain solid dot.
function statusDotIcon(online: boolean | null, size: number, label?: string, deviceType?: string) {
  const statusColor = online === null ? '#8c90a4' : online ? '#16a34a' : '#ef4444';
  const background = deviceType !== undefined ? `linear-gradient(90deg, ${statusColor} 50%, ${typeMeta(deviceType).color} 50%)` : statusColor;
  const labelHtml = label ? `<span class="map-dot-icon__label">${escapeHtml(label)}</span>` : '';
  return L.divIcon({
    className: label ? 'map-dot-icon map-dot-icon--labelled' : 'map-dot-icon',
    html: `<span class="map-dot-icon__dot" style="background:${background};width:${size}px;height:${size}px"></span>${labelHtml}`,
    iconSize: label ? [160, size] : [size, size],
    iconAnchor: [size / 2, size / 2],
    popupAnchor: [0, -size / 2],
  });
}

function statusLabel(online: boolean | null) {
  return online === null ? 'Unknown' : online ? 'Online' : 'Offline';
}

// Auto-discovered wires (LLDP neighbor data, or learned FDB/MAC entries)
// get their own colour + dashed stroke so they read as "the system found
// this" rather than "someone drew this" — manually-added links keep the
// original solid blue. Matches the purple used for the "LLDP" tag on the
// device-detail Topology tab.
function linkStyle(source?: string): { color: string; dashArray?: string } {
  if (source === 'lldp') return { color: '#8b5cf6', dashArray: '6 5' };
  if (source === 'fdb') return { color: '#0d9488', dashArray: '2 5' };
  return { color: '#1868db' };
}
function linkSourceLabel(source?: string) {
  if (source === 'lldp') return 'via LLDP';
  if (source === 'fdb') return 'via FDB';
  return 'manual';
}

function goToDevice(id: number) {
  router.push({ name: 'device-detail', params: { id } });
}
function goToEditDevice(id: number) {
  router.push({ name: 'device-management-edit', params: { id } });
}

// A pin's popup starts with only what's already in the bulk map payload
// (name/IP/model/group/status) — CPU/memory and interface up/down counts
// aren't part of that bulk response (same reason they aren't part of the
// topology graph's either), so they're fetched only when a popup is
// actually opened, `from=store`/cached, same endpoints and same trade-off
// as the topology graph's device drawer.
const deviceExtraCache = new Map<number, any>();
const deviceResourcesCache = new Map<number, any>();
const popupExtra = reactive<Record<number, any>>({});
const popupResources = reactive<Record<number, any>>({});
async function onDevicePopupOpen(id: number) {
  if (!(id in popupExtra)) {
    if (!deviceExtraCache.has(id)) {
      try {
        const { data } = await DataService.get(`/device/${id}`);
        deviceExtraCache.set(id, data.data);
      } catch {
        deviceExtraCache.set(id, null);
      }
    }
    popupExtra[id] = deviceExtraCache.get(id);
  }
  if (!(id in popupResources)) {
    if (!deviceResourcesCache.has(id)) {
      try {
        const { data } = await DataService.get(`/component/switches/resources/${id}`, { from: 'store' });
        deviceResourcesCache.set(id, data.data);
      } catch {
        deviceResourcesCache.set(id, null);
      }
    }
    popupResources[id] = deviceResourcesCache.get(id);
  }
}

// Search box: find a device already on the map by name/IP and jump to it —
// flies the view in and opens its popup, same as clicking the pin directly.
function onSearchSelect(id: number) {
  const marker = deviceMarkerRefs.get(id);
  const map = mapRef.value?.leafletObject;
  if (!marker || !map) return;
  map.flyTo(marker.getLatLng(), Math.max(map.getZoom(), 16));
  map.once('moveend', () => marker.openPopup());
  searchDeviceId.value = undefined;
  searchOpen.value = false;
}

// Adds Leaflet's native fullscreen control, plus Geoman's "Edit Layers" /
// "Drag Layers" toolbar (matching the real production Map page exactly),
// once the underlying map instance exists — vue-leaflet has no wrapper for
// either plugin, so both are wired up directly against the real L.Map
// object exposed by LMap's `ready` event.
function onMapReady() {
  const map = mapRef.value?.leafletObject;
  if (!map || map._extraControlsAdded) return;
  map._extraControlsAdded = true;

  // A real Leaflet control (not a separately absolute-positioned div) so it
  // stacks in the same topleft column as zoom/fullscreen/edit-drag instead
  // of floating over them — matching how the original page builds its own
  // filter button.
  const FilterControl = L.Control.extend({
    onAdd() {
      const container = L.DomUtil.create('div', 'leaflet-bar leaflet-control map-filter-control');
      const link = L.DomUtil.create('a', '', container);
      link.href = '#';
      link.title = 'Filters';
      link.setAttribute('role', 'button');
      link.innerHTML = '<i class="fa fa-filter"></i>';
      L.DomEvent.on(link, 'click', (e: Event) => {
        L.DomEvent.stopPropagation(e);
        e.preventDefault();
        filtersOpen.value = !filtersOpen.value;
        if (filtersOpen.value) searchOpen.value = false;
      });
      L.DomEvent.disableClickPropagation(container);
      return container;
    },
  });
  map.addControl(new FilterControl({ position: 'topleft' }));

  const SearchControl = L.Control.extend({
    onAdd() {
      const container = L.DomUtil.create('div', 'leaflet-bar leaflet-control map-filter-control');
      const link = L.DomUtil.create('a', '', container);
      link.href = '#';
      link.title = 'Search devices';
      link.setAttribute('role', 'button');
      link.innerHTML = '<i class="fa fa-search"></i>';
      L.DomEvent.on(link, 'click', (e: Event) => {
        L.DomEvent.stopPropagation(e);
        e.preventDefault();
        searchOpen.value = !searchOpen.value;
        if (searchOpen.value) filtersOpen.value = false;
      });
      L.DomEvent.disableClickPropagation(container);
      return container;
    },
  });
  map.addControl(new SearchControl({ position: 'topleft' }));

  map.addControl(new FullScreen({ position: 'topleft' }));
  // Only the edit/drag buttons — no draw tools — same as the original.
  // Geoman's drag mode makes every layer on the map draggable (however it
  // was added), so a device marker's native `dragend` event just fires
  // normally afterwards; no extra wiring needed beyond the @dragend handler
  // already on each device l-marker below.
  (map as any).pm.addControls({
    position: 'topleft',
    drawMarker: false,
    drawCircleMarker: false,
    drawPolyline: false,
    drawRectangle: false,
    drawPolygon: false,
    drawCircle: false,
    drawText: false,
    cutPolygon: false,
    removalMode: false,
    rotateMode: false,
    editMode: true,
    dragMode: true,
  });
}

const savingPosition = ref<number | null>(null);
async function onDeviceDragEnd(d: DeviceMarker, e: any) {
  const { lat, lng } = e.target.getLatLng();
  savingPosition.value = d.id;
  try {
    await DataService.put(`/device/${d.id}`, { coordinates: { lat: String(lat), lon: String(lng) } });
    d.coordinates = { lat: String(lat), lon: String(lng) };
    notification.success({ message: `${d.name} moved`, description: 'New position saved.' });
  } catch (err: any) {
    notification.error({ message: 'Could not save new position', description: err?.response?.data?.error?.description || 'Please try again.' });
    load();
  } finally {
    savingPosition.value = null;
  }
}

onMounted(async () => {
  await loadGroups();
  load();
});
</script>

<template>
  <sdPageHeader :routes="[{ path: '/', breadcrumbName: 'Dashboard' }, { path: '', breadcrumbName: 'Map' }]" class="ninjadash-page-header-main" />
  <Main>
    <div class="map-wrap">
      <div v-if="filtersOpen" class="map-filters">
        <div class="map-filters__row">
          <label><a-checkbox v-model:checked="layers.devices" /> Devices ({{ deviceMarkers.length }})</label>
          <div class="map-filters__legend map-filters__legend--pins">
            <span><i class="map-filters__pin-half" style="background: linear-gradient(90deg, #16a34a 50%, #94a3b8 50%)"></i> up</span>
            <span><i class="map-filters__pin-half" style="background: linear-gradient(90deg, #ef4444 50%, #94a3b8 50%)"></i> down</span>
            <span style="flex-basis: 100%; margin-top: 2px">right half = device type colour (see Topology graph legend)</span>
          </div>
        </div>
        <div class="map-filters__row">
          <label><a-checkbox v-model:checked="layers.onts" /> ONTs ({{ ontMarkers.length }})</label>
        </div>
        <div class="map-filters__row">
          <label><a-checkbox v-model:checked="layers.links" /> Links ({{ linkLines.length }})</label>
          <div class="map-filters__legend">
            <span><i style="background: #1868db"></i> Manual</span>
            <span><i style="background: #8b5cf6"></i> LLDP</span>
            <span><i style="background: #0d9488"></i> FDB</span>
          </div>
        </div>
        <div class="map-filters__row map-filters__row--groups">
          <label class="map-filters__label">Device groups</label>
          <a-select v-model:value="selectedGroups" mode="multiple" allow-clear placeholder="All groups" style="width: 100%" :options="groupOptions.map((g) => ({ value: g.id, label: g.name }))" @change="load" />
        </div>
        <div class="map-filters__row map-filters__row--groups">
          <label class="map-filters__label">Status</label>
          <a-select v-model:value="statusFilter" style="width: 100%">
            <a-select-option value="all">All</a-select-option>
            <a-select-option value="online">Online</a-select-option>
            <a-select-option value="offline">Offline</a-select-option>
          </a-select>
        </div>
      </div>

      <div v-if="searchOpen" class="map-filters map-search-panel">
        <label class="map-filters__label">Search devices</label>
        <a-select
          v-model:value="searchDeviceId"
          show-search
          allow-clear
          placeholder="Search by name or IP..."
          style="width: 100%"
          :filter-option="(input: string, opt: any) => opt.label.toLowerCase().includes(input.toLowerCase())"
          :options="deviceMarkers.map((item) => ({ value: item.d.id, label: `${item.d.name} (${item.d.ip})` }))"
          @select="onSearchSelect"
        />
      </div>

      <a-spin v-if="loading" class="map-spin" size="large" />

      <l-map
        v-if="deviceMarkers.length || ontMarkers.length"
        ref="mapRef"
        :center="mapCenter"
        :zoom="13"
        :min-zoom="6"
        :max-bounds="nepalBounds"
        :max-bounds-viscosity="1"
        class="map-canvas"
        @ready="onMapReady"
      >
        <!--
          LControlLayers renders nothing itself (`render() { return null }`)
          — it only registers the underlying Leaflet control via
          provide/inject. Each base l-tile-layer registers itself with that
          control independently through the same injection, which only
          works if they're mounted as siblings under l-map — nesting them
          inside l-control-layer (the previous, natural-looking structure)
          silently discarded every tile layer, which is why no tiles (and
          no base-layer options) ever rendered.
        -->
        <l-control-layer position="topright" />
        <l-tile-layer url="https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}" attribution="&copy; Esri" layer-type="base" name="Satellite" :visible="false" />
        <l-tile-layer url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png" attribution="&copy; OpenStreetMap contributors" layer-type="base" name="OpenStreetMap" :visible="true" />
        <l-tile-layer url="https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png" attribution="&copy; OpenTopoMap contributors" layer-type="base" name="Terrain" :visible="false" />
        <l-tile-layer url="https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png" attribution="&copy; CARTO" layer-type="base" name="Light" :visible="false" />
        <l-tile-layer url="https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png" attribution="&copy; CARTO" layer-type="base" name="Dark" :visible="false" />

        <l-layer-group v-if="layers.links">
          <l-polyline
            v-for="item in linkLines"
            :key="'link-' + item.l.id"
            :lat-lngs="[item.src, item.dest]"
            :color="linkStyle(item.l.source).color"
            :dash-array="linkStyle(item.l.source).dashArray"
            :weight="2.5"
          >
            <l-tooltip>
              <div class="map-link-tip">
                <div>{{ item.l.devices.src.name }} ↔ {{ item.l.devices.dest.name }} ({{ linkSourceLabel(item.l.source) }})</div>
                <div v-if="item.l.interfaces?.src || item.l.interfaces?.dest" class="map-link-tip__ifaces">
                  <span v-if="item.l.interfaces?.src">
                    <i class="map-link-tip__dot" :style="{ background: ifaceStatusColor(item.l.interfaces.src.status) }"></i>{{ item.l.interfaces.src.name }}
                  </span>
                  <span v-if="item.l.interfaces?.dest">
                    <i class="map-link-tip__dot" :style="{ background: ifaceStatusColor(item.l.interfaces.dest.status) }"></i>{{ item.l.interfaces.dest.name }}
                  </span>
                </div>
              </div>
            </l-tooltip>
          </l-polyline>
        </l-layer-group>

        <l-layer-group v-if="layers.devices">
          <l-marker
            v-for="item in deviceMarkers"
            :key="'dev-' + item.d.id"
            :lat-lng="item.latlng"
            :icon="statusDotIcon(item.online, 18, `${item.d.name} (${item.d.ip})`, item.d.model?.type)"
            @dragend="onDeviceDragEnd(item.d, $event)"
            @ready="(obj: any) => registerDeviceMarkerRef(item.d.id, obj)"
            @popupopen="onDevicePopupOpen(item.d.id)"
          >
            <l-popup>
              <div class="map-popup">
                <strong>{{ item.d.name }}</strong>
                <div>{{ item.d.ip }}</div>
                <div class="log-subtext">{{ item.d.model?.name }}</div>
                <div class="log-subtext">{{ item.d.group?.name }}</div>
                <div class="map-popup__status" :class="item.online ? 'is-online' : 'is-offline'">{{ statusLabel(item.online) }}</div>
                <div v-if="item.d.pinger?.last_change" class="log-subtext">since {{ item.d.pinger.last_change }}</div>
                <div v-if="popupResources[item.d.id]?.cpu || popupResources[item.d.id]?.memory" class="map-popup__pills">
                  <span v-if="popupResources[item.d.id].cpu?.util != null" class="map-popup__pill" :style="{ background: loadColor(popupResources[item.d.id].cpu.util) }">CPU {{ Math.round(popupResources[item.d.id].cpu.util) }}%</span>
                  <span v-if="popupResources[item.d.id].memory?.util != null" class="map-popup__pill" :style="{ background: loadColor(popupResources[item.d.id].memory.util) }">Mem {{ Math.round(popupResources[item.d.id].memory.util) }}%</span>
                </div>
                <div v-if="popupExtra[item.d.id]?.ifaces_stat" class="log-subtext">
                  Interfaces: {{ popupExtra[item.d.id].ifaces_stat.up }} up<template v-if="popupExtra[item.d.id].ifaces_stat.down"> / {{ popupExtra[item.d.id].ifaces_stat.down }} down</template>
                </div>
                <div class="map-popup__actions">
                  <a @click="goToDevice(item.d.id)">View device →</a>
                  <a @click="goToEditDevice(item.d.id)">Edit device →</a>
                </div>
              </div>
            </l-popup>
          </l-marker>
        </l-layer-group>

        <l-layer-group v-if="layers.onts">
          <l-marker v-for="item in ontMarkers" :key="'ont-' + item.o.id" :lat-lng="item.latlng" :icon="statusDotIcon(item.online, 12)">
            <l-popup>
              <div class="map-popup">
                <strong>{{ item.o.name }}</strong>
                <div v-if="item.o.ident" class="log-subtext">{{ item.o.ident }}</div>
                <div class="log-subtext">{{ item.o.device.ip }} ({{ item.o.device.name }})</div>
                <div class="map-popup__status" :class="item.online ? 'is-online' : 'is-offline'">{{ statusLabel(item.online) }}</div>
                <div v-if="item.o.optical?.rx != null">RX: {{ item.o.optical.rx }} dBm</div>
                <div class="map-popup__actions">
                  <a @click="goToDevice(item.o.device.id)">View device →</a>
                </div>
              </div>
            </l-popup>
          </l-marker>
        </l-layer-group>
      </l-map>

      <div v-else-if="!loading" class="map-empty">
        <unicon name="map-marker-alt"></unicon>
        <p>No devices with coordinates set yet — set a location on a device's edit page to see it here.</p>
      </div>
    </div>
  </Main>
</template>

<style scoped>
.map-wrap {
  position: relative;
  height: calc(100vh - 160px);
  min-height: 500px;
}
.map-canvas {
  width: 100%;
  height: 100%;
  z-index: 1;
}
/* The filter toggle is a real Leaflet control (built in onMapReady, styled
   with Leaflet's own leaflet-bar look) so it stacks in the same topleft
   column as zoom/fullscreen/edit-drag instead of floating over them as a
   separately-positioned overlay. The panel it opens is anchored to the
   right of that whole control column rather than under any one button, so
   it doesn't need to track the stack's height as more controls are added. */
:global(.map-filter-control a) {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 30px;
  height: 30px;
  color: #5a5f7d;
  background: #fff;
}
:global(.map-filter-control a:hover) {
  color: #1868db;
}
.map-filters {
  position: absolute;
  top: 14px;
  left: 60px;
  z-index: 500;
  background: #fff;
  border: 1px solid #e6e9f1;
  border-radius: 6px;
  padding: 14px;
  width: 240px;
  box-shadow: 0 4px 14px rgba(0, 0, 0, 0.08);
}
.map-filters__row {
  margin-bottom: 10px;
  font-size: 13px;
  color: #272b41;
}
.map-filters__row label {
  display: flex;
  align-items: center;
  gap: 8px;
}
.map-filters__label {
  display: block;
  font-weight: 600;
  color: #5a5f7d;
  margin-bottom: 6px;
}
.map-filters__legend {
  display: flex;
  gap: 10px;
  margin: 6px 0 0 22px;
  font-size: 11px;
  color: #8c90a4;
}
.map-filters__legend span {
  display: inline-flex;
  align-items: center;
  gap: 4px;
}
.map-filters__legend i {
  display: inline-block;
  width: 10px;
  height: 3px;
  border-radius: 2px;
}
.map-filters__legend--pins {
  flex-wrap: wrap;
}
.map-filters__pin-half {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  border: 1px solid rgba(0, 0, 0, 0.15);
}
.map-spin {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  z-index: 400;
}
.map-empty {
  height: 100%;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 10px;
  color: #8c90a4;
  text-align: center;
  padding: 40px;
}
.map-empty :deep(svg) {
  width: 32px;
  height: 32px;
}
.map-popup {
  display: flex;
  flex-direction: column;
  gap: 3px;
  font-size: 13px;
}
.map-popup__status {
  font-size: 12px;
  font-weight: 700;
}
.map-popup__status.is-online {
  color: #1a7a3a;
}
.map-popup__status.is-offline {
  color: #a60a0a;
}
.map-popup__actions {
  display: flex;
  flex-direction: column;
  margin-top: 4px;
}
.map-popup__pills {
  display: flex;
  gap: 5px;
  margin: 2px 0;
}
.map-popup__pill {
  font-size: 10px;
  font-weight: 700;
  color: #fff;
  padding: 1px 6px;
  border-radius: 3px;
}
.map-link-tip {
  font-size: 12px;
}
.map-link-tip__ifaces {
  display: flex;
  gap: 10px;
  margin-top: 3px;
  font-size: 11px;
  color: #5a5f7d;
}
.map-link-tip__ifaces span {
  display: inline-flex;
  align-items: center;
  gap: 4px;
}
.map-link-tip__dot {
  display: inline-block;
  width: 7px;
  height: 7px;
  border-radius: 50%;
}
.map-popup a {
  color: #1868db;
  cursor: pointer;
}
.log-subtext {
  font-size: 11px;
  color: #8c90a4;
}
:global(.map-dot-icon) {
  display: flex;
  align-items: center;
}
:global(.map-dot-icon__dot) {
  display: block;
  flex-shrink: 0;
  border-radius: 50%;
  border: 2px solid #fff;
  box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.35);
}
/* Two or more OLTs sitting at (or very near) the same real coordinate get
   spread into a small ring so their dots don't sit exactly on top of each
   other, but their always-on name/IP labels are wide enough to still
   overlap and turn into unreadable text soup at that spacing. Shown on
   hover only instead — dots stay identifiable via colour/position at a
   glance, full name+IP shows for whichever one the pointer is actually
   over, and z-index is bumped so a hovered label isn't hidden under a
   neighbouring dot. */
:global(.map-dot-icon__label) {
  display: none;
  margin-left: 5px;
  padding: 1px 6px;
  border-radius: 3px;
  background: rgba(255, 255, 255, 0.92);
  border: 1px solid rgba(0, 0, 0, 0.15);
  font-size: 11px;
  font-weight: 600;
  color: #272b41;
  white-space: nowrap;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.15);
}
:global(.map-dot-icon--labelled:hover) {
  z-index: 10000 !important;
}
:global(.map-dot-icon--labelled:hover .map-dot-icon__label) {
  display: inline-block;
}
</style>
