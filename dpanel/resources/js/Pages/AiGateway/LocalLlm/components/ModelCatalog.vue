<script setup>
import { fmtBytes } from './format';

const props = defineProps({
    catalog: { type: Array, default: () => [] },
    installed: { type: Array, default: () => [] },
    pulls: { type: Object, default: () => ({}) },
    totalMemory: { type: Number, default: 0 },
    busy: { type: Boolean, default: false },
});
defineEmits(['pull']);

const stateOf = (item) => {
    if (props.installed.includes(item.id)) return 'installed';
    if (props.pulls[item.id]?.state === 'downloading') return 'downloading';
    return null;
};
// Weights plus the context's working memory must fit next to everything else.
const tooBig = (item) => props.totalMemory > 0 && item.size_bytes * 1.3 > props.totalMemory * 0.8;
</script>

<template>
    <div class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
        <h2 class="text-sm font-semibold">Recommended models</h2>
        <p class="mt-0.5 text-xs text-slate-500">Quantized (Q4) chat models that run on a CPU. Smaller is faster; larger answers better.</p>
        <div class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div v-for="item in catalog" :key="item.id"
                class="flex flex-col rounded-md border border-slate-200 p-3 dark:border-slate-700">
                <div class="flex items-start justify-between gap-2">
                    <div class="text-sm font-medium">{{ item.name }}</div>
                    <span class="shrink-0 text-xs text-slate-400">{{ fmtBytes(item.size_bytes) }}</span>
                </div>
                <p class="mt-1 flex-1 text-xs text-slate-500">{{ item.note }}</p>
                <p v-if="tooBig(item)" class="mt-1 text-xs text-amber-600">Likely too large for this server's RAM.</p>
                <div class="mt-2">
                    <span v-if="stateOf(item) === 'installed'" class="text-xs text-emerald-600"><i class="bi bi-check2"></i> Installed</span>
                    <span v-else-if="stateOf(item) === 'downloading'" class="text-xs text-slate-500">Downloading…</span>
                    <button v-else @click="$emit('pull', { url: item.url, id: item.id })" :disabled="busy"
                        class="rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 disabled:opacity-50 dark:border-slate-600 dark:hover:bg-slate-700">
                        <i class="bi bi-download"></i> Download
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
