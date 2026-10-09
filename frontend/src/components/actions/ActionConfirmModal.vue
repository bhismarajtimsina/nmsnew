<script setup lang="ts">
/**
 * The one confirmation dialog every device action goes through (Plan 38). Opening it runs the dry run, so the user
 * sees exactly which targets the server resolved, inside their scope, before anything is sent. High-impact and bulk
 * requests also need a typed acknowledgement, and can stop at the first target that fails (on by default; changing it
 * runs the dry run again, since the choice is part of what is confirmed). After execute the dialog follows the queued
 * results until each target has finished, woken early by the `actions.finished` notice; closing it early stops
 * following, not the work.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { api } from '@/api/client';
import { useRealtimeStore } from '@/stores/realtime';
import {
  ackMatches,
  ackPhrase,
  actionError,
  actionsApi,
  describeTarget,
  followResults,
  isFinal,
  isNoticeFor,
  summarize,
  wakeableSleep,
  ACTIONS_FINISHED,
  type Executed,
  type Params,
  type Prepared,
  type Target,
} from './actions';

const props = defineProps<{ open: boolean; action: string; targets: Target[]; params?: Params }>();
const emit = defineEmits<{ (e: 'close'): void; (e: 'finished', executed: Executed): void }>();

const actions = actionsApi(api);
const stage = ref<'preparing' | 'confirm' | 'sending' | 'following' | 'done' | 'error'>('preparing');
const prepared = ref<Prepared | null>(null);
const executed = ref<Executed | null>(null);
const error = ref<string | null>(null);
const typed = ref('');
const stopOnFailure = ref(true);
const bulk = computed(() => props.targets.length > 1);
const options = () => ({ stopOnFailure: bulk.value && stopOnFailure.value });
let cancelled = false;
let wakeable = wakeableSleep();
let unsubscribe: () => void = () => {};

const phrase = computed(() => (prepared.value ? ackPhrase(prepared.value.summary) : null));
const canSend = computed(() => stage.value === 'confirm' && ackMatches(phrase.value, typed.value));
const statusColor: Record<string, string> = {
  queued: 'default',
  running: 'blue',
  succeeded: 'green',
  failed: 'red',
  refused: 'orange',
  skipped: 'default',
};

async function prepare() {
  cancelled = false;
  stage.value = 'preparing';
  prepared.value = null;
  executed.value = null;
  error.value = null;
  typed.value = '';
  try {
    prepared.value = await actions.prepare(props.action, props.targets, props.params ?? {}, options());
    stage.value = 'confirm';
  } catch (e) {
    error.value = actionError(e);
    stage.value = 'error';
  }
}

async function send() {
  if (!prepared.value || !canSend.value) return;
  stage.value = 'sending';
  try {
    executed.value = await actions.execute(
      props.action,
      prepared.value.token,
      props.targets,
      props.params ?? {},
      options(),
    );
    stage.value = 'following';
    wakeable = wakeableSleep();
    unsubscribe = useRealtimeStore().subscribe(ACTIONS_FINISHED, (message) => {
      if (isNoticeFor(message.data, executed.value?.confirmation_id)) wakeable.wake();
    });
    executed.value = await followResults(actions, executed.value, {
      sleep: wakeable.sleep,
      onUpdate: (update) => (executed.value = update),
      isCancelled: () => cancelled,
    });
    stage.value = 'done';
    emit('finished', executed.value);
  } catch (e) {
    error.value = actionError(e);
    stage.value = 'error';
  } finally {
    stopListening();
  }
}

function stopListening() {
  unsubscribe();
  unsubscribe = () => {};
}

function close() {
  cancelled = true;
  wakeable.wake();
  stopListening();
  emit('close');
}

watch(
  () => props.open,
  (open) => {
    if (open) prepare();
    else cancelled = true;
  },
  { immediate: true },
);
onBeforeUnmount(() => {
  cancelled = true;
  wakeable.wake();
  stopListening();
});
</script>

<template>
  <a-modal
    :visible="open"
    :title="prepared?.summary.title ?? 'Device action'"
    :mask-closable="false"
    width="560px"
    @cancel="close"
  >
    <a-skeleton v-if="stage === 'preparing'" active />
    <a-alert v-if="error" type="error" :message="error" show-icon style="margin-bottom: 12px" />

    <template v-if="prepared && !executed">
      <p>This will be sent to {{ prepared.summary.count }} target{{ prepared.summary.count === 1 ? '' : 's' }}:</p>
      <ul class="acm-targets">
        <li v-for="(target, i) in prepared.summary.targets" :key="i">{{ describeTarget(target) }}</li>
      </ul>
      <p v-for="(value, name) in prepared.summary.params" :key="name" class="acm-param">
        <strong>{{ name }}</strong
        >: {{ value }}
      </p>
      <a-checkbox
        v-if="bulk"
        v-model:checked="stopOnFailure"
        :disabled="stage !== 'confirm'"
        class="acm-stop"
        @change="prepare"
        >Stop at the first target that does not succeed</a-checkbox
      >
      <template v-if="phrase !== null">
        <p class="acm-warn">
          This cannot be undone from here. Type <code>{{ phrase }}</code> to confirm.
        </p>
        <a-input v-model:value="typed" autocomplete="off" :disabled="stage !== 'confirm'" />
      </template>
    </template>

    <template v-if="executed">
      <p>{{ summarize(executed.results) }}</p>
      <a-list size="small" :data-source="executed.results">
        <template #renderItem="{ item }">
          <a-list-item>
            <span>{{ describeTarget(item.target) }}</span>
            <span>
              <a-tag :color="statusColor[item.status]">{{ item.status }}</a-tag>
              <span v-if="item.error" class="acm-error">{{ item.error }}</span>
            </span>
          </a-list-item>
        </template>
      </a-list>
      <p v-if="stage === 'done' && !isFinal(executed.results)" class="acm-warn">
        Stopped following before every target finished. The worker carries on; reload the page later to see the outcome.
      </p>
    </template>

    <template #footer>
      <sdButton type="light" @click="close">{{ executed ? 'Close' : 'Cancel' }}</sdButton>
      <sdButton v-if="!executed" type="danger" :disabled="!canSend" :loading="stage === 'sending'" @click="send"
        >Send</sdButton
      >
    </template>
  </a-modal>
</template>

<style scoped>
.acm-targets {
  max-height: 200px;
  overflow-y: auto;
  padding-left: 20px;
}
.acm-stop {
  display: block;
  margin-top: 8px;
}
.acm-param {
  margin: 4px 0;
}
.acm-warn {
  color: #fa8c16;
  margin-top: 12px;
}
.acm-error {
  color: #ff4d4f;
  margin-left: 6px;
}
</style>
