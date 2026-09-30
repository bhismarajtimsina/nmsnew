<!--
  The shared "run a macro" wizard — extracted out of MacrosCard.vue so the
  same preview/execute flow can be triggered from a dedicated, purpose-built
  button too (e.g. a device-page action), not just from the generic macro
  list. See MacrosCard.vue for the full rationale; this file is just the
  modal + its logic.
-->
<script setup lang="ts">
import { ref, reactive } from 'vue';
import { DataService } from '@/config/dataService/dataService';
import { Modal, notification } from 'ant-design-vue';
import { generateExecutionId, pollMacroProgress } from '@/utility/macroProgress';

const props = defineProps<{
  deviceId: number;
  interfaceBindKey?: string;
}>();

interface MacroParameter {
  key: string;
  label: string;
  type: 'select_from_variable' | 'select_from_predefined' | 'input_variable' | 'input_string';
  default?: string;
  required?: boolean;
  variants_list?: string; // '\n'-separated, for select_from_predefined
  source_parameter_key?: string; // dot-path into generated variables — select_from_variable's option list, or input_variable's prefill
  regular_expr?: string;
}
interface MacroDetail {
  id: number;
  name: string;
  description?: string;
  template: string;
  display_output: 'no' | 'all' | 'last';
  parameters: MacroParameter[];
}

function getByPath(obj: any, path?: string) {
  if (!obj || !path) return undefined;
  return path.split('.').reduce((o, k) => (o == null ? undefined : o[k]), obj);
}

// The backend's `error` field on an execute response is an OBJECT (file/
// line/type/trace/message from the switcher-core console module), not a
// plain string — String(anObject) renders as the literal text
// "[object Object]", which is what made a real device-side failure
// unreadable. Pull the real message out, and name which specific command
// failed (multi_console_command stops at the first one, per
// break_on_error) using its actual device output.
function formatDeviceError(err: any, commands?: any[]): string {
  const msg = typeof err === 'string' ? err : err?.message || JSON.stringify(err);
  const failed = (commands || []).find((c: any) => c && c.success === false);
  if (failed) {
    return `${msg} — command "${failed.command}" failed: ${failed.output}`;
  }
  return msg;
}
function ifaceArg() {
  return props.interfaceBindKey ? { bind_key: props.interfaceBindKey } : undefined;
}

const wizardOpen = ref(false);
const wizardLoading = ref(false);
const macro = ref<MacroDetail | null>(null);
const variables = ref<any>(null);
const wizardParams = reactive<Record<string, string>>({});
const previewText = ref('');
const previewLoading = ref(false);
const executing = ref(false);
// multi_console_command's own response shape (confirmed against the vendor
// switcher-core source) is an array of {command, output, success} objects,
// not plain strings — rendered as "> command\noutput" lines below.
interface CommandResult {
  command: string;
  output: string;
  success: boolean;
}
const resultCommands = ref<CommandResult[] | null>(null);
const resultError = ref<string | null>(null);
const onExecuted = ref<(() => void) | null>(null);

async function open(macroId: number, onExecutedCb?: () => void) {
  macro.value = null;
  variables.value = null;
  previewText.value = '';
  resultCommands.value = null;
  resultError.value = null;
  onExecuted.value = onExecutedCb || null;
  Object.keys(wizardParams).forEach((k) => delete wizardParams[k]);
  wizardOpen.value = true;
  wizardLoading.value = true;
  try {
    const [macroRes, varsRes] = await Promise.all([
      DataService.get(`/component/macros/macro/${macroId}`),
      // Read-only: generates the same template variables (device info,
      // interface data if applicable) the execute step will use — no
      // device write. `from: store` never falls back to a live query.
      DataService.post('/component/macros/variables', {
        device: { id: props.deviceId },
        macros: { id: macroId },
        interface: ifaceArg(),
        from: 'store',
      }),
    ]);
    macro.value = macroRes.data.data;
    variables.value = varsRes.data.data;
    for (const p of macro.value!.parameters) {
      if (p.type === 'select_from_predefined') {
        wizardParams[p.key] = p.default || (p.variants_list || '').split('\n')[0] || '';
      } else if (p.type === 'select_from_variable') {
        const list = getByPath(variables.value, p.source_parameter_key) || [];
        wizardParams[p.key] = list.length ? String(list[0]) : '';
      } else if (p.type === 'input_variable') {
        const val = getByPath(variables.value, p.source_parameter_key);
        wizardParams[p.key] = val != null ? String(val) : p.default || '';
      } else {
        wizardParams[p.key] = p.default || '';
      }
    }
  } catch (err: any) {
    notification.error({ message: 'Could not prepare macro', description: err?.response?.data?.error?.description || err.message });
    wizardOpen.value = false;
  } finally {
    wizardLoading.value = false;
  }
}
defineExpose({ open });

