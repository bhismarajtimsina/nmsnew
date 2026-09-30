<script setup lang="ts">
import { ref, reactive, onMounted, computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { DataService } from '@/config/dataService/dataService';
import { notification, Modal } from 'ant-design-vue';
import { Main } from '../styled';
import JsonTreeView from '@/components/utilities/JsonTreeView.vue';

const props = defineProps<{
  variant: 'macros' | 'onts-registration';
}>();

const route = useRoute();
const router = useRouter();
const apiBase = props.variant === 'macros' ? '/component/macros/control' : '/component/onts_registration/control';
const listRouteName = props.variant === 'macros' ? 'macros' : 'onts-registration';
const listBreadcrumb = props.variant === 'macros' ? 'Macros' : 'ONTs registration';
const isMacros = props.variant === 'macros';

const editingId = computed(() => (route.params.id ? Number(route.params.id) : null));
const isEdit = computed(() => editingId.value !== null);

// Field names below match the REAL backend contract for macro parameters —
// confirmed by reading MacrosGateway::validateParameters() in BOTH
// components that consume this data (components/Macros/Controllers and
// components/OntsRegistration/Controllers — identical code in both): it
// reads $variable['key'], $variable['type'] (the 4 values below),
// $variable['source_parameter_key'], $variable['variants_list'] and
// $variable['regular_expr']. This replaces an earlier, self-invented shape
// (property/display_name/predefined/variables/input_source/input/...)
// guessed before any real macro existed to check against — it didn't match
// what either backend actually reads, so a macro edited through this page
// silently lost its real key/type/etc. `item_name`/`item_filter`/`condition`
// had no backend (or wizard) support at all and are dropped rather than
// carried forward as fields that silently do nothing.
type ParamType = 'select_from_predefined' | 'select_from_variable' | 'input_variable' | 'input_string';
interface MacroParam {
  key: string;
  label: string;
  required: boolean;
  type: ParamType;
  source_parameter_key?: string; // dot-path into generated variables — select_from_variable's option list, or input_variable's prefill
  variants_list?: string; // '\n'-separated — select_from_predefined
  regular_expr?: string; // validated against the submitted value — input_variable / input_string
  default?: string; // static default — input_string
}
const paramTypeOptions: { value: ParamType; label: string }[] = [
  { value: 'select_from_predefined', label: 'Dropdown list from predefined values' },
  { value: 'select_from_variable', label: 'Dropdown list from a variable' },
  { value: 'input_variable', label: 'Input field, prefilled from a variable' },
  { value: 'input_string', label: 'Input field' },
];
const displayForOptions = [
  { value: 'DEVICE', label: 'Device' },
  { value: 'PORT', label: 'Port' },
  { value: 'PON', label: 'PON port' },
  { value: 'ONU', label: 'ONU' },
  // Also shown on an ONU's page, but as its own separate "Add WAN" action
  // instead of the generic macro list — see MacrosCard.vue / OntDetailPanel.vue.
  { value: 'WAN', label: 'WAN (ONU) — shown as a separate "Add WAN" action' },
];
const displayOutputOptions = [
  { value: 'no', label: 'None' },
  { value: 'all', label: 'All commands' },
  { value: 'last', label: 'Last command' },
];
const paramSyntaxExample = '{{ params.key }}';

const loading = ref(true);
const saving = ref(false);
const removing = ref(false);
const activeTab = ref('common');
const vendorFilter = ref<string | undefined>(undefined);
const modelOptions = ref<{ id: number; key: string; name: string; vendor: string }[]>([]);
const roleOptions = ref<{ id: number; name: string }[]>([]);
const deviceOptions = ref<{ id: number; name: string; ip?: string }[]>([]);

const form = reactive({
  name: '',
  description: '',
  display_for: [] as string[],
  display_output: 'no',
  models: [] as string[],
  user_roles: [] as number[],
  enabled: true,
  template: '',
  parameters: [] as MacroParam[],
});

const filteredModelOptions = computed(() =>
  vendorFilter.value ? modelOptions.value.filter((m) => m.vendor === vendorFilter.value) : modelOptions.value,
);

function blankParam(): MacroParam {
  return { key: '', label: '', required: false, type: 'input_string' };
}

// --- Parameters: Form view (one box per field, click-driven) vs Raw JSON
// view (paste/type the parameter array directly) — the same form.parameters
// array backs both; switching views converts between them so nothing is
// lost either direction.
const paramsMode = ref<'form' | 'raw'>('form');
const rawParamsText = ref('[]');
const rawParamsError = ref('');
function onParamsModeChange(e: any) {
  const mode = e.target.value;
  if (mode === 'raw') {
    rawParamsText.value = JSON.stringify(form.parameters, null, 2);
    rawParamsError.value = '';
    paramsMode.value = 'raw';
  } else if (syncRawParamsToForm()) {
    paramsMode.value = 'form';
  }
  // else: parse failed — syncRawParamsToForm already set rawParamsError and
  // left paramsMode on 'raw' so the textarea (and the error) stay visible.
}
function syncRawParamsToForm(): boolean {
  try {
    const parsed = JSON.parse(rawParamsText.value);
    if (!Array.isArray(parsed)) throw new Error('must be a JSON array, e.g. [ { "key": "...", ... } ]');
    form.parameters = parsed;
    rawParamsError.value = '';
    return true;
  } catch (err: any) {
    rawParamsError.value = `Invalid JSON: ${err.message}`;
    return false;
  }
}
function addParameter() {
  form.parameters.push(blankParam());
}
function removeParameter(i: number) {
  form.parameters.splice(i, 1);
}

// Reorder via the drag handle — mirrors the real "Add New Macro" page, whose
// parameter rows each carry a `fa-align-justify` drag handle for reordering.
const dragIndex = ref<number | null>(null);
function onDragStart(i: number) {
  dragIndex.value = i;
}
function onDragOver(e: DragEvent) {
  e.preventDefault();
}
function onDrop(i: number) {
  if (dragIndex.value === null || dragIndex.value === i) return;
  const moved = form.parameters.splice(dragIndex.value, 1)[0];
  form.parameters.splice(i, 0, moved);
  dragIndex.value = null;
}

async function load() {
  loading.value = true;
  const [modelsRes, rolesRes, devicesRes] = await Promise.allSettled([
    DataService.get('/device-model'),
    DataService.get('/user-role'),
    DataService.get('/device/options'),
  ]);
  if (modelsRes.status === 'fulfilled') modelOptions.value = modelsRes.value.data.data || [];
  if (rolesRes.status === 'fulfilled') roleOptions.value = rolesRes.value.data.data || [];
  if (devicesRes.status === 'fulfilled') deviceOptions.value = devicesRes.value.data.data || [];

  if (isEdit.value) {
    try {
      const { data } = await DataService.get(`${apiBase}/${editingId.value}`);
      const row = data.data;
      Object.assign(form, {
        name: row.name,
        description: row.description || '',
        display_for: row.display_for || [],
        display_output: row.display_output || 'no',
        models: (row.models || []).map((m: any) => m.key),
        user_roles: (row.user_roles || []).map((r: any) => r.id),
        enabled: row.enabled ?? true,
        template: row.template || '',
        parameters: row.parameters || [],
      });
    } catch (err: any) {
      notification.error({
        message: 'Could not load macro',
        description: err?.response?.data?.error?.description || 'Please try again.',
      });
    }
  } else if (route.query.imported === '1') {
    // Picked up from MacrosListPage.vue's Import flow — see the comment
    // there on why this rebuild pre-fills for review instead of matching the
    // real app's immediate auto-create-on-file-select behavior.
    const raw = sessionStorage.getItem(`macro-import-${props.variant}`);
    if (raw) {
      try {
        const parsed = JSON.parse(raw);
        Object.assign(form, {
          name: parsed.name || '',
          description: parsed.description || '',
          display_for: parsed.display_for || [],
          display_output: parsed.display_output || 'no',
          models: (parsed.models || []).map((m: any) => m.key).filter(Boolean),
          user_roles: (parsed.user_roles || []).map((r: any) => r.id).filter(Boolean),
          enabled: parsed.enabled ?? true,
          template: parsed.template || '',
          parameters: parsed.parameters || [],
        });
      } catch {
        notification.error({ message: 'Invalid imported JSON' });
      } finally {
        sessionStorage.removeItem(`macro-import-${props.variant}`);
      }
    }
  }
  loading.value = false;
}
onMounted(load);

// --- Parameters/Template tab: device+interface picker driving live variables ---
const paramDeviceId = ref<number | undefined>(undefined);
const paramInterfaceOptions = ref<{ id: number; name: string }[]>([]);
const paramInterfaceId = ref<number | undefined>(undefined);
const liveVariables = ref<Record<string, any>>({});
const hasLiveVariables = computed(() => Object.keys(liveVariables.value).length > 0);
const loadingVariables = ref(false);

async function onParamDeviceChange(id: number) {
  paramDeviceId.value = id;
  paramInterfaceId.value = undefined;
  paramInterfaceOptions.value = [];
  if (!id) return;
  try {
    const { data } = await DataService.get('/device-interface', { device_id: id, limit: 999999 });
    paramInterfaceOptions.value = (data.data || []).map((i: any) => ({ id: i.id, name: i.name }));
  } catch {
    paramInterfaceOptions.value = [];
  }
}

function flattenVariables(obj: any, prefix = ''): string[] {
  const out: string[] = [];
  if (obj && typeof obj === 'object' && !Array.isArray(obj)) {
    for (const key of Object.keys(obj)) {
      const path = prefix ? `${prefix}.${key}` : key;
      const val = obj[key];
      if (val && typeof val === 'object' && !Array.isArray(val)) {
        out.push(...flattenVariables(val, path));
      } else {
        out.push(path);
      }
    }
  }
  return out;
}
const valueSourceOptions = computed(() => flattenVariables(liveVariables.value));

async function selectDeviceInterface() {
  if (!paramDeviceId.value) {
    notification.error({ message: 'Choose a device first' });
    return;
  }
  loadingVariables.value = true;
  try {
    const { data } = await DataService.post(`${apiBase}/variables`, {
      device: { id: paramDeviceId.value },
      interface: paramInterfaceId.value ? { id: paramInterfaceId.value } : undefined,
      from: 'cache',
    });
    liveVariables.value = data.data || {};
    notification.success({ message: 'Live variables loaded' });
  } catch (err: any) {
    notification.error({
      message: 'Could not load live variables',
      description: err?.response?.data?.error?.description || 'Please try again.',
    });
  } finally {
    loadingVariables.value = false;
  }
}

// --- Template tab: server-rendered live preview (read-only, safe endpoint) ---
const previewResult = ref<any>(null);
const previewError = ref<string | null>(null);
const previewing = ref(false);
// The backend renders `data` as a single string with embedded newlines (one
// command per line), not an array — confirmed by actually calling the
// endpoint. Rendered as-is (the live-result <pre> already preserves
// newlines) instead of running it through JSON.stringify, which was
// wrapping it in a pair of quote characters and made a real multi-line
// result look like one garbled line.
const previewDisplay = computed(() => {
  if (previewResult.value === null) return '';
  if (typeof previewResult.value === 'string') return previewResult.value;
  return JSON.stringify(previewResult.value, null, 2);
});
async function runPreview() {
  if (!paramDeviceId.value) {
    notification.error({ message: 'Choose a device on the Parameters tab first' });
    return;
  }
  if (paramsMode.value === 'raw' && !syncRawParamsToForm()) {
    notification.error({ message: 'Fix the raw parameters JSON before previewing' });
    activeTab.value = 'parameters';
    return;
  }
  previewing.value = true;
  previewError.value = null;
  try {
    const params: Record<string, any> = {};
    for (const p of form.parameters) {
      if (p.key) params[p.key] = p.default || '';
    }
    const { data } = await DataService.post(`${apiBase}/preview`, {
      device: { id: paramDeviceId.value },
      interface: paramInterfaceId.value ? { id: paramInterfaceId.value } : undefined,
      params,
      from: 'cache',
      template: form.template,
    });
    previewResult.value = data.data;
  } catch (err: any) {
    previewResult.value = null;
    // The real backend can 500 with a bare {"message": "Slim Application
    // Error"} (no .error.description) rather than its usual structured
    // error shape — e.g. a template referencing {{ iface.name }} with no
    // interface chosen throws an uncaught Twig error server-side. Surface
    // whatever text is actually there instead of a generic "try again" for
    // that case, since it usually points straight at the real cause.
    const msg = err?.response?.data?.error?.description || err?.response?.data?.message;
    previewError.value = msg || 'Preview failed — please try again.';
    notification.error({ message: 'Preview failed', description: msg || 'Please try again.' });
  } finally {
    previewing.value = false;
  }
}

function buildPayload() {
  const payload: Record<string, any> = {
    name: form.name,
    // Both components' Create endpoint accepts either {key} or {id}, but
    // their Update endpoint requires {id} only and throws otherwise
    // ("Parameter models must contain array of device models") — confirmed
    // live: editing an existing macro 500'd because this used to send
    // {key}. Resolving to {id} here works for both create and update.
    models: form.models
      .map((key) => modelOptions.value.find((m) => m.key === key))
      .filter((m): m is (typeof modelOptions.value)[number] => !!m)
      .map((m) => ({ id: m.id })),
    template: form.template,
    parameters: form.parameters,
  };
  if (isMacros) {
    payload.description = form.description;
    payload.display_for = form.display_for;
    payload.display_output = form.display_output;
    payload.user_roles = form.user_roles.map((id) => ({ id }));
  } else {
    payload.enabled = form.enabled;
  }
  return payload;
}

async function submit() {
  if (!form.name.trim()) {
    notification.error({ message: 'Name is required' });
    activeTab.value = 'common';
    return;
  }
  if (paramsMode.value === 'raw' && !syncRawParamsToForm()) {
    notification.error({ message: 'Fix the raw parameters JSON before saving' });
    activeTab.value = 'parameters';
    return;
  }
  saving.value = true;
  try {
    if (isEdit.value) {
      await DataService.put(`${apiBase}/${editingId.value}`, buildPayload());
      notification.success({ message: 'Macro updated successfully' });
    } else {
      await DataService.post(apiBase, buildPayload());
      notification.success({ message: 'Macro created successfully' });
    }
    router.push({ name: listRouteName });
  } catch (err: any) {
    notification.error({
      message: 'Could not save macro',
      description: err?.response?.data?.error?.description || 'Please try again.',
    });
  } finally {
    saving.value = false;
  }
}

function confirmRemove() {
  if (!editingId.value) return;
  Modal.confirm({
    title: `Delete macro "${form.name}"?`,
    okText: 'Delete',
    okType: 'danger',
    onOk: async () => {
      removing.value = true;
      try {
        await DataService.delete(`${apiBase}/${editingId.value}`);
        notification.success({ message: 'Macro deleted' });
        router.push({ name: listRouteName });
      } catch (err: any) {
        notification.error({
          message: 'Could not delete macro',
          description: err?.response?.data?.error?.description || 'Please try again.',
        });
      } finally {
        removing.value = false;
      }
    },
  });
}

const pageTitle = computed(() => (isEdit.value ? `Edit Macros - "${form.name}"` : isMacros ? 'Add New Macro' : 'Add macro'));
</script>

<template>
  <sdPageHeader
    :routes="[
      { path: '/', breadcrumbName: 'Dashboard' },
      { path: '/' + (isMacros ? 'config/macros' : 'config/onts-registration'), breadcrumbName: listBreadcrumb },
      { path: '', breadcrumbName: pageTitle },
    ]"
    :title="pageTitle"
    class="ninjadash-page-header-main"
  >
    <template #buttons>
      <div class="macro-form-actions">
        <sdButton type="light" @click="router.push({ name: listRouteName })"><unicon name="arrow-left"></unicon> Back</sdButton>
        <sdButton type="primary" :loading="saving" @click="submit"><unicon name="save"></unicon> {{ isEdit ? 'Save' : 'Create' }}</sdButton>
        <sdButton v-if="isEdit" type="danger" :loading="removing" @click="confirmRemove"><unicon name="trash-alt"></unicon> Remove</sdButton>
      </div>
    </template>
  </sdPageHeader>
  <Main>
    <a-skeleton v-if="loading" active />
    <sdCards v-else :headless="true">
      <!-- Shown once, shared by the Parameters and Template tabs (both need
           the same device+interface context) — this used to be duplicated
           as two separate <a-select> instances, one per tab-pane, both bound
           to the same paramInterfaceId/paramDeviceId refs. Ant Design Vue
           keeps inactive tab-panes mounted rather than destroying them, so
           both instances existed in the DOM at once; two live Select
           components sharing one v-model is exactly the kind of setup that
           made the Interfaces dropdown behave inconsistently (sometimes
           opening the other instance's stale panel, sometimes appearing not
           to update at all) — hoisting it to a single instance fixes that
           at the root instead of patching around it. -->
      <div v-if="activeTab !== 'common'" class="macro-device-picker-wrap">
        <p class="macro-hint">Choose a Device and an Interface to which the parameters will be applied</p>
        <a-row :gutter="16" class="macro-device-picker">
          <a-col :xs="24" :md="10">
            <label>Device</label>
            <a-select
              v-model:value="paramDeviceId"
              show-search
              placeholder="Choose device"
              style="width: 100%"
              :filter-option="(input: string, option: any) => option.label.toLowerCase().includes(input.toLowerCase())"
              :options="deviceOptions.map((d) => ({ value: d.id, label: d.name }))"
              @change="onParamDeviceChange"
            />
          </a-col>
          <a-col :xs="24" :md="10">
            <label>Interfaces</label>
            <a-select
              v-model:value="paramInterfaceId"
              show-search
              placeholder="Choose interface"
              style="width: 100%"
              :filter-option="(input: string, option: any) => option.label.toLowerCase().includes(input.toLowerCase())"
              :options="paramInterfaceOptions.map((i) => ({ value: i.id, label: i.name }))"
            />
          </a-col>
          <a-col :xs="24" :md="4" class="macro-select-btn">
            <sdButton type="primary" :loading="loadingVariables" @click="selectDeviceInterface">Select</sdButton>
            <sdButton v-if="activeTab === 'template'" type="light" :loading="previewing" @click="runPreview">Preview</sdButton>
          </a-col>
        </a-row>

        <!-- Matches the real app: clicking Select doesn't just populate the
             Value source dropdowns below invisibly — it shows the actual
             live-variables response as a browsable tree, since that's the
             only way to know what paths (device.*, iface.*, ...) are even
             available to reference. -->
        <div v-if="hasLiveVariables" class="macro-vars-tree">
          <JsonTreeView :value="liveVariables" :start-expanded="true" />
        </div>
      </div>

      <a-tabs v-model:activeKey="activeTab">
        <a-tab-pane key="common" tab="Common">
          <a-form layout="vertical">
            <a-form-item label="Name">
              <a-input v-model:value="form.name" />
            </a-form-item>
            <a-form-item v-if="isMacros" label="Description">
              <a-input v-model:value="form.description" />
            </a-form-item>
            <a-form-item v-if="!isMacros">
              <a-checkbox v-model:checked="form.enabled">Enabled</a-checkbox>
            </a-form-item>
            <a-form-item v-if="isMacros" label="Roles">
              <a-select v-model:value="form.user_roles" mode="multiple" style="width: 100%" :options="roleOptions.map((r) => ({ value: r.id, label: r.name }))" />
            </a-form-item>
            <a-form-item :label="isMacros ? 'Model vendors filter' : 'Device vendor'">
              <a-select v-model:value="vendorFilter" allow-clear style="width: 100%" :options="[...new Set(modelOptions.map((m) => m.vendor).filter(Boolean))].sort().map((v) => ({ value: v, label: v }))" />
            </a-form-item>
            <a-form-item :label="isMacros ? 'Models' : 'Device models'">
              <a-select
                v-model:value="form.models"
                mode="multiple"
                show-search
                style="width: 100%"
                :filter-option="(input: string, option: any) => option.label.toLowerCase().includes(input.toLowerCase())"
                :options="filteredModelOptions.map((m) => ({ value: m.key, label: `${m.vendor} ${m.name}` }))"
              />
            </a-form-item>
            <a-form-item v-if="isMacros" label="Display for">
              <a-select v-model:value="form.display_for" mode="multiple" style="width: 100%" :options="displayForOptions" />
            </a-form-item>
            <a-form-item v-if="isMacros" label="Display output">
              <a-select v-model:value="form.display_output" style="width: 100%" :options="displayOutputOptions" />
            </a-form-item>
          </a-form>
        </a-tab-pane>

        <a-tab-pane key="parameters" tab="Parameters">
          <div class="macro-params-header">
            <p class="macro-hint" style="margin-bottom: 0">
              Add parameters to the macro below. They can be accessed through the dot notation as follows:
              <code>{{ paramSyntaxExample }}</code>
            </p>
            <a-radio-group v-model:value="paramsMode" button-style="solid" size="small" @change="onParamsModeChange">
              <a-radio-button value="form">Form</a-radio-button>
              <a-radio-button value="raw">Raw JSON</a-radio-button>
            </a-radio-group>
          </div>

          <template v-if="paramsMode === 'form'">
            <sdButton type="primary" @click="addParameter"><unicon name="plus"></unicon> Add parameter</sdButton>

            <div
              v-for="(p, i) in form.parameters"
              :key="i"
              class="macro-param-row"
              draggable="true"
              @dragstart="onDragStart(i)"
              @dragover="onDragOver"
              @drop="onDrop(i)"
            >
              <span class="macro-param-row__handle" title="Drag to reorder"><unicon name="draggabledots"></unicon></span>
              <a-row :gutter="16">
                <a-col :xs="24" :md="8">
                  <label>Key</label>
                  <a-input v-model:value="p.key" placeholder="ont_id" />
                  <div class="macro-field-note">Must match: ^[a-z][a-z_0-9]{1,}$</div>
                </a-col>
                <a-col :xs="24" :md="8">
                  <label>Label</label>
                  <a-input v-model:value="p.label" placeholder="Shown to the operator" />
                </a-col>
                <a-col :xs="12" :md="3">
                  <label>Required</label>
                  <a-checkbox v-model:checked="p.required" />
                </a-col>
                <a-col :xs="12" :md="5">
                  <label>Parameter type</label>
                  <a-select v-model:value="p.type" style="width: 100%" :options="paramTypeOptions" />
                </a-col>
              </a-row>

              <a-row :gutter="16" class="macro-param-row__second">
                <a-col v-if="p.type === 'select_from_predefined'" :xs="24" :md="12">
                  <label>Variants list</label>
                  <a-textarea v-model:value="p.variants_list" :rows="3" placeholder="one value per line" />
                </a-col>

                <a-col v-if="p.type === 'select_from_variable'" :xs="24" :md="12">
                  <label>Source parameter key</label>
                  <a-select
                    v-model:value="p.source_parameter_key"
                    show-search
                    style="width: 100%"
                    :options="valueSourceOptions.map((v) => ({ value: v, label: v }))"
                  />
                  <div class="macro-field-note">Must point to an array of valid values (e.g. free.all)</div>
                </a-col>

                <template v-if="p.type === 'input_variable'">
                  <a-col :xs="12" :md="6">
                    <label>Source parameter key</label>
                    <a-select
                      v-model:value="p.source_parameter_key"
                      show-search
                      style="width: 100%"
                      :options="valueSourceOptions.map((v) => ({ value: v, label: v }))"
                    />
                    <div class="macro-field-note">Prefills the field — the operator can still edit it</div>
                  </a-col>
                  <a-col :xs="12" :md="6">
                    <label>Regular expression</label>
                    <a-input v-model:value="p.regular_expr" placeholder="^[0-9]{1,14}$" />
                  </a-col>
                </template>

                <template v-if="p.type === 'input_string'">
                  <a-col :xs="12" :md="6">
                    <label>Default value</label>
                    <a-input v-model:value="p.default" placeholder="Optional" />
                  </a-col>
                  <a-col :xs="12" :md="6">
                    <label>Regular expression</label>
                    <a-input v-model:value="p.regular_expr" placeholder="^[0-9]{1,14}$" />
                  </a-col>
                </template>
              </a-row>
              <sdButton type="danger" size="small" class="macro-param-row__delete" @click="removeParameter(i)">
                <unicon name="trash-alt"></unicon>
              </sdButton>
            </div>
          </template>

          <template v-else>
            <a-textarea v-model:value="rawParamsText" :rows="18" class="macro-template-editor" spellcheck="false" />
            <p v-if="rawParamsError" class="macro-hint macro-hint--error">{{ rawParamsError }}</p>
            <p v-else class="macro-hint macro-hint--muted">
              A JSON array of parameter objects — same shape as the Form view: <code>key</code>, <code>label</code>,
              <code>required</code>, <code>type</code> (one of
              <code>select_from_predefined</code>, <code>select_from_variable</code>, <code>input_variable</code>,
              <code>input_string</code>), plus <code>variants_list</code> / <code>source_parameter_key</code> /
              <code>regular_expr</code> / <code>default</code> depending on the type. Switching back to Form re-parses
              this text — fix any error shown here first.
            </p>
          </template>
        </a-tab-pane>

        <a-tab-pane key="template" tab="Template">
          <a-row :gutter="16">
            <a-col :xs="24" :md="14">
              <label class="macro-second-label">Template block</label>
              <a-textarea v-model:value="form.template" :rows="16" class="macro-template-editor" />
            </a-col>
            <a-col :xs="24" :md="10">
              <label class="macro-second-label">Live result</label>
              <pre v-if="previewError" class="macro-live-result macro-live-result--error">{{ previewError }}</pre>
              <pre v-else class="macro-live-result">{{ previewDisplay }}</pre>
            </a-col>
          </a-row>
          <p class="macro-hint macro-hint--muted">
            Template compilation provided by Twig. When changes are made, click Preview to see a server-generated list of
            commands based on the template, which will be executed on the equipment.
          </p>
        </a-tab-pane>
      </a-tabs>
    </sdCards>
  </Main>
