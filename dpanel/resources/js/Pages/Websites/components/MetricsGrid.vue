<script setup>
import { computed, inject } from 'vue';
import { Deferred } from '@inertiajs/vue3';

const props = defineProps({
    website: {
        type: Object,
        required: true,
    },
    metrics: {
        type: Object,
        default: () => ({}),
    },
});

const pushToast = inject('pushToast');

const toNumber = (value) => {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : 0;
};

const metrics = computed(() => [
    { label: 'Connections', value: toNumber(props.metrics.connections_current), icon: 'bi-people', color: 'blue' },
    { label: 'Active Jobs', value: toNumber(props.metrics.jobs_pending), icon: 'bi-list-task', color: 'amber' },
    { label: 'Databases', value: toNumber(props.metrics.databases_count), icon: 'bi-database', color: 'violet' },
    { label: 'Disk Usage', value: `${toNumber(props.metrics.disk_used_mb).toFixed(1)} MB`, icon: 'bi-hdd', color: 'emerald', sub: `Files: ${toNumber(props.metrics.file_count)}` },
]);

const metricColorClasses = {
    blue: 'bg-blue-500/10 text-blue-600 dark:bg-blue-500/15 dark:text-blue-400',
    amber: 'bg-amber-500/10 text-amber-600 dark:bg-amber-500/15 dark:text-amber-400',
    violet: 'bg-violet-500/10 text-violet-600 dark:bg-violet-500/15 dark:text-violet-400',
    emerald: 'bg-emerald-500/10 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-400',
};

const localDevPermissionCommand = computed(() => {
    const path = String(props.website?.root_path || '').trim();
    if (!path) return '';
    return `sudo chmod -R u+rwX ${path} && sudo chmod -R 777 ${path}`;
});

const copyToClipboard = (text, toastMessage = '') => {
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text);
    }
    if (toastMessage) {
        pushToast?.(toastMessage, 'success');
    }
};
</script>

<template>
    <section class="order-2 grid gap-3 sm:grid-cols-2 xl:col-start-2 xl:row-start-1 xl:grid-cols-1">
        <div v-if="localDevPermissionCommand" class=" rounded-xl border border-blue-200 bg-blue-50/50 p-3 dark:border-blue-800 dark:bg-blue-500/10">
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-blue-500 dark:text-blue-400">For Local Development</p>
                    <p class="mt-1 text-xs text-blue-700 dark:text-blue-300">Developer running this project locally on your PC with VS Code? Run this to fix file permissions.</p>
                </div>
                <button type="button"
                    class="shrink-0 rounded-lg border border-blue-200 bg-white p-1.5 text-blue-500 transition hover:text-blue-700 dark:border-blue-800 dark:bg-slate-900 dark:hover:text-blue-300"
                    @click="copyToClipboard(localDevPermissionCommand, 'Command copied — paste it in your local terminal.')" title="Copy command" aria-label="Copy command">
                    <i class="bi bi-copy text-sm"></i>
                </button>
            </div>
            <code class="mt-2 block overflow-x-auto  rounded-lg bg-white/70 px-2.5 py-1.5 text-[11px] text-blue-800 dark:bg-slate-900/50 dark:text-blue-300">{{ localDevPermissionCommand }}</code>
            <p class="mt-2 flex items-start gap-1.5 text-[11px] font-medium text-amber-600 dark:text-amber-400">
                <i class="bi bi-exclamation-triangle-fill mt-0.5 shrink-0"></i>
                <span>Development risk: this opens file permissions to 777. Only run it on your local machine — never on a production server.</span>
            </p>
        </div>
        <Deferred data="metrics">
            <template #fallback>
                <div v-for="n in 4" :key="n"
                    class="animate-pulse rounded-xl border border-slate-200/80 bg-white p-4 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50">
                    <div class="flex items-start justify-between">
                        <div class="space-y-2">
                            <div class="h-2.5 w-16 rounded bg-slate-200 dark:bg-slate-700"></div>
                            <div class="h-6 w-12 rounded bg-slate-200 dark:bg-slate-700"></div>
                        </div>
                        <div class="h-10 w-10 rounded-xl bg-slate-200 dark:bg-slate-700"></div>
                    </div>
                </div>
            </template>
            <div v-for="metric in metrics" :key="metric.label"
                class="group rounded-xl border border-slate-200/80 bg-white p-4 shadow-sm transition hover:shadow-md dark:border-slate-800/80 dark:bg-slate-900/50">
                <div class="flex items-start justify-between">
                    <div>
                        <p
                            class="text-[11px] font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500">
                            {{ metric.label }}</p>
                        <p class="mt-2 text-2xl font-bold text-slate-900 dark:text-slate-100">{{
                            metric.value }}</p>
                        <p v-if="metric.sub" class="mt-1 text-[11px] text-slate-400 dark:text-slate-500">{{
                            metric.sub }}</p>
                    </div>
                    <div
                        :class="['flex h-10 w-10 items-center justify-center rounded-xl transition', metricColorClasses[metric.color]]">
                        <svg viewBox="0 0 24 24" class="h-5 w-5 fill-current opacity-80">
                            <path
                                d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-5 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z" />
                        </svg>
                    </div>
                </div>
            </div>
        </Deferred>
    </section>
</template>
