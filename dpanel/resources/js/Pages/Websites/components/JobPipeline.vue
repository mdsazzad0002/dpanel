<script setup>
import { computed } from 'vue';

// Progress card for a clone/share-link job, styled like the upload form's
// "Tracked import pipeline": one bar per step, filled as the job moves on.
const props = defineProps({
    title: { type: String, required: true },
    jobId: { type: String, default: '' },
    // [{ key, label }] in the order the job runs them.
    steps: { type: Array, required: true },
    stage: { type: String, default: null },
    // The step the job was on when it failed, so that bar turns red.
    failedAt: { type: String, default: null },
    message: { type: String, default: '' },
});

const rows = computed(() => {
    const failed = props.stage === 'failed';
    const at = props.steps.findIndex((step) => step.key === (failed ? props.failedAt : props.stage));
    const current = failed && at === -1 ? 0 : at;

    return props.steps.map((step, index) => {
        if (props.stage === 'ready' || index < current) return { ...step, status: 'done', progress: 100 };
        if (index === current) return failed ? { ...step, status: 'failed', progress: 100 } : { ...step, status: 'running', progress: 60 };
        return { ...step, status: 'waiting', progress: 0 };
    });
});
</script>

<template>
    <div class="space-y-3 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-800/50">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h4 class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ title }}</h4>
            <code v-if="jobId" class="rounded bg-slate-200 px-2 py-1 text-xs text-slate-600 dark:bg-slate-700 dark:text-slate-300">{{ jobId }}</code>
        </div>
        <div v-for="row in rows" :key="row.key">
            <div class="mb-1 flex justify-between text-xs">
                <span class="font-semibold text-slate-600 dark:text-slate-300">{{ row.label }}</span>
                <span class="text-slate-500">{{ row.status }} · {{ row.progress }}%</span>
            </div>
            <div class="h-2 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
                <div class="h-full rounded-full transition-all duration-500"
                    :class="[row.status === 'done' ? 'bg-emerald-500' : row.status === 'failed' ? 'bg-red-500' : 'bg-indigo-500', row.status === 'running' ? 'animate-pulse' : '']"
                    :style="{ width: `${row.progress}%` }"></div>
            </div>
        </div>
        <p v-if="stage === 'ready'" class="text-xs text-emerald-600"><i class="bi bi-check-circle-fill mr-1"></i>Done</p>
        <p v-if="stage === 'failed'" class="text-xs text-red-600"><i class="bi bi-x-circle-fill mr-1"></i>{{ message || 'Failed.' }}</p>
    </div>
</template>
