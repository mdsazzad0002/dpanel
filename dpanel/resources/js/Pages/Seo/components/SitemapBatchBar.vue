<script setup>
import { computed } from 'vue';

// Counts and a run/stop button for a list of sitemaps checked one by one.
const props = defineProps({
    // { total, pass, warn, fail, error, pending, loading, urls }
    stats: { type: Object, required: true },
    running: { type: Boolean, default: false },
    label: { type: String, default: 'sitemaps' },
    // Another list is being checked; only one run goes at a time.
    busy: { type: Boolean, default: false },
});
const emit = defineEmits(['run', 'stop']);

const done = computed(() => props.stats.total - props.stats.pending - props.stats.loading);
// Failed requests are retried along with the ones never checked.
const runnable = computed(() => props.stats.pending + props.stats.error);
const runLabel = computed(() => {
    if (!runnable.value) return 'All checked';
    if (runnable.value === props.stats.total) return 'Run all checks';
    return `Check remaining ${runnable.value}`;
});
const percent = computed(() => (props.stats.total ? Math.round((done.value / props.stats.total) * 100) : 0));
</script>

<template>
    <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 dark:border-slate-700 dark:bg-slate-800/60">
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs">
            <span class="font-medium text-slate-700 dark:text-slate-200">{{ done }} / {{ stats.total }} {{ label }} checked</span>
            <span class="text-emerald-600 dark:text-emerald-400" title="Passed"><i class="bi bi-check-circle-fill"></i> {{ stats.pass }}</span>
            <span class="text-amber-600 dark:text-amber-400" title="With warnings"><i class="bi bi-exclamation-triangle-fill"></i> {{ stats.warn }}</span>
            <span class="text-red-600 dark:text-red-400" title="With errors"><i class="bi bi-x-circle-fill"></i> {{ stats.fail + stats.error }}</span>
            <span v-if="stats.urls" class="text-slate-500 dark:text-slate-400"><i class="bi bi-link-45deg"></i> {{ stats.urls.toLocaleString() }} URLs</span>
            <button
                v-if="running"
                type="button"
                @click="emit('stop')"
                class="ml-auto rounded-md border border-slate-300 bg-white px-2.5 py-1 font-medium hover:bg-slate-50 dark:border-slate-600 dark:bg-slate-900 dark:hover:bg-slate-800"
            >
                <i class="bi bi-stop-fill mr-1"></i>Stop
            </button>
            <button
                v-else
                type="button"
                :disabled="!runnable || busy"
                :title="busy ? 'Another check is running' : ''"
                @click="emit('run')"
                class="ml-auto rounded-md bg-blue-600 px-2.5 py-1 font-medium text-white hover:bg-blue-700 disabled:opacity-50"
            >
                <i class="bi bi-play-fill mr-0.5"></i>{{ runLabel }}
            </button>
        </div>
        <div v-if="running || (done > 0 && done < stats.total)" class="mt-2 h-1 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
            <div class="h-full rounded-full bg-blue-600 transition-all" :style="{ width: `${percent}%` }"></div>
        </div>
    </div>
</template>
