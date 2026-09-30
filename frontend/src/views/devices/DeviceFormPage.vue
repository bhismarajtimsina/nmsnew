<script setup lang="ts">
import { ref, reactive, onMounted, computed, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { DataService } from '@/config/dataService/dataService';
import { notification, Modal } from 'ant-design-vue';
import { Main } from '../styled';

const route = useRoute();
const router = useRouter();
const editingId = computed(() => (route.params.id ? Number(route.params.id) : null));
const isEdit = computed(() => editingId.value !== null);

const loading = ref(true);
const saving = ref(false);
const removing = ref(false);
const detecting = ref(false);
const accessOptions = ref<{ id: number; name: string }[]>([]);
// A model's `pollers` comes back as an object mapping poller name -> its
// default interval in seconds (e.g. `{ system: 300, counters: 180 }`), not
// a plain string array — confirmed against the real API. A device's own
// `pollers` override IS a plain string array of poller names though (see
// the Device schema), so the two shapes genuinely differ and get converted
// between below.
const modelOptions = ref<{ id: number; name: string; vendor: string; pollers?: Record<string, number> }[]>([]);
const groupOptions = ref<{ id: number; name: string }[]>([]);

const form = reactive({
  ip: '',
  access: undefined as number | undefined,
  enabled: true,
  name: '',
  location: '',
  model: undefined as number | undefined,
  mac: '',
  group: undefined as number | undefined,
  serial: '',
  description: '',
  params: {} as Record<string, any>,
  pollers: null as string[] | null,
});
const meta = reactive({ deviceId: 0, createdAt: '', updatedAt: '' });
const paramsText = ref('{}');
const usingCustomPollers = computed(() => form.pollers !== null);
const selectedModel = computed(() => modelOptions.value.find((m) => m.id === form.model));
// The poller *names* available for the current model, each paired with its
// default interval so the "custom" checklist can show e.g. "system (300s)"
// instead of a bare, unexplained key.
const selectedModelPollerOptions = computed(() => {
  const pollers = selectedModel.value?.pollers || {};
  return Object.entries(pollers).map(([name, seconds]) => ({ value: name, label: `${name} (${seconds}s)` }));
});
const selectedModelPollerNames = computed(() => Object.keys(selectedModel.value?.pollers || {}));

async function load() {
  loading.value = true;
  const [accessRes, modelRes, groupRes] = await Promise.allSettled([
    DataService.get('/device-access'),
    DataService.get('/device-model'),
    DataService.get('/device-group'),
  ]);
  if (accessRes.status === 'fulfilled') accessOptions.value = accessRes.value.data.data || [];
  if (modelRes.status === 'fulfilled') modelOptions.value = modelRes.value.data.data || [];
  if (groupRes.status === 'fulfilled') groupOptions.value = groupRes.value.data.data || [];

  if (isEdit.value) {
    try {
      const { data } = await DataService.get(`/device/${editingId.value}`);
      const d = data.data;
      Object.assign(form, {
        ip: d.ip,
        access: d.access?.id,
        enabled: d.enabled ?? true,
        name: d.name,
        location: d.location || '',
        model: d.model?.id,
        mac: d.mac || '',
        group: d.group?.id,
        serial: d.serial || '',
        description: d.description || '',
        // PHP serializes an empty associative array as JSON `[]`, not `{}`
        // (it can't tell an empty array from an empty object) — a device
        // that's never had any params set comes back this way. `[] || {}`
        // still picks `[]` since empty arrays are truthy in JS, so
        // form.params silently became a real Array. Setting
        // `form.params.oxidized_enabled = true` on an Array still "works"
        // (arrays are objects, so the assignment succeeds) but
        // JSON.stringify on an Array only serializes numeric-index
        // elements — the property was there in memory the whole time,
        // just invisible to the save payload every time. Normalize any
        // array response to a plain object before it ever reaches form.params.
        params: normalizeParams(d.params),
        pollers: d.pollers || null,
      });
      Object.assign(meta, { deviceId: d.id, createdAt: d.created_at, updatedAt: d.updated_at });
      paramsText.value = JSON.stringify(form.params, null, 2);
      loadSideData();
    } catch (err: any) {
      notification.error({
        message: 'Could not load device',
        description: err?.response?.data?.error?.description || 'Please try again.',
      });
    }
  }
  loading.value = false;
}
onMounted(load);

// See the comment above the `params: normalizeParams(d.params)` call in
// load() — an Array here silently drops any property written onto it once
// JSON.stringify'd, so every path that can populate form.params (initial
// load, and the JSON textarea, which a user could just as easily paste
// "[]" or "" into) is normalized through this rather than trusting the
// value is already an object.
function normalizeParams(value: any): Record<string, any> {
  return value && typeof value === 'object' && !Array.isArray(value) ? value : {};
}

// `form.params` is the single source of truth for the device's `params`
// object — both the Oxidized-enabled switch and the raw JSON textarea write
// into it (rather than each keeping their own copy, which is how a real bug
// happened here: the switch used to write straight into `form.params`, but
// the actual save payload was built from `JSON.parse(paramsText.value)` — a
// *separate* string only ever set once at load time. Toggling the switch
// never touched that string, so Save silently sent the old value and the
// toggle reverted on refresh even though the UI showed it as on).
function syncParamsFromText(): boolean {
  try {
    form.params = normalizeParams(JSON.parse(paramsText.value));
    return true;
  } catch {
    return false;
  }
}
function syncTextFromParams() {
  paramsText.value = JSON.stringify(form.params, null, 2);
}
function toggleOxidizedEnabled(checked: boolean) {
  form.params = normalizeParams(form.params);
  form.params.oxidized_enabled = checked;
  syncTextFromParams();
}
function updateVlanInternetOverride(value: string) {
  form.params = normalizeParams(form.params);
  form.params.vlan_internet = value;
  syncTextFromParams();
}

function buildPayload() {
  return {
    ip: form.ip,
    access: form.access ? { id: form.access } : undefined,
    enabled: form.enabled,
    name: form.name,
    location: form.location || undefined,
    // The Map page (Links & Topology > Map) plots devices from their
    // `coordinates` field, not `location` — those are two separate fields
    // on the backend (`location` is just a free-text lat,lng string shown
    // in this form; `coordinates` is what /maps/devices actually filters
    // and reads lat/lon from). Without this, a location set here would
    // never appear on the map. Sent only when hasCoordinates is true so an
    // empty/invalid location clears it instead of writing garbage.
    coordinates: hasCoordinates.value ? { lat: String(mapCenter.value[0]), lon: String(mapCenter.value[1]) } : null,
    model: form.model ? { id: form.model } : undefined,
    mac: form.mac || undefined,
    group: form.group ? { id: form.group } : undefined,
    serial: form.serial || undefined,
    description: form.description || undefined,
    params: form.params,
    pollers: form.pollers,
  };
}

async function submit() {
  if (!form.ip.trim() || !form.name.trim() || !form.access || !form.model || !form.group) {
    notification.error({ message: 'IP, name, access, model and group are required' });
    return;
  }
  // Pick up whatever's currently in the JSON textarea in case the user
  // edited it and hasn't blurred the field yet.
  if (!syncParamsFromText()) {
    notification.error({ message: 'Additional parameters must be valid JSON' });
    return;
  }
  saving.value = true;
  try {
    if (isEdit.value) {
      await DataService.put(`/device/${editingId.value}`, buildPayload());
      notification.success({ message: 'Device updated' });
      await load();
    } else {
      await DataService.post('/device', buildPayload());
      notification.success({ message: 'Device created' });
      router.push({ name: 'device-management' });
    }
  } catch (err: any) {
    notification.error({
      message: 'Could not save device',
      description: err?.response?.data?.error?.description || 'Please try again.',
    });
  } finally {
    saving.value = false;
  }
}

function confirmRemove() {
  if (!editingId.value) return;
  Modal.confirm({
    title: `Delete device "${form.name || form.ip}"?`,
    okText: 'Delete',
    okType: 'danger',
    onOk: async () => {
      removing.value = true;
      try {
        await DataService.delete(`/device/${editingId.value}`);
        notification.success({ message: 'Device deleted' });
        router.push({ name: 'device-management' });
      } catch (err: any) {
        notification.error({
          message: 'Could not delete device',
          description: err?.response?.data?.error?.description || 'Please try again.',
        });
      } finally {
        removing.value = false;
      }
    },
  });
}

// --- Get info from device (POST /device-detect — live SNMP probe, doesn't
// touch anything stored until the user actually clicks Save/Create) ---
async function detectDevice() {
  if (!form.ip || !form.access) {
    notification.error({ message: 'Enter an IP and choose an access profile first' });
    return;
  }
  detecting.value = true;
  try {
    const { data } = await DataService.post('/device-detect', { ip: form.ip, access_id: form.access });
    const d = data.data;
    if (d.name) form.name = d.name;
    if (d.mac) form.mac = d.mac;
    if (d.serial) form.serial = d.serial;
    if (d.model?.id) form.model = d.model.id;
    notification.success({ message: 'Device info detected' });
  } catch (err: any) {
    notification.error({
      message: 'Could not detect device',
      description: err?.response?.data?.error?.description || 'Check the IP and access profile.',
    });
  } finally {
    detecting.value = false;
  }
}

// --- Header actions: Run poller / Clear poll history / Go to device ---
const polling = ref(false);
const clearingHistory = ref(false);
async function runPoller() {
  if (!editingId.value) return;
  polling.value = true;
  try {
    await DataService.put(`/poller/poll-background/${editingId.value}`, {});
    notification.success({ message: 'Poller started in the background' });
  } catch (err: any) {
    notification.error({
      message: 'Could not start poller',
      description: err?.response?.data?.error?.description || 'Please try again.',
    });
  } finally {
    polling.value = false;
  }
}
function confirmClearHistory() {
  if (!editingId.value) return;
  Modal.confirm({
    title: 'Clear poll history?',
    content: 'This removes cached FDB / ONT-identification history collected for this device.',
    okText: 'Clear history',
    okType: 'danger',
    onOk: async () => {
      clearingHistory.value = true;
      try {
        await DataService.delete(`/poller/clear-history/${editingId.value}`);
        notification.success({ message: 'Poll history cleared' });
      } catch (err: any) {
        notification.error({
          message: 'Could not clear poll history',
          description: err?.response?.data?.error?.description || 'Please try again.',
        });
      } finally {
        clearingHistory.value = false;
      }
    },
  });
}
function goToDevice() {
  if (editingId.value) router.push({ name: 'device-detail', params: { id: editingId.value } });
}

// --- Side data loaded only in edit mode: links, oxidized, interfaces ---
interface LinkRow {
  id: number;
  src_device: { id: number; name: string };
  dest_device: { id: number; name: string };
  src_iface: { id: number; name: string } | null;
  dest_iface: { id: number; name: string } | null;
}
const links = ref<LinkRow[]>([]);
const oxidizedSupported = ref(false);
const oxidizedStatus = ref<any>(null);
// Oxidized (the external backup tool) 404s its own status endpoint for any
// device it has never successfully backed up yet — a real, legitimate state
// (recently enabled, not yet due for its next scheduled run, or unreachable),
// not an error in this app. That used to be swallowed silently by a bare
// `.catch(() => {})`, leaving the whole status block invisible with no
// explanation — indistinguishable from the feature being broken. Tracked
// explicitly now so the template can tell "still loading" / "genuinely no
// backup yet" / "a real error" apart and say so.
const oxidizedStatusState = ref<'idle' | 'loading' | 'loaded' | 'not-backed-up' | 'error'>('idle');
const interfaces = ref<{ id: number; name: string; description: string; bind_key: string; poll_enabled: boolean }[]>([]);
const interfacesLoaded = ref(false);

function loadOxidizedStatus() {
  if (!editingId.value) return;
  oxidizedStatusState.value = 'loading';
  DataService.get(`/component/oxidized/data/status/${editingId.value}`)
    .then((r) => {
      oxidizedStatus.value = r.data.data;
      oxidizedStatusState.value = 'loaded';
    })
    .catch((err: any) => {
      oxidizedStatusState.value = err?.response?.status === 404 ? 'not-backed-up' : 'error';
    });
}

async function loadSideData() {
  if (!editingId.value) return;
  const [linksRes, oxSupportedRes] = await Promise.allSettled([
    DataService.get(`/component/links/by-device/${editingId.value}`),
    DataService.get(`/component/oxidized/is-oxidized-supported/${editingId.value}`),
  ]);
  if (linksRes.status === 'fulfilled') links.value = linksRes.value.data.data || [];
  if (oxSupportedRes.status === 'fulfilled') {
    oxidizedSupported.value = !!oxSupportedRes.value.data.data;
    if (oxidizedSupported.value && form.params.oxidized_enabled) {
      loadOxidizedStatus();
    }
  }
  DataService.get('/device-interface', { device_id: editingId.value, limit: 999999 })
    .then((r) => {
      interfaces.value = r.data.data || [];
      interfacesLoaded.value = true;
    })
    .catch(() => {});
}
const pollingEnabledCount = computed(() => interfaces.value.filter((i) => i.poll_enabled).length);

// --- Configure port polling modal ---
const portModalOpen = ref(false);
const portSaving = ref<Record<number, boolean>>({});
async function togglePortPolling(iface: { id: number; poll_enabled: boolean }, checked: boolean) {
  portSaving.value[iface.id] = true;
  try {
    await DataService.put(`/device-interface/${iface.id}`, { poll_enabled: checked });
    iface.poll_enabled = checked;
  } catch (err: any) {
    notification.error({
      message: 'Could not update interface',
      description: err?.response?.data?.error?.description || 'Please try again.',
    });
  } finally {
    portSaving.value[iface.id] = false;
  }
}

// --- Oxidized config viewer ---
const oxidizedConfigModalOpen = ref(false);
const oxidizedConfigText = ref('');
const oxidizedConfigLoading = ref(false);
async function viewOxidizedConfig() {
  if (!editingId.value) return;
  oxidizedConfigModalOpen.value = true;
  oxidizedConfigLoading.value = true;
  try {
    const { data } = await DataService.get(`/component/oxidized/data/config/${editingId.value}`);
    oxidizedConfigText.value = data.data || '';
  } catch (err: any) {
    oxidizedConfigText.value = err?.response?.data?.error?.description || 'Could not load config.';
  } finally {
    oxidizedConfigLoading.value = false;
  }
}
watch(
  () => form.params.oxidized_enabled,
  (enabled) => {
    if (enabled && oxidizedSupported.value && editingId.value && oxidizedStatusState.value === 'idle') {
      loadOxidizedStatus();
    }
  },
);

// --- Coordinates map ---
const mapCenter = computed<[number, number]>(() => {
  const [lat, lng] = (form.location || '').split(',').map((v) => Number(v.trim()));
  return Number.isFinite(lat) && Number.isFinite(lng) ? [lat, lng] : [0, 0];
});
const hasCoordinates = computed(() => mapCenter.value[0] !== 0 || mapCenter.value[1] !== 0);
function setLocationFromMap(e: any) {
  // vue-leaflet doesn't declare `click` as a formal component emit, so Vue
  // also attaches this handler as a native DOM listener on LMap's root
  // element (alongside the real Leaflet-level one) — that invocation gets a
  // plain browser MouseEvent with no `.latlng` and would otherwise throw.
  if (!e?.latlng) return;
  const { lat, lng } = e.latlng;
  form.location = `${lat.toFixed(7)},${lng.toFixed(7)}`;
}
// Dragging the existing pin to a new spot is a third way to set the
// location, alongside clicking the map and typing exact coordinates.
function onMarkerDragEnd(e: any) {
  const { lat, lng } = e.target.getLatLng();
  form.location = `${lat.toFixed(7)},${lng.toFixed(7)}`;
}
// Manual latitude/longitude entry alongside the click-on-map picker — both
// read/write the same `form.location` string, so typing exact coordinates
// here and clicking the map stay in sync either way.
const latInput = computed({
  get: () => (hasCoordinates.value ? mapCenter.value[0] : null),
  set: (v) => {
    form.location = `${(v ?? 0).toFixed(7)},${mapCenter.value[1].toFixed(7)}`;
  },
});
const lngInput = computed({
  get: () => (hasCoordinates.value ? mapCenter.value[1] : null),
  set: (v) => {
    form.location = `${mapCenter.value[0].toFixed(7)},${(v ?? 0).toFixed(7)}`;
  },
});

// --- Add link modal ---
const linkModalOpen = ref(false);
const linkSaving = ref(false);
const linkDeviceOptions = ref<{ id: number; name: string }[]>([]);
const linkDestInterfaces = ref<{ id: number; name: string }[]>([]);
const linkForm = reactive({ destDevice: undefined as number | undefined, srcIface: undefined as number | undefined, destIface: undefined as number | undefined });
async function openLinkModal() {
  linkModalOpen.value = true;
  Object.assign(linkForm, { destDevice: undefined, srcIface: undefined, destIface: undefined });
  linkDestInterfaces.value = [];
  if (!linkDeviceOptions.value.length) {
    try {
      const { data } = await DataService.get('/device/options');
      linkDeviceOptions.value = (data.data || []).filter((d: any) => d.id !== editingId.value);
    } catch {
      // ignore — the select will just be empty
    }
  }
}
async function onLinkDestDeviceChange(id: number) {
  linkForm.destDevice = id;
  linkForm.destIface = undefined;
  try {
    const { data } = await DataService.get('/device-interface', { device_id: id, limit: 999999 });
    linkDestInterfaces.value = (data.data || []).map((i: any) => ({ id: i.id, name: i.name }));
  } catch {
    linkDestInterfaces.value = [];
  }
}
async function submitLink() {
  if (!linkForm.destDevice) {
    notification.error({ message: 'Choose a target device' });
    return;
  }
  linkSaving.value = true;
  try {
    await DataService.post('/component/links', {
      src_device: { id: editingId.value },
      dest_device: { id: linkForm.destDevice },
      src_iface: linkForm.srcIface ? { id: linkForm.srcIface } : undefined,
      dest_iface: linkForm.destIface ? { id: linkForm.destIface } : undefined,
    });
    notification.success({ message: 'Link created' });
    linkModalOpen.value = false;
    const { data } = await DataService.get(`/component/links/by-device/${editingId.value}`);
    links.value = data.data || [];
  } catch (err: any) {
    notification.error({
      message: 'Could not create link',
      description: err?.response?.data?.error?.description || 'Please try again.',
    });
  } finally {
    linkSaving.value = false;
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
        notification.error({
          message: 'Could not delete link',
          description: err?.response?.data?.error?.description || 'Please try again.',
        });
      }
    },
  });
}