function variantsFor(p: MacroParameter): string[] {
  if (p.type === 'select_from_predefined') return (p.variants_list || '').split('\n').filter(Boolean);
  if (p.type === 'select_from_variable') return (getByPath(variables.value, p.source_parameter_key) || []).map(String);
  return [];
}

async function preview() {
  if (!macro.value) return;
  previewLoading.value = true;
  previewText.value = '';
  try {
    const { data } = await DataService.post('/component/macros/execute', {
      device: { id: props.deviceId },
      macros: { id: macro.value.id },
      interface: ifaceArg(),
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
    title: 'Run these commands on the live device?',
    content:
      'This sends real commands to the device over its CLI — not a simulation. Preview the exact commands first if you haven’t already. This cannot be undone from here.',
    okText: 'Yes, run it',
    okType: 'danger',
    onOk: execute,
  });
}

// Real-time step progress while execute() is still in flight — a macro
// that legitimately takes over a minute (confirmed live) used to leave
// this modal showing nothing but a spinner the whole time. See
// utility/macroProgress.ts for the full mechanism.
const progressDone = ref(0);
const progressTotal = ref(0);

async function execute() {
  if (!macro.value) return;
  executing.value = true;
  resultCommands.value = null;
  resultError.value = null;
  progressDone.value = 0;
  progressTotal.value = 0;
  const executionId = generateExecutionId();
  const progress = pollMacroProgress(executionId, (p) => {
    resultCommands.value = p.commands;
    progressDone.value = p.done;
    progressTotal.value = p.total;
  });
  try {
    const { data } = await DataService.post('/component/macros/execute', {
      device: { id: props.deviceId },
      macros: { id: macro.value.id },
      interface: ifaceArg(),
      params: { ...wizardParams },
      preview: false,
      from: 'store',
      execution_id: executionId,
    });
    resultCommands.value = data.data?.commands || [];
    resultError.value = data.data?.error ? formatDeviceError(data.data.error, resultCommands.value) : null;
    if (resultError.value) {
      notification.error({ message: 'Device reported an error', description: resultError.value });
    } else {
      notification.success({ message: 'Macro executed' });
      onExecuted.value?.();
    }
  } catch (err: any) {
    notification.error({ message: 'Execution failed', description: err?.response?.data?.error?.description || err.message });
  } finally {
    progress.stop();
    executing.value = false;
  }
}
</script>

<template>
  <a-modal v-model:visible="wizardOpen" :title="macro ? `Run macro — ${macro.name}` : 'Run macro'" :footer="null" width="560">
    <a-skeleton v-if="wizardLoading" active />
    <template v-else-if="macro">
      <a-form v-if="macro.parameters.length" layout="vertical">
        <a-form-item v-for="p in macro.parameters" :key="p.key" :label="p.label">
          <a-select
            v-if="p.type === 'select_from_predefined' || p.type === 'select_from_variable'"
            v-model:value="wizardParams[p.key]"
            style="width: 100%"
            :options="variantsFor(p).map((v) => ({ value: v, label: v }))"
          />
          <a-input v-else v-model:value="wizardParams[p.key]" />
        </a-form-item>
      </a-form>
      <p v-else class="rmm-hint">This macro takes no parameters.</p>

      <div style="display: flex; gap: 8px; margin: 10px 0">
        <sdButton size="small" :loading="previewLoading" @click="preview">
          <unicon name="eye" width="14" height="14"></unicon> Preview commands
        </sdButton>
        <sdButton size="small" type="danger" :loading="executing" @click="confirmExecute">
          <unicon name="check" width="14" height="14"></unicon> Execute on device
        </sdButton>
      </div>

      <pre v-if="previewText" class="rmm-preview">{{ previewText }}</pre>
      <p v-else-if="resultCommands === null" class="rmm-hint">Preview the exact commands before executing — nothing is sent to the device until you click Execute.</p>

      <p v-if="executing && progressTotal > 0" class="rmm-hint">Running step {{ progressDone }} of {{ progressTotal }}…</p>
      <template v-if="resultCommands !== null">
        <p v-if="resultError" class="rmm-hint rmm-hint--error">{{ resultError }}</p>
        <pre v-else-if="resultCommands.length" class="rmm-preview">{{ resultCommands.map((c) => `> ${c.command}\n${c.output}`).join('\n\n') }}</pre>
        <p v-else class="rmm-hint">Executed — this macro's display output is set to "None", so no command output is shown.</p>
      </template>
    </template>
  </a-modal>
</template>

<style scoped>
.rmm-hint {
  font-size: 12px;
  color: #8c90a4;
}
.rmm-hint--error {
  color: #e5484d;
}
.rmm-preview {
  background: #272b41;
  color: #e6e9f1;
  padding: 12px;
  border-radius: 6px;
  font-size: 12px;
  white-space: pre-wrap;
  word-break: break-word;
}
</style>
