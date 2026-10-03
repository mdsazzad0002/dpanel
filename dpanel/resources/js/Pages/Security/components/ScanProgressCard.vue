<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import axios from 'axios';

const props = defineProps({
    scan: { type: Object, required: true },
    panelRoute: { type: Function, required: true },
});

const emit = defineEmits(['finished']);

const scanLabels = { quick: 'Quick', full: 'Full', malware: 'Malware', integrity: 'Integrity', permissions: 'Permissions', configuration: 'Server configuration' };

const current = ref(props.scan);
const progress = ref(null);
// Counts up locally between polls so the clock does not jump in 2s steps.
const elapsed = ref(0);
let pollTimer = null;
let clockTimer = null;

const poll = async () => {
    try {
        const { data } = await axios.get(props.panelRoute('security.center.scans.show', { scan: props.scan.id }));
        current.value = data.scan;
        progress.value = data.progress;
        if (data.progress) elapsed.value = data.progress.elapsed;
        if (!['queued', 'running'].includes(data.scan.status)) {
            stop();
            emit('finished', data.scan);
        }
    } catch {
        // A missed poll is retried on the next tick.
    }
};

const stop = () => {
    clearInterval(pollTimer);
    clearInterval(clockTimer);
};

onMounted(() => {
    poll();
    pollTimer = setInterval(poll, 2000);
    clockTimer = setInterval(() => {
        if (current.value.status === 'running') elapsed.value += 1;
    }, 1000);
});
onBeforeUnmount(stop);

const percent = computed(() => progress.value?.percent);
const determinate = computed(() => percent.value !== null && percent.value !== undefined);

const formatDuration = (seconds) => {
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return m > 0 ? `${m}m ${String(s).padStart(2, '0')}s` : `${s}s`;
};
const number = (value) => new Intl.NumberFormat().format(value);

const severityStyles = {
    critical: 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300',
    high: 'bg-orange-100 text-orange-700 dark:bg-orange-950 dark:text-orange-300',
    medium: 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
    low: 'bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300',
    info: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
};
const foundCounts = computed(() => ['critical', 'high', 'medium', 'low', 'info']
    .map((severity) => ({ severity, count: progress.value?.found?.[severity] || 0 }))
    .filter((item) => item.count > 0));
const foundTotal = computed(() => foundCounts.value.reduce((sum, item) => sum + item.count, 0));
</script>

<template>
    <div class="rounded-lg border border-blue-200 bg-blue-50/60 p-4 dark:border-blue-900 dark:bg-blue-950/30">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <p class="text-sm font-semibold">
                {{ current.target }}
                <span class="font-normal text-slate-500 dark:text-slate-400">· {{ scanLabels[current.scan_type] || current.scan_type }} scan</span>
            </p>
            <p class="text-xs tabular-nums text-slate-600 dark:text-slate-300">
                <span v-if="determinate" class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ percent }}%</span>
                <span v-if="current.status === 'running'" class="ml-2">{{ formatDuration(elapsed) }}</span>
            </p>
        </div>

        <div class="mt-3 h-2 overflow-hidden rounded-full bg-blue-100 dark:bg-blue-900/50" role="progressbar" :aria-valuenow="determinate ? percent : undefined" aria-valuemin="0" aria-valuemax="100" :aria-label="progress?.label || 'Scan progress'">
            <div v-if="determinate" class="h-full rounded-full bg-blue-600 transition-[width] duration-700 ease-out dark:bg-blue-400" :style="{ width: `${Math.max(2, percent)}%` }" />
            <div v-else class="scan-indeterminate h-full w-1/3 rounded-full bg-blue-600 dark:bg-blue-400" />
        </div>

        <p class="mt-2 text-xs text-slate-600 dark:text-slate-300">
            {{ progress?.label || (current.status === 'queued' ? 'Waiting for a worker' : 'Starting…') }}<span v-if="progress?.total">: {{ number(progress.done) }} of {{ number(progress.total) }} files</span>
        </p>

        <div v-if="progress && progress.phase !== 'queued' && progress.phase !== 'checking'" class="mt-3 border-t border-blue-200/70 pt-3 dark:border-blue-900/70">
            <div class="flex flex-wrap items-center gap-2 text-xs">
                <span class="font-medium">Found so far:</span>
                <span v-if="foundTotal === 0" class="text-emerald-700 dark:text-emerald-400">nothing yet</span>
                <span v-for="item in foundCounts" :key="item.severity" class="rounded-full px-2 py-0.5 capitalize" :class="severityStyles[item.severity]">
                    {{ item.count }} {{ item.severity }}
                </span>
            </div>
            <ul v-if="progress.recent?.length" class="mt-2 space-y-1 text-xs">
                <li v-for="(item, index) in progress.recent" :key="`${item.rule_id}-${item.file_path}-${index}`" class="flex items-start gap-2">
                    <span class="mt-0.5 shrink-0 rounded px-1.5 text-[10px] font-semibold uppercase" :class="severityStyles[item.severity]">{{ item.severity }}</span>
                    <span class="min-w-0 flex-1">
                        <span class="text-slate-800 dark:text-slate-100">{{ item.title }}</span>
                        <span v-if="item.file_path" class="block truncate font-mono text-[11px] text-slate-500">{{ item.file_path }}</span>
                    </span>
                    <span v-if="item.penalty" class="shrink-0 whitespace-nowrap text-slate-600 dark:text-slate-300" :title="`Lowers the ${item.category_label} score by ${item.penalty} points while open`">
                        −{{ item.penalty }} {{ item.category_label }}
                    </span>
                </li>
            </ul>
            <p v-if="foundTotal > (progress.recent?.length || 0)" class="mt-1 text-[11px] text-slate-500">Showing the latest {{ progress.recent.length }} of {{ foundTotal }}.</p>
        </div>

        <ol v-if="progress?.steps?.length > 1" class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs">
            <li v-for="step in progress.steps" :key="step.key" class="flex items-center gap-1.5" :class="{ 'text-slate-400 dark:text-slate-500': step.state === 'pending', 'font-medium text-blue-700 dark:text-blue-300': step.state === 'active', 'text-slate-600 dark:text-slate-300': step.state === 'done' }">
                <i v-if="step.state === 'done'" class="bi bi-check-circle-fill text-emerald-600 dark:text-emerald-400" aria-hidden="true" />
                <i v-else-if="step.state === 'active'" class="bi bi-arrow-repeat inline-block animate-spin" aria-hidden="true" />
                <i v-else class="bi bi-circle" aria-hidden="true" />
                {{ step.label }}
            </li>
        </ol>
    </div>
</template>

<style scoped>
.scan-indeterminate {
    animation: scan-slide 1.4s ease-in-out infinite;
}

@keyframes scan-slide {
    0% { transform: translateX(-100%); }
    100% { transform: translateX(300%); }
}

@media (prefers-reduced-motion: reduce) {
    .scan-indeterminate {
        animation: none;
        width: 100%;
        opacity: 0.5;
    }
}
</style>