// --- QR code (reuses the same live-fetch pattern as the QR printing pages) ---
const qrWithLabel = ref(true);
const qrWithLogo = ref(true);
const qrSize = ref(400);
const qrCustomLabel = ref('');
const qrImage = ref('');
const qrLoading = ref(false);
async function loadQr() {
  if (!editingId.value) return;
  qrLoading.value = true;
  try {
    const { data } = await DataService.get(`/component/qr-generator/qr-code-base64/device/${editingId.value}`, {
      size: qrSize.value,
      with_logo: qrWithLogo.value,
      with_label: qrWithLabel.value,
      label: qrCustomLabel.value || '',
    });
    qrImage.value = data.data?.qr || '';
  } catch {
    qrImage.value = '';
  } finally {
    qrLoading.value = false;
  }
}
function downloadQr() {
  if (!qrImage.value) return;
  const a = document.createElement('a');
  a.href = qrImage.value;
  a.download = `${form.name || form.ip}-qr.png`;
  a.click();
}
function printQr() {
  if (!qrImage.value) return;
  const w = window.open('', '_blank');
  if (!w) return;
  w.document.write(`<img src="${qrImage.value}" style="max-width:100%" onload="window.print()" />`);
  w.document.close();
}
watch(
  [editingId, qrWithLabel, qrWithLogo, qrSize, qrCustomLabel],
  () => {
    if (editingId.value) loadQr();
  },
  { immediate: true },
);

