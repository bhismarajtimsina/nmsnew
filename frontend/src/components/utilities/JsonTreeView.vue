<!--
  Small self-contained collapsible JSON viewer — used by the Macros
  "Parameters" tab to show the raw live-variables result after clicking
  Select (POST /component/macros/control/variables), matching the real
  app's own expandable "params / user / device / iface / ..." tree instead
  of leaving that response invisible behind a toast notification.

  No JSON-tree library was already in this project's dependencies, so this
  is a plain recursive component rather than pulling in a new package.
-->
<script setup lang="ts">
import { ref, computed } from 'vue';

// Explicit name so the implicit-self-reference recursion below (<JsonTreeView>
// used inside its own template) resolves to the compiled component instance
// instead of a raw dynamic re-import of this module (which was otherwise
// re-running the file's `defineProps()`/`withDefaults()` compiler macros at
// runtime and throwing "only usable inside <script setup>" warnings).
defineOptions({ name: 'JsonTreeView' });

const props = withDefaults(
  defineProps<{
    value: any;
    keyName?: string | null;
    depth?: number;
    last?: boolean;
    startExpanded?: boolean;
  }>(),
  { keyName: null, depth: 0, last: true, startExpanded: false },
);

const isContainer = computed(() => props.value !== null && typeof props.value === 'object');
const isArray = computed(() => Array.isArray(props.value));
const entries = computed(() => {
  if (!isContainer.value) return [];
  return isArray.value ? props.value.map((v: any, i: number) => [i, v]) : Object.entries(props.value);
});
const expanded = ref(props.startExpanded);

function displayPrimitive(v: any) {
  if (typeof v === 'string') return `"${v}"`;
  return String(v);
}
</script>

<template>
  <div class="json-node" :style="{ paddingLeft: depth ? '16px' : '0' }">
    <template v-if="isContainer && entries.length">
      <div class="json-node__row json-node__row--toggle" @click="expanded = !expanded">
        <span class="json-node__toggle">{{ expanded ? '−' : '+' }}</span>
        <span v-if="keyName !== null" class="json-node__key">"{{ keyName }}"</span>
        <span class="json-node__punct">{{ keyName !== null ? ': ' : '' }}{{ isArray ? '[' : '{' }}</span>
        <span v-if="!expanded" class="json-node__summary">/* {{ entries.length }} item{{ entries.length === 1 ? '' : 's' }} */ {{ isArray ? ']' : '}' }}{{ last ? '' : ',' }}</span>
      </div>
      <template v-if="expanded">
        <JsonTreeView
          v-for="([k, v], i) in entries"
          :key="k"
          :value="v"
          :key-name="isArray ? null : String(k)"
          :depth="(depth || 0) + 1"
          :last="i === entries.length - 1"
        />
        <div class="json-node__row" :style="{ paddingLeft: depth ? '16px' : '0' }">
          <span class="json-node__punct">{{ isArray ? ']' : '}' }}{{ last ? '' : ',' }}</span>
        </div>
      </template>
    </template>
    <template v-else-if="isContainer">
      <div class="json-node__row">
        <span v-if="keyName !== null" class="json-node__key">"{{ keyName }}"</span>
        <span class="json-node__punct">{{ keyName !== null ? ': ' : '' }}{{ isArray ? '[]' : '{}' }}{{ last ? '' : ',' }}</span>
      </div>
    </template>
    <template v-else>
      <div class="json-node__row">
        <span v-if="keyName !== null" class="json-node__key">"{{ keyName }}"</span>
        <span class="json-node__value">{{ keyName !== null ? ': ' : '' }}{{ displayPrimitive(value) }}{{ last ? '' : ',' }}</span>
      </div>
    </template>
  </div>
</template>

<style scoped>
.json-node {
  font-family: monospace;
  font-size: 12px;
  line-height: 1.6;
}
.json-node__row {
  white-space: pre;
}
.json-node__row--toggle {
  cursor: pointer;
}
.json-node__row--toggle:hover {
  background: #f4f5f9;
}
.json-node__toggle {
  display: inline-block;
  width: 14px;
  color: #8c90a4;
  font-weight: 700;
}
.json-node__key {
  color: #a6392c;
}
.json-node__punct {
  color: #5a5f7d;
}
.json-node__summary {
  color: #8c90a4;
  font-style: italic;
}
.json-node__value {
  color: #1868db;
}
</style>
