<script setup lang="ts">
/**
 * A recorded console session, read-only (Plan 38): the device's output replayed in a terminal that accepts no input,
 * and what the user typed as a list, with `[input hidden]` where a password was entered (the secret itself was never
 * stored). Needs `console.logs.view`; the API scopes it by the session's device.
 */
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { Terminal } from '@xterm/xterm';
import { FitAddon } from '@xterm/addon-fit';
import '@xterm/xterm/css/xterm.css';
import { api } from '@/api/client';
import {
  consoleApi,
  consoleError,
  loadTranscript,
  sessionLength,
  splitTranscript,
  type ConsoleSession,
} from './console';

const props = defineProps<{ open: boolean; sessionId: string }>();
const emit = defineEmits<{ (e: 'close'): void }>();

const consoles = consoleApi(api);
const loading = ref(true);
const error = ref<string | null>(null);
const session = ref<ConsoleSession | null>(null);
const typed = ref<string[]>([]);
const complete = ref(true);
const screen = ref<HTMLDivElement | null>(null);
let terminal: Terminal | null = null;

async function load() {
  loading.value = true;
  error.value = null;
  try {
    const transcript = await loadTranscript(consoles, props.sessionId);
    const { output, input } = splitTranscript(transcript.chunks);
    session.value = transcript.session;
    typed.value = input;
    complete.value = transcript.complete;
    loading.value = false;
    await nextTick();
    terminal?.dispose();
    terminal = new Terminal({ disableStdin: true, cursorBlink: false, fontSize: 13, scrollback: 20000 });
    const fit = new FitAddon();
    terminal.loadAddon(fit);
    if (screen.value) terminal.open(screen.value);
    fit.fit();
    terminal.write(output);
  } catch (e) {
    error.value = consoleError(e);
    loading.value = false;
  }
}

function close() {
  terminal?.dispose();
  terminal = null;
  emit('close');
}

watch(
  () => [props.open, props.sessionId] as const,
  ([open]) => {
    if (open) load();
  },
  { immediate: true },
);
onBeforeUnmount(() => terminal?.dispose());
</script>

<template>
  <a-modal :visible="open" title="Console transcript" :footer="null" width="960px" @cancel="close">
    <a-skeleton v-if="loading" active />
    <a-alert v-else-if="error" type="error" :message="error" show-icon />
    <template v-else-if="session">
      <a-descriptions size="small" :column="3" class="tv-meta">
        <a-descriptions-item label="Device">{{ session.device_name }}</a-descriptions-item>
        <a-descriptions-item label="User">{{ session.username ?? '(deleted user)' }}</a-descriptions-item>
        <a-descriptions-item label="Length">{{ sessionLength(session) }}</a-descriptions-item>
        <a-descriptions-item label="Started">{{ new Date(session.created_at).toLocaleString() }}</a-descriptions-item>
        <a-descriptions-item label="Ended">{{ session.close_reason ?? session.status }}</a-descriptions-item>
        <a-descriptions-item label="Login">{{
          session.auto_auth ? 'stored login' : 'typed by the user'
        }}</a-descriptions-item>
      </a-descriptions>
      <a-alert
        v-if="!complete"
        type="warning"
        show-icon
        class="tv-meta"
        message="This transcript is long; only its beginning is shown."
      />
      <a-tabs>
        <a-tab-pane key="output" tab="Device output"><div ref="screen" class="tv-screen"></div></a-tab-pane>
        <a-tab-pane key="input" :tab="`Typed input (${typed.length})`">
          <a-empty v-if="!typed.length" description="Nothing was typed." />
          <pre v-else class="tv-input">{{ typed.join('\n') }}</pre>
        </a-tab-pane>
      </a-tabs>
    </template>
  </a-modal>
</template>

<style scoped>
.tv-meta {
  margin-bottom: 12px;
}
.tv-screen {
  height: 460px;
  background: #000;
  padding: 4px;
}
.tv-input {
  max-height: 460px;
  overflow: auto;
  background: #f6f6f6;
  padding: 8px;
}
</style>