const pageTitle = computed(() => (isEdit.value ? `Edit device ${form.name || form.ip}` : 'Add new device'));
</script>

<template>
  <sdPageHeader
    :routes="[{ path: '/', breadcrumbName: 'Dashboard' }, { path: '/management/device', breadcrumbName: 'Device management' }, { path: '', breadcrumbName: pageTitle }]"
    class="ninjadash-page-header-main"
  >
    <template #buttons>
      <div class="dv-form-actions">
        <sdButton type="light" @click="router.push({ name: 'device-management' })"><unicon name="arrow-left"></unicon> Back</sdButton>
        <sdButton type="primary" :loading="saving" @click="submit"><unicon name="save"></unicon> {{ isEdit ? 'Save' : 'Create' }}</sdButton>
        <template v-if="isEdit">
          <sdButton type="warning" :loading="polling" @click="runPoller"><unicon name="import"></unicon> Run poller</sdButton>
          <sdButton type="warning" :loading="clearingHistory" @click="confirmClearHistory"><unicon name="trash-alt"></unicon> Clear poll history</sdButton>
          <sdButton type="danger" :loading="removing" @click="confirmRemove"><unicon name="trash-alt"></unicon> Delete</sdButton>
          <sdButton type="light" @click="goToDevice"><unicon name="arrow-right"></unicon> Go to device</sdButton>
        </template>
      </div>
    </template>
  </sdPageHeader>
  <Main>
    <a-skeleton v-if="loading" active />
    <a-row v-else :gutter="25">
      <a-col :xs="24" :md="isEdit ? 10 : 24" :lg="isEdit ? 10 : 24">
        <sdCards title="Main" style="margin-bottom: 25px">
          <div v-if="isEdit" class="dv-meta">
            <p><strong>Device ID:</strong> {{ meta.deviceId }}</p>
            <p><strong>Created at:</strong> {{ meta.createdAt }}</p>
            <p><strong>Updated at:</strong> {{ meta.updatedAt }}</p>
          </div>
          <a-form layout="vertical">
            <a-form-item label="IP">
              <a-input v-model:value="form.ip" placeholder="192.168.1.10" />
            </a-form-item>
            <a-form-item label="Access">
              <a-select v-model:value="form.access" allow-clear style="width: 100%" :options="accessOptions.map((a) => ({ value: a.id, label: a.name }))" />
            </a-form-item>
            <a-form-item>
              <div class="dv-toggle-row"><span>Enabled</span> <a-switch v-model:checked="form.enabled" /></div>
            </a-form-item>
            <sdButton type="primary" block :loading="detecting" style="margin-bottom: 20px" @click="detectDevice">
              <unicon name="import"></unicon> Get info from device
            </sdButton>
            <a-form-item label="Name">
              <a-input v-model:value="form.name" placeholder="Name" />
            </a-form-item>
            <a-form-item label="Location">
              <a-input v-model:value="form.location" placeholder="lat,lng" />
            </a-form-item>
            <a-form-item label="Model">
              <!--
                162 real models load here (confirmed against /device-model) —
                ant-design's own virtual-scrolling only renders ~10 rows of
                the dropdown at a time for performance, which can look like
                most models are "missing" unless the dropdown itself is
                scrolled (not the page). Taller listHeight means far fewer
                searches ever need that scroll at all.
              -->
              <a-select
                v-model:value="form.model"
                show-search
                allow-clear
                style="width: 100%"
                :list-height="400"
                :filter-option="(input: string, option: any) => option.label.toLowerCase().includes(input.toLowerCase())"
                :options="modelOptions.map((m) => ({ value: m.id, label: `${m.vendor} ${m.name}` }))"
              />
            </a-form-item>
            <a-form-item label="MAC">
              <a-input v-model:value="form.mac" placeholder="AA:BB:CC:DD:EE:FF" />
            </a-form-item>
            <a-form-item label="Groups">
              <a-select v-model:value="form.group" allow-clear style="width: 100%" :options="groupOptions.map((g) => ({ value: g.id, label: g.name }))" />
            </a-form-item>
            <a-form-item label="Serial number">
              <a-input v-model:value="form.serial" placeholder="Serial number" />
            </a-form-item>
            <a-form-item label="Description">
              <a-textarea v-model:value="form.description" :rows="3" placeholder="Description" />
            </a-form-item>
          </a-form>
        </sdCards>

        <sdCards v-if="isEdit" title="Additional parameters">
          <p class="dv-hint">Raw per-device parameters (JSON) — e.g. <code>oxidized_enabled</code>, model-specific overrides.</p>
          <a-textarea v-model:value="paramsText" :rows="6" class="dv-json-editor" @blur="syncParamsFromText" />
        </sdCards>
      </a-col>

      <a-col v-if="isEdit" :xs="24" :md="14" :lg="14">
        <sdCards title="Links" style="margin-bottom: 25px">
          <template #button>
            <sdButton type="primary" size="small" @click="openLinkModal"><unicon name="plus"></unicon> Add link</sdButton>
          </template>
          <a-empty v-if="!links.length" description="Links not added" />
          <ul v-else class="dv-links-list">
            <li v-for="l in links" :key="l.id">
              <span>{{ l.src_iface?.name || l.src_device.name }} → {{ l.dest_device.name }}{{ l.dest_iface ? ' · ' + l.dest_iface.name : '' }}</span>
              <a class="dv-links-list__delete" @click="confirmDeleteLink(l)"><unicon name="trash-alt"></unicon></a>
            </li>
          </ul>
        </sdCards>

        <sdCards title="Coordinates" style="margin-bottom: 25px">
          <div class="dv-coords-manual">
            <a-form-item label="Latitude">
              <a-input-number v-model:value="latInput" :precision="7" :step="0.0001" style="width: 100%" placeholder="e.g. 26.6227689" />
            </a-form-item>
            <a-form-item label="Longitude">
              <a-input-number v-model:value="lngInput" :precision="7" :step="0.0001" style="width: 100%" placeholder="e.g. 87.5436672" />
            </a-form-item>
          </div>
          <div class="dv-map-wrap">
            <l-map :center="hasCoordinates ? mapCenter : [20, 0]" :zoom="hasCoordinates ? 15 : 2" class="dv-map" @click="setLocationFromMap">
              <l-tile-layer url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png" />
              <l-marker v-if="hasCoordinates" :lat-lng="mapCenter" :draggable="true" @dragend="onMarkerDragEnd" />
            </l-map>
          </div>
          <p class="dv-hint">Click the map, drag the pin, or type exact coordinates above.</p>
          <a
            v-if="hasCoordinates"
            :href="`https://www.google.com/maps?q=${mapCenter[0]},${mapCenter[1]}`"
            target="_blank"
            rel="noopener"
          >
            <unicon name="location-point"></unicon> Open in Google Maps
          </a>
        </sdCards>

        <sdCards title="Poller configuration" style="margin-bottom: 25px">
          <div class="dv-toggle-row">
            <span>Use default poller configuration</span>
            <a-switch :checked="!usingCustomPollers" @change="(v: boolean) => (form.pollers = v ? null : selectedModelPollerNames.slice())" />
          </div>
          <template v-if="usingCustomPollers">
            <p v-if="!selectedModelPollerOptions.length" class="dv-hint">Choose a model above to see its available pollers.</p>
            <div v-else class="dv-custom-pollers">
              <a-checkbox-group v-model:value="form.pollers" :options="selectedModelPollerOptions" />
            </div>
          </template>
        </sdCards>

        <sdCards title="Oxidized configuration" style="margin-bottom: 25px">
          <div v-if="!oxidizedSupported" class="dv-hint">This model isn't supported by Oxidized backups.</div>
          <template v-else>
            <div class="dv-toggle-row">
              <span>Enable oxidized backups</span>
              <a-switch :checked="form.params.oxidized_enabled" @change="toggleOxidizedEnabled" />
            </div>
            <template v-if="form.params.oxidized_enabled">
              <a-skeleton v-if="oxidizedStatusState === 'loading'" active :paragraph="{ rows: 3 }" />
              <div v-else-if="oxidizedStatusState === 'loaded' && oxidizedStatus" class="dv-oxidized-status">
                <p><strong>Model:</strong> {{ oxidizedStatus.model || '—' }}</p>
                <p><strong>Last status:</strong> {{ oxidizedStatus.last?.status || '—' }}</p>
                <p><strong>Start:</strong> {{ oxidizedStatus.last?.start || '—' }}</p>
                <p><strong>End:</strong> {{ oxidizedStatus.last?.end || '—' }}</p>
                <p><strong>Spent:</strong> {{ oxidizedStatus.last?.time?.toFixed ? oxidizedStatus.last.time.toFixed(2) : oxidizedStatus.last?.time }}<span v-if="oxidizedStatus.last?.time">s</span></p>
                <a @click="viewOxidizedConfig"><unicon name="file-alt"></unicon> View config</a>
              </div>
              <div v-else-if="oxidizedStatusState === 'not-backed-up'" class="dv-hint dv-hint--warn">
                <unicon name="clock-eight"></unicon>
                No backup recorded yet for this device — it may still be waiting for Oxidized's next
                scheduled run, or Oxidized can't reach it with the current access settings.
                <a @click="viewOxidizedConfig">Check config output →</a>
              </div>
              <div v-else-if="oxidizedStatusState === 'error'" class="dv-hint dv-hint--error">
                <unicon name="exclamation-triangle"></unicon>
                Could not load backup status — <a @click="loadOxidizedStatus">retry</a>.
              </div>
            </template>
          </template>
        </sdCards>

        <sdCards title="Provisioning" style="margin-bottom: 25px">
          <a-form-item label="Internet VLAN override">
            <a-input :value="form.params.vlan_internet" @update:value="updateVlanInternetOverride" placeholder="Leave blank to use the site-wide default (System configuration)" />
            <div class="dv-hint">
              Used by ONT registration / WAN-add macros on this device only. Leave blank to fall back to the
              global "Internet VLAN" setting under Configuration → System configuration.
            </div>
          </a-form-item>
        </sdCards>

        <sdCards title="Configure port polling" style="margin-bottom: 25px">
          <div class="dv-port-summary">
            <div>
              <p><strong>Count interfaces:</strong> {{ interfaces.length }}</p>
              <p><strong>Count polled interfaces:</strong> {{ pollingEnabledCount }}</p>
            </div>
            <sdButton type="primary" :disabled="!interfacesLoaded" @click="portModalOpen = true"><unicon name="edit"></unicon> Edit ports</sdButton>
          </div>
        </sdCards>

        <sdCards title="QR code">
          <div class="dv-qr-wrap">
            <div class="dv-qr-preview">
              <a-skeleton v-if="qrLoading" active />
              <img v-else-if="qrImage" :src="qrImage" alt="Device QR code" />
            </div>
            <div class="dv-qr-controls">
              <div class="dv-toggle-row"><span>With label</span> <a-switch v-model:checked="qrWithLabel" /></div>
              <div class="dv-toggle-row"><span>With logo</span> <a-switch v-model:checked="qrWithLogo" /></div>
              <div class="dv-qr-field"><label>Size</label> <a-input-number v-model:value="qrSize" :min="100" :max="1000" /></div>
              <div class="dv-qr-field"><label>Custom label</label> <a-input v-model:value="qrCustomLabel" /></div>
              <div class="dv-qr-actions">
                <sdButton type="light" @click="printQr"><unicon name="print"></unicon> Print</sdButton>
                <sdButton type="primary" @click="downloadQr"><unicon name="import"></unicon> Download</sdButton>
              </div>
            </div>
          </div>
        </sdCards>
      </a-col>
    </a-row>

    <a-modal v-model:visible="portModalOpen" title="Edit interface polling" width="720px" :footer="null">
      <a-table :data-source="interfaces" row-key="id" size="small" :pagination="{ pageSize: 15 }" :scroll="{ y: 480 }">
        <a-table-column title="Bind key" data-index="bind_key" :width="100" />
        <a-table-column title="Name" data-index="name" />
        <a-table-column title="Description" data-index="description" />
        <a-table-column title="Enable polling" :width="120">
          <template #default="{ record }">
            <a-switch
              :checked="record.poll_enabled"
              :loading="portSaving[record.id]"
              @change="(v: boolean) => togglePortPolling(record, v)"
            />
          </template>
        </a-table-column>
      </a-table>
    </a-modal>

    <a-modal v-model:visible="oxidizedConfigModalOpen" title="Oxidized config" width="720px" :footer="null">
      <a-skeleton v-if="oxidizedConfigLoading" active />
      <pre v-else class="dv-oxidized-config">{{ oxidizedConfigText }}</pre>
    </a-modal>

    <a-modal v-model:visible="linkModalOpen" title="Add link" width="480px">
      <a-form layout="vertical">
        <a-form-item label="Target device">
          <a-select
            v-model:value="linkForm.destDevice"
            show-search
            style="width: 100%"
            :filter-option="(input: string, option: any) => option.label.toLowerCase().includes(input.toLowerCase())"
            :options="linkDeviceOptions.map((d) => ({ value: d.id, label: d.name }))"
            @change="onLinkDestDeviceChange"
          />
        </a-form-item>
        <a-form-item label="This device's interface (optional)">
          <a-select v-model:value="linkForm.srcIface" allow-clear style="width: 100%" :options="interfaces.map((i) => ({ value: i.id, label: i.name }))" />
        </a-form-item>
        <a-form-item label="Target interface (optional)">
          <a-select v-model:value="linkForm.destIface" allow-clear style="width: 100%" :options="linkDestInterfaces.map((i) => ({ value: i.id, label: i.name }))" />
        </a-form-item>
      </a-form>
      <template #footer>
        <sdButton type="light" @click="linkModalOpen = false">Close</sdButton>
        <sdButton type="primary" :loading="linkSaving" @click="submitLink">Save</sdButton>
      </template>
    </a-modal>
  </Main>