</template>

<style scoped>
.macro-form-actions {
  display: flex;
  gap: 10px;
}
.macro-form-actions :deep(svg) {
  width: 13px;
  height: 13px;
  margin-right: 4px;
}
.macro-hint {
  font-size: 13px;
  color: #5a5f7d;
  margin-bottom: 10px;
}
.macro-hint--muted {
  color: #8c90a4;
  font-size: 12px;
  margin-top: 14px;
}
.macro-hint--error {
  color: #e5484d;
  font-size: 12px;
  margin-top: 10px;
}
.macro-params-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 14px;
}
.macro-device-picker-wrap {
  border-bottom: 1px solid #e6e9f1;
  padding-bottom: 16px;
  margin-bottom: 16px;
}
.macro-device-picker {
  margin-bottom: 0;
}
.macro-device-picker label {
  display: block;
  font-size: 12px;
  font-weight: 700;
  color: #272b41;
  margin-bottom: 6px;
}
.macro-select-btn {
  gap: 8px;
  display: flex;
  align-items: flex-end;
}
.macro-vars-tree {
  background: #f8f9fc;
  border: 1px solid #e6e9f1;
  border-radius: 6px;
  padding: 12px 16px;
  margin-bottom: 20px;
  max-height: 280px;
  overflow: auto;
}
.macro-param-row {
  position: relative;
  border: 1px solid #e6e9f1;
  border-radius: 6px;
  padding: 16px 50px 16px 40px;
  margin-bottom: 16px;
  cursor: grab;
}
.macro-param-row:active {
  cursor: grabbing;
}
.macro-param-row__handle {
  position: absolute;
  top: 16px;
  left: 12px;
  color: #8c90a4;
}
.macro-param-row__handle :deep(svg) {
  width: 14px;
  height: 14px;
}
.macro-param-row label,
.macro-second-label {
  display: block;
  font-size: 12px;
  font-weight: 700;
  color: #272b41;
  margin-bottom: 4px;
}
.macro-second-label {
  margin-top: 10px;
}
.macro-param-row__second {
  margin-top: 12px;
}
.macro-field-note {
  font-size: 11px;
  color: #8c90a4;
  margin-top: 2px;
}
.macro-param-row__delete {
  position: absolute;
  top: 12px;
  right: 12px;
}
.macro-template-editor :deep(textarea),
:deep(textarea.macro-template-editor) {
  font-family: monospace;
  font-size: 12px;
  background: #1e2433 !important;
  color: #d9dde8 !important;
}
.macro-live-result {
  background: #1e2433;
  color: #8ee0a1;
  font-family: monospace;
  font-size: 12px;
  border-radius: 4px;
  padding: 10px;
  min-height: 260px;
  max-height: 400px;
  overflow: auto;
  white-space: pre-wrap;
  word-break: break-word;
}
.macro-live-result--error {
  background: #2a1416;
  color: #ff9d9d;
}
</style>
