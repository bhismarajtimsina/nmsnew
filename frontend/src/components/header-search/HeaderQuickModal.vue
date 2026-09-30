<script setup lang="ts">
import { watch } from 'vue';

const props = defineProps<{
  modelValue: boolean;
  ariaLabel: string;
}>();
const emit = defineEmits<{ (e: 'update:modelValue', value: boolean): void; (e: 'opened'): void }>();

function close() {
  emit('update:modelValue', false);
}

watch(
  () => props.modelValue,
  (open) => {
    document.body.style.overflow = open ? 'hidden' : '';
    if (open) emit('opened');
  },
);
</script>

<template>
  <Teleport to="body">
    <Transition name="hqm-fade">
      <div v-if="modelValue" class="hqm-backdrop" @click.self="close" @keydown.esc="close">
        <section class="hqm-panel" role="dialog" aria-modal="true" :aria-label="ariaLabel">
          <div class="hqm-panel__header">
            <slot name="header" :close="close" />
          </div>
          <div class="hqm-panel__body">
            <slot :close="close" />
          </div>
        </section>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.hqm-backdrop {
  position: fixed;
  inset: 0;
  z-index: 1050;
  background: rgba(15, 23, 42, 0.55);
  backdrop-filter: blur(2px);
  display: flex;
  justify-content: center;
  padding: 10vh 16px 0;
}
.hqm-panel {
  width: 100%;
  max-width: 720px;
  max-height: 72vh;
  background: #ffffff;
  border-radius: 10px;
  box-shadow: 0 24px 60px rgba(15, 23, 42, 0.35);
  display: flex;
  flex-direction: column;
  overflow: hidden;
}
.hqm-panel__header {
  flex-shrink: 0;
}
.hqm-panel__body {
  overflow-y: auto;
  flex: 1;
}
.hqm-fade-enter-active,
.hqm-fade-leave-active {
  transition: opacity 0.15s ease;
}
.hqm-fade-enter-from,
.hqm-fade-leave-to {
  opacity: 0;
}
@media (max-width: 575px) {
  .hqm-backdrop {
    padding: 0;
  }
  .hqm-panel {
    max-width: 100%;
    max-height: 100vh;
    height: 100vh;
    border-radius: 0;
  }
}
</style>