</template>

<style scoped>
.dv-form-actions {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}
.dv-form-actions :deep(svg) {
  width: 13px;
  height: 13px;
  margin-right: 4px;
}
.dv-toggle-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  font-size: 13px;
  font-weight: 700;
  color: #272b41;
}
.dv-meta {
  margin-bottom: 16px;
  padding-bottom: 12px;
  border-bottom: 1px solid #eceef4;
}
.dv-meta p {
  margin: 0 0 4px;
  font-size: 13px;
  color: #5a5f7d;
}
.dv-hint {
  font-size: 12px;
  color: #8c90a4;
  margin-bottom: 10px;
}
.dv-hint--warn,
.dv-hint--error {
  display: flex;
  align-items: flex-start;
  gap: 6px;
  padding: 10px 12px;
  border-radius: 6px;
  line-height: 1.5;
}
.dv-hint--warn {
  background: rgba(212, 160, 23, 0.1);
  color: #ab7d0a;
}
.dv-hint--error {
  background: rgba(166, 10, 10, 0.08);
  color: #a60a0a;
}
.dv-hint--warn :deep(svg),
.dv-hint--error :deep(svg) {
  width: 14px;
  height: 14px;
  flex-shrink: 0;
  margin-top: 1px;
}
.dv-hint--warn a,
.dv-hint--error a {
  font-weight: 600;
  text-decoration: underline;
  white-space: nowrap;
}
.dv-json-editor :deep(textarea) {
  font-family: monospace;
  font-size: 12px;
}
.dv-links-list {
  list-style: none;
  margin: 0;
  padding: 0;
}
.dv-links-list li {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8px 0;
  border-bottom: 1px solid #f1f2f6;
  font-size: 13px;
  color: #5a5f7d;
}
.dv-links-list__delete {
  color: #8c90a4;
  cursor: pointer;
}
.dv-links-list__delete:hover {
  color: #e5484d;
}
.dv-coords-manual {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0 16px;
}
.dv-map-wrap {
  margin-bottom: 10px;
}
.dv-map {
  height: 280px !important;
  border-radius: 8px;
  overflow: hidden;
}
.dv-custom-pollers {
  margin-top: 14px;
}
.dv-custom-pollers :deep(.ant-checkbox-group) {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.dv-oxidized-status {
  margin-top: 14px;
  padding-top: 12px;
  border-top: 1px solid #eceef4;
}
.dv-oxidized-status p {
  margin: 0 0 4px;
  font-size: 13px;
  color: #5a5f7d;
}
.dv-oxidized-status a {
  color: #1868db;
  font-size: 13px;
  cursor: pointer;
}
.dv-oxidized-config {
  max-height: 500px;
  overflow: auto;
  background: #1e2433;
  color: #d9dde8;
  font-family: monospace;
  font-size: 12px;
  padding: 12px;
  border-radius: 6px;
  white-space: pre-wrap;
}
.dv-port-summary {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
}
.dv-port-summary p {
  margin: 0 0 4px;
  font-size: 13px;
  color: #5a5f7d;
}
.dv-qr-wrap {
  display: flex;
  gap: 20px;
  flex-wrap: wrap;
}
.dv-qr-preview {
  width: 180px;
  min-height: 180px;
  display: flex;
  align-items: center;
  justify-content: center;
}
.dv-qr-preview img {
  max-width: 100%;
}
.dv-qr-controls {
  flex: 1;
  min-width: 200px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.dv-qr-field {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}
.dv-qr-field label {
  font-size: 13px;
  font-weight: 700;
  color: #272b41;
}
.dv-qr-actions {
  display: flex;
  gap: 8px;
  margin-top: 6px;
}
</style>
