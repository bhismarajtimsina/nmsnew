<!--
  The OLT dashboard's "unregistered_onts" tab: ONUs the OLT has detected on
  a PON port but that aren't yet bound to a managed interface. Reverse
  engineered from the UnregisteredOnts component in DeviceInfo-CGuBjiZ6.js.

  The registration wizard below (dynamic parameter form generated from the
  model's registration macro, with preview/execute steps) IS built out now
  — a real macro exists for Huawei MA5683T (confirmed live this session:
  `GET /component/onts_registration/by-device/{id}` used to 404 for every
  device on this system, meaning the wizard had nothing to exercise). The
  macro's `template` is raw CLI text run via switcher-core's
  `multi_console_command` module — Preview only ever renders that text
  locally (zero device interaction); Execute is the one real live write in
  this whole component, gated by an explicit confirm dialog on top of the
  backend's own `unregistered_onts`/`unregistered_onts_preview` permission
  rules.
-->
<script setup lang="ts">
import { ref, reactive, onMounted, onBeforeUnmount } from 'vue';
import { DataService } from '@/config/dataService/dataService';
import { Modal, notification } from 'ant-design-vue';
import { wsClient } from '@/services/wsClient';
import { generateExecutionId, pollMacroProgress } from '@/utility/macroProgress';

const props = defineProps<{ deviceId: number }>();

interface UnregisteredOnt {
  interface: { name: string; _technology: string };
  serial?: string;
  mac_address?: string;
  equipment_id?: string;
  type?: string;
  model?: string;
  _ident: string;
}
interface MacroParameter {
  key: string;
  label: string;
  type: 'select_from_variable' | 'select_from_predefined' | 'input_variable' | 'input_string';
  default?: string;
  required?: boolean;
  variants_list?: string; // '\n'-separated, for select_from_predefined
  source_parameter_key?: string; // dot-path into the generated variables, for select_from_variable
  regular_expr?: string;
}
interface Macro {
  id: number;
  name: string;
  template: string;
  parameters: MacroParameter[];
}

const loading = ref(true);
const rows = ref<UnregisteredOnt[]>([]);
const templateConfigured = ref(true);
const templateError = ref('');
const macro = ref<Macro | null>(null);

async function loadInfo(from: 'store' | 'cache' | 'device' = 'store') {
  loading.value = true;
  templateError.value = '';
  templateConfigured.value = true;
  macro.value = null;
  try {
    const { data } = await DataService.get(`/component/onts_registration/by-device/${props.deviceId}`);
    macro.value = data.data;
  } catch (err: any) {
    templateConfigured.value = false;
    templateError.value = err?.response?.status === 404 ? 'Registration template is not configured for this device model.' : err?.response?.data?.error?.description || err.message;
  }
  try {
    const { data } = await DataService.get(`/component/onts_registration/unregistered/${props.deviceId}`, { from });
    rows.value = data.data || [];
  } catch {
    rows.value = [];
  } finally {
    loading.value = false;
  }
}
defineExpose({ loadInfo });
onMounted(() => loadInfo());

// Real-time: this list is already auto-polled every few minutes (see
// PollerUnregisteredOntsInterface below), but a poller cycle finishing or a
// registration/dereg action against this device means it's likely stale
// right now — refetch quietly instead of waiting for the next cycle.
// NOTE the explicit 'cache' here rather than loadInfo()'s 'store' default.
// 'store' means "read the cache, error if it isn't there" — it never falls
// back to the device. A registration/dereg now clears this device's
// unregistered_onts cache entry precisely so the next read reflects reality,
// so a 'store' read at exactly that moment finds nothing and lands in the
// catch below with rows = [] — the notification arrives and the list goes
// blank/unchanged instead of updating. 'cache' re-fetches when the entry is
// missing or stale, which is what makes the refresh actually show the new
// state. Same source the ONTs tree already uses on these events.
const unsubPoller = wsClient.subscribe('event:poller:finished', (msg) => {
  if (msg.data?.device?.id === props.deviceId) loadInfo('cache');
});
const unsubAction = wsClient.subscribe('event:sys_action:added', (msg) => {
  if (msg.data?.device?.id === props.deviceId) loadInfo('cache');
});
onBeforeUnmount(() => {
  unsubPoller();
  unsubAction();
});

// A dedicated, explicit "pull now" for this one tab specifically — this
// module is polled automatically every few minutes (see
// PollerUnregisteredOntsInterface), but a customer on the phone right now
// with a freshly-plugged-in ONT shouldn't have to wait for the next
// scheduled cycle. A deliberate click here, same as the device page's own
// "Reload info" button, is the one place this component ever queries the
// device live outside the registration wizard itself.
function pullNow() {
  loadInfo('device');
}

// --- Registration wizard ---------------------------------------------

function getByPath(obj: any, path?: string) {
  if (!obj || !path) return undefined;
  return path.split('.').reduce((o, k) => (o == null ? undefined : o[k]), obj);
}

// The backend's `error` field on an execute response is an OBJECT (file/
// line/type/trace/message from the switcher-core console module), not a
// plain string — String(anObject) renders as the literal text
// "[object Object]", which is what made a real device-side failure
// (e.g. a rejected command) unreadable. Pull the real message out, and
// name which specific command failed (multi_console_command stops at the
// first one, per break_on_error) using its actual device output.
function formatDeviceError(err: any, commands?: any[]): string {
  const msg = typeof err === 'string' ? err : err?.message || JSON.stringify(err);
  const failed = (commands || []).find((c: any) => c && c.success === false);
  if (failed) {
    return `${msg} — command "${failed.command}" failed: ${failed.output}`;
  }
  return msg;
}

const wizardOpen = ref(false);
const wizardLoading = ref(false);
const wizardOnt = ref<UnregisteredOnt | null>(null);
const wizardVariables = ref<any>(null);
const wizardParams = reactive<Record<string, string>>({});
const previewText = ref('');
const previewLoading = ref(false);
const executing = ref(false);

// Shared with the bulk-register path below: computes each parameter's
// default value the same way the single-ONT wizard always has, except
// `usedByKey` lets a select_from_variable param (e.g. ont_id) exclude
// values already claimed earlier in the same bulk run — the freshly
// generated `variables` won't reflect that yet (its free-slot list is
// read from store/cache, which a batch of live registrations outruns).
function computeDefaultParams(params: MacroParameter[], variables: any, usedByKey: Record<string, Set<string>> = {}): Record<string, string> {
  const out: Record<string, string> = {};
  for (const p of params) {
    if (p.type === 'select_from_predefined') {
      out[p.key] = p.default || (p.variants_list || '').split('\n')[0] || '';
    } else if (p.type === 'select_from_variable') {
      const list: string[] = (getByPath(variables, p.source_parameter_key) || []).map(String);
      const used = usedByKey[p.key];
      out[p.key] = (used ? list.find((v) => !used.has(v)) : list[0]) ?? '';
    } else if (p.type === 'input_variable') {
      const val = getByPath(variables, p.source_parameter_key);
      out[p.key] = val != null ? String(val) : p.default || '';
    } else {
      out[p.key] = p.default || '';
    }
  }
  return out;
}

async function openRegisterModal(record: UnregisteredOnt) {
  if (!macro.value) return;
  wizardOnt.value = record;
  wizardVariables.value = null;
  previewText.value = '';
  resultCommands.value = null;
  Object.keys(wizardParams).forEach((k) => delete wizardParams[k]);
  wizardOpen.value = true;
  wizardLoading.value = true;
  try {
    // Read-only: generates the same template variables (device info, the
    // ONT's own data, and the real list of currently-free ONT-ID slots on
    // this PON port) the execute step will use — no device write.
    const { data } = await DataService.post('/component/onts_registration/variables', {
      device: { id: props.deviceId },
      ont: record._ident,
      from: 'store',
    });
    wizardVariables.value = data.data;
    const defaults = computeDefaultParams(macro.value.parameters, wizardVariables.value);
    Object.assign(wizardParams, defaults);
  } catch (err: any) {
    notification.error({ message: 'Could not prepare registration', description: err?.response?.data?.error?.description || err.message });
    wizardOpen.value = false;
  } finally {
    wizardLoading.value = false;
  }
}

// --- Bulk register: every currently-unregistered ONT on this device -----
// Uses each parameter's own default (blank description now that it's
// optional, "no" for configure_wan, etc.) — there's no way to collect
// per-ONT distinct values (a description, WAN credentials) in one bulk
// click, so this is deliberately the "quick register with defaults, edit
// details later" path, not a replacement for the one-at-a-time wizard.
// Runs sequentially with a pause between each (and one retry on failure)
// — proven necessary live: a fast back-to-back string of fresh telnet
// sessions makes the OLT trip over its own session cleanup (same fix as
// the ONTs tree's bulk-delete).
const bulkRegistering = ref(false);
const bulkProgress = reactive({ done: 0, total: 0 });
function sleep(ms: number) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

async function bulkRegisterOne(record: UnregisteredOnt, usedByKey: Record<string, Set<string>>): Promise<{ ok: true } | { ok: false; error: string }> {
  try {
    const { data: varsRes } = await DataService.post('/component/onts_registration/variables', {
      device: { id: props.deviceId },
      ont: record._ident,
      from: 'store',
    });
    const params = computeDefaultParams(macro.value!.parameters, varsRes.data, usedByKey);
    for (const p of macro.value!.parameters) {
      if (p.type === 'select_from_variable' && params[p.key]) {
        (usedByKey[p.key] ??= new Set()).add(params[p.key]);
      }
    }
    const { data: execRes } = await DataService.post('/component/onts_registration/execute', {
      device: { id: props.deviceId },
      ont: record._ident,
      params,
      preview: false,
      from: 'store',
    });
    if (execRes.data?.error) {
      return { ok: false, error: formatDeviceError(execRes.data.error, execRes.data.commands) };
    }
    return { ok: true };
  } catch (err: any) {
    return { ok: false, error: err?.response?.data?.error?.description || err.message || 'Unknown error' };
  }
}

function confirmBulkRegister() {
  const targets = rows.value;
  if (!targets.length || !macro.value) return;
  Modal.confirm({
    title: `Register all ${targets.length} unregistered ONT${targets.length === 1 ? '' : 's'} on this device?`,
    content:
      'Each one is registered with default settings — blank description, no WAN configured — one at a time. You can edit any of them individually afterward. This sends real commands to the device, not a simulation.',
    okText: `Register ${targets.length}`,
    okType: 'danger',
    onOk: async () => {
      bulkRegistering.value = true;
      bulkProgress.done = 0;
      bulkProgress.total = targets.length;
      const usedByKey: Record<string, Set<string>> = {};
      const failures: { name: string; error: string }[] = [];
      for (let i = 0; i < targets.length; i++) {
        const record = targets[i];
        let result = await bulkRegisterOne(record, usedByKey);
        if (!result.ok) {
          await sleep(2000);
          result = await bulkRegisterOne(record, usedByKey);
        }
        if (!result.ok) failures.push({ name: record.interface.name, error: 'error' in result ? result.error : 'Unknown error' });
        bulkProgress.done++;
        if (i < targets.length - 1) await sleep(1200);
      }
      bulkRegistering.value = false;
      await loadInfo('device');
      if (!failures.length) {
        notification.success({ message: `Registered all ${targets.length} ONTs` });
      } else {
        notification.error({
          message: `Registered ${targets.length - failures.length}/${targets.length} — ${failures.length} failed`,
          description:
            failures
              .slice(0, 5)
              .map((f) => `${f.name}: ${f.error}`)
              .join('\n') + (failures.length > 5 ? `\n…and ${failures.length - 5} more` : ''),
          duration: 0,
        });
      }
    },
  });
}

// Fields named wan_* (see the registration macro's "Also configure WAN
// now?" toggle) only matter once that toggle is set to "yes" — hidden
// otherwise so the form doesn't show three irrelevant fields by default.
// v-show (not v-if) so a value typed in before toggling back to "no"
// isn't lost if they change their mind again.
function shouldShowParam(p: MacroParameter): boolean {
  if (p.key.startsWith('wan_')) {
    return wizardParams.configure_wan === 'yes';
  }
  return true;
}

function variantsFor(p: MacroParameter): string[] {
  if (p.type === 'select_from_predefined') return (p.variants_list || '').split('\n').filter(Boolean);
  if (p.type === 'select_from_variable') return (getByPath(wizardVariables.value, p.source_parameter_key) || []).map(String);
  return [];
}

async function preview() {
  if (!wizardOnt.value) return;
  previewLoading.value = true;
  previewText.value = '';
  try {
    const { data } = await DataService.post('/component/onts_registration/execute', {
      device: { id: props.deviceId },
      ont: wizardOnt.value._ident,
      params: { ...wizardParams },
      preview: true,
      from: 'store',
    });
    previewText.value = data.data;
  } catch (err: any) {
    notification.error({ message: 'Could not render preview', description: err?.response?.data?.error?.description || err.message });
  } finally {
    previewLoading.value = false;
  }
}

function confirmExecute() {
  Modal.confirm({
    title: 'Run these commands on the live OLT?',
    content:
      'This sends real configuration commands to the device over its CLI — not a simulation. Preview the exact commands first if you haven’t already. This cannot be undone from here; verify the ONT the way you normally would afterwards.',
    okText: 'Yes, register it',
    okType: 'danger',
    onOk: execute,
  });
}

// Real-time step progress while the execute() call below is still in
// flight — a registration that takes a while (this template has several
// steps) used to leave this whole wizard showing nothing but a spinner
// until it finally finished. See utility/macroProgress.ts for the full
// mechanism; this component polls the ONT-registration-specific progress
// endpoint (its own permission gate, unregistered_onts).
const resultCommands = ref<{ command: string; output: string; success: boolean }[] | null>(null);
const progressDone = ref(0);
const progressTotal = ref(0);

async function execute() {
  if (!wizardOnt.value) return;
  executing.value = true;
  resultCommands.value = null;
  progressDone.value = 0;
  progressTotal.value = 0;
  const executionId = generateExecutionId();
  const progress = pollMacroProgress(
    executionId,
    (p) => {
      resultCommands.value = p.commands;
      progressDone.value = p.done;
      progressTotal.value = p.total;
    },
    1500,
    '/component/onts_registration'
  );
  try {
    const { data } = await DataService.post('/component/onts_registration/execute', {
      device: { id: props.deviceId },
      ont: wizardOnt.value._ident,
      params: { ...wizardParams },
      preview: false,
      from: 'store',
      execution_id: executionId,
    });
    resultCommands.value = data.data?.commands || null;
    if (data.data?.error) {
      notification.error({ message: 'Device reported an error', description: formatDeviceError(data.data.error, data.data.commands) });
    } else {
      notification.success({ message: 'Registration commands sent' });
      wizardOpen.value = false;
      loadInfo('device');
    }
  } catch (err: any) {
    notification.error({ message: 'Registration failed', description: err?.response?.data?.error?.description || err.message });
  } finally {
    progress.stop();
    executing.value = false;
  }
}
</script>

<template>
  <div>
    <div class="uo-toolbar">
      <sdButton size="small" type="primary" :loading="loading" @click="pullNow">
        <unicon name="redo" width="13" height="13"></unicon> Pull now
      </sdButton>
      <sdButton
        v-if="templateConfigured && rows.length > 0"
        size="small"
        type="danger"
        :disabled="bulkRegistering"
        :loading="bulkRegistering"
        @click="confirmBulkRegister"
      >
        <unicon name="plus" width="13" height="13"></unicon>
        {{ bulkRegistering ? `Registering ${bulkProgress.done}/${bulkProgress.total}…` : `Register all (${rows.length})` }}
      </sdButton>
      <span class="uo-hint">Checked automatically every few minutes — pull now to check this OLT immediately instead.</span>
    </div>
    <a-skeleton v-if="loading" active />
    <a-empty v-else-if="!rows.length" description="No unregistered ONTs found" />
    <template v-else>
      <a-alert v-if="!templateConfigured" type="warning" :message="templateError" show-icon style="margin-bottom: 12px" />
      <a-table :data-source="rows" row-key="_ident" size="small" :pagination="{ pageSize: 20 }">
        <a-table-column title="Interface" :width="140">
          <template #default="{ record }"><strong>{{ record.interface.name }}</strong></template>
        </a-table-column>
        <a-table-column title="Type" :width="90">
          <template #default="{ record }"><span class="uo-type">{{ record.interface._technology }}</span></template>
        </a-table-column>
        <a-table-column title="Identification">
          <template #default="{ record }">
            <div>
              <span v-if="record.interface._technology === 'gpon' && record.serial"><b>{{ record.serial }}</b></span>
              <span v-else-if="record.interface._technology === 'epon' && record.mac_address"><b>{{ record.mac_address }}</b></span>
              <br v-if="record.equipment_id || record.type || record.model" />
              <small v-if="record.equipment_id">{{ record.equipment_id }} </small>
              <small v-if="record.type">{{ record.type }} </small>
              <small v-if="record.model">{{ record.model }}</small>
            </div>
          </template>
        </a-table-column>
        <a-table-column title="" :width="120">
          <template #default="{ record }">
            <sdButton
              size="small"
              type="primary"
              :disabled="!templateConfigured"
              title="Register this ONU (requires a registration template configured for the model)"
              @click="openRegisterModal(record)"
            >
              <unicon name="plus" width="14" height="14"></unicon> Register
            </sdButton>
          </template>
        </a-table-column>
      </a-table>
    </template>

    <a-modal v-model:visible="wizardOpen" title="Register ONT" :footer="null" width="560">
      <a-skeleton v-if="wizardLoading" active />
      <template v-else-if="wizardOnt && macro">
        <p class="uo-hint" style="margin-bottom: 14px">
          {{ wizardOnt.serial || wizardOnt.mac_address }} on <strong>{{ wizardOnt.interface.name }}</strong>
          <template v-if="wizardVariables?.iface?.name"> (port {{ wizardVariables.iface.name }})</template>
        </p>
        <a-form layout="vertical">
          <a-form-item v-for="p in macro.parameters" v-show="shouldShowParam(p)" :key="p.key" :label="p.label">
            <a-select
              v-if="p.type === 'select_from_predefined' || p.type === 'select_from_variable'"
              v-model:value="wizardParams[p.key]"
              style="width: 100%"
              :options="variantsFor(p).map((v) => ({ value: v, label: v }))"
            />
            <a-input v-else v-model:value="wizardParams[p.key]" />
          </a-form-item>
        </a-form>

        <div style="display: flex; gap: 8px; margin: 10px 0">
          <sdButton size="small" :loading="previewLoading" @click="preview">
            <unicon name="eye" width="14" height="14"></unicon> Preview commands
          </sdButton>
          <sdButton size="small" type="danger" :loading="executing" @click="confirmExecute">
            <unicon name="check" width="14" height="14"></unicon> Execute on device
          </sdButton>
        </div>

        <pre v-if="previewText" class="uo-preview">{{ previewText }}</pre>
        <p v-else-if="resultCommands === null" class="uo-hint">Preview the exact commands before executing — nothing is sent to the device until you click Execute.</p>

        <p v-if="executing && progressTotal > 0" class="uo-hint">Running step {{ progressDone }} of {{ progressTotal }}…</p>
        <p v-else-if="executing" class="uo-hint">Connecting to device…</p>
        <pre v-if="resultCommands && resultCommands.length" class="uo-preview">{{ resultCommands.map((c) => `> ${c.command}\n${c.output}`).join('\n\n') }}</pre>
      </template>
    </a-modal>
  </div>
</template>

<style scoped>
.uo-type {
  text-transform: uppercase;
  font-size: 11px;
  font-weight: 700;
  color: #8c90a4;
}
.uo-toolbar {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 14px;
}
.uo-hint {
  font-size: 12px;
  color: #8c90a4;
}
.uo-preview {
  background: #272b41;
  color: #e6e9f1;
  padding: 12px;
  border-radius: 6px;
  font-size: 12px;
  white-space: pre-wrap;
  word-break: break-word;
}
</style>
