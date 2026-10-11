<script setup>
import { computed } from 'vue';
import { highlight, toShell } from './mongoJson';

// A document (or any Extended JSON value) as highlighted shell syntax.
const props = defineProps({
    value: { type: null, default: null },
    maxHeight: { type: String, default: '' },
});

const html = computed(() => highlight(toShell(props.value)));
</script>

<template>
    <pre class="mongo-json overflow-auto whitespace-pre font-mono text-xs leading-5 text-slate-700 dark:text-slate-200" :style="maxHeight ? { maxHeight } : null" v-html="html"></pre>
</template>

<style scoped>
.mongo-json :deep(.mj-key) { color: #0f766e; }
.mongo-json :deep(.mj-str) { color: #b45309; }
.mongo-json :deep(.mj-num) { color: #1d4ed8; }
.mongo-json :deep(.mj-lit) { color: #7c3aed; }
.mongo-json :deep(.mj-fn) { color: #be185d; }
:global(.dark) .mongo-json :deep(.mj-key) { color: #5eead4; }
:global(.dark) .mongo-json :deep(.mj-str) { color: #fcd34d; }
:global(.dark) .mongo-json :deep(.mj-num) { color: #93c5fd; }
:global(.dark) .mongo-json :deep(.mj-lit) { color: #c4b5fd; }
:global(.dark) .mongo-json :deep(.mj-fn) { color: #f9a8d4; }
</style>
