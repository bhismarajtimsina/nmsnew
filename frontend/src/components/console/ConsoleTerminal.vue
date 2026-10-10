<script setup lang="ts">
/**
 * The browser terminal for the console gateway (Plan 38): xterm.js in a modal. Nothing connects until the user presses
 * Connect, which asks the API for a ticket and opens the gateway socket with it. The banner above the terminal and the
 * gateway's own first line both say the session is recorded. Closing the modal ends the session.
 */
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { Terminal } from '@xterm/xterm';
import { FitAddon } from '@xterm/addon-fit';
import '@xterm/xterm/css/xterm.css';
import { api } from '@/api/client';
import { useAuthStore } from '@/stores/auth';
import {
  AUTO_AUTH_PERMISSION,
  ConsoleConnection,
  consoleApi,
  browserSocket,
  consoleError,
  gatewayUrl,
  type ConsoleState,
} from './console';

const props = defineProps<{ open: boolean; deviceId: string; deviceName: string }>();
const emit = defineEmits<{ (e: 'close'): void }>();

const auth = useAuthStore();
const consoles = consoleApi(api);
const canAutoAuth = auth.can(AUTO_AUTH_PERMISSION);
const autoAuth = ref(false);
const state = ref<ConsoleState | 'idle'>('idle');
const message = ref<string | null>(null);
const screen = ref<HTMLDivElement | null>(null);
let terminal: Terminal | null = null;
let fit: FitAddon | null = null;
let connection: ConsoleConnection | null = null;

function onResize() {
  fit?.fit();
}

async function connect() {
  message.value = null;
  state.value = 'connecting';
  try {
    const ticket = await consoles.request(props.deviceId, autoAuth.value);
    await nextTick();
    terminal?.dispose();
    terminal = new Terminal({ cursorBlink: true, fontSize: 13, convertEol: false, scrollback: 5000 });
    fit = new FitAddon();
    terminal.loadAddon(fit);
    if (screen.value) terminal.open(screen.value);
    fit.fit();
    window.addEventListener('resize', onResize);
    connection = new ConsoleConnection(
      gatewayUrl(window.location, ticket),
      {
        onOutput: (text) => terminal?.write(text),
        onState: (next, why) => {
          state.value = next;
          if (why) message.value = why;
          if (next === 'open') terminal?.focus();
        },
      },
      (url) => browserSocket(url),
    );
    terminal.onData((keys) => connection?.send(keys));
  } catch (error) {
    state.value = 'idle';
    message.value = consoleError(error);
  }
}

function teardown() {
  connection?.close();
  connection = null;
  terminal?.dispose();
  terminal = null;
  fit = null;
  window.removeEventListener('resize', onResize);
  state.value = 'idle';
}

function close() {
  teardown();
  message.value = null;
  emit('close');
}

watch(
  () => props.open,
  (open) => {
    if (!open) teardown();
  },
);
onBeforeUnmount(teardown);
</script>

<template>
  <a-modal
    :visible="open"
    :title="`Console: ${deviceName}`"
    :footer="null"
    :mask-closable="false"
    width="900px"
    @cancel="close"
  >
    <a-alert
      type="info"
      show-icon
      class="ct-banner"
      message="This session is recorded with your name. Input typed at password prompts is hidden from the record."
    />
    <div v-if="state === 'idle'" class="ct-start">
      <a-checkbox v-if="canAutoAuth" v-model:checked="autoAuth"
        >Log in with the login stored on the device's access profile</a-checkbox
      >
      <sdButton type="primary" @click="connect">Connect</sdButton>
    </div>
    <a-alert
      v-if="message"
      :type="state === 'idle' ? 'error' : 'warning'"
      :message="message"
      show-icon
      class="ct-message"
    />
    <div v-show="state !== 'idle'" ref="screen" class="ct-screen"></div>
    <div v-if="state === 'closed'" class="ct-start">
      <sdButton type="default" @click="close">Close</sdButton>
    </div>
  </a-modal>
</template>

<style scoped>
.ct-banner,
.ct-message {
  margin-bottom: 12px;
}
.ct-start {
  display: flex;
  gap: 12px;
  align-items: center;
  margin-bottom: 12px;
}
.ct-screen {
  height: 460px;
  background: #000;
  padding: 4px;
}
</style>
