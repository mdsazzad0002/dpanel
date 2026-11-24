<script setup>
import { useI18n } from 'vue-i18n';
import { computed } from 'vue';
import { Deferred } from '@inertiajs/vue3';
import DevelopmentSettingsPanel from '@/Pages/Websites/components/DevelopmentSettingsPanel.vue';

const { t } = useI18n();

const props = defineProps({
    website: {
        type: Object,
        required: true,
    },
    metrics: {
        type: Object,
        default: () => ({}),
    },
    rootInspection: {
        type: Object,
        default: () => ({}),
    },
});

const toNumber = (value) => {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : 0;
};

const metrics = computed(() => [
    { label: t('websites.metrics.connections'), value: toNumber(props.metrics.connections_current), icon: 'bi-people', color: 'blue' },
    { label: t('websites.metrics.activeJobs'), value: toNumber(props.metrics.jobs_pending), icon: 'bi-list-task', color: 'amber' },
    { label: t('websites.metrics.databases'), value: toNumber(props.metrics.databases_count), icon: 'bi-database', color: 'violet' },
    { label: t('websites.metrics.diskUsage'), value: `${toNumber(props.metrics.disk_used_mb).toFixed(1)} MB`, icon: 'bi-hdd', color: 'emerald', sub: t('websites.metrics.files', { count: toNumber(props.metrics.file_count) }) },
]);

const metricColorClasses = {
    blue: 'bg-blue-500/10 text-blue-600 dark:bg-blue-500/15 dark:text-blue-400',
    amber: 'bg-amber-500/10 text-amber-600 dark:bg-amber-500/15 dark:text-amber-400',
    violet: 'bg-violet-500/10 text-violet-600 dark:bg-violet-500/15 dark:text-violet-400',
    emerald: 'bg-emerald-500/10 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-400',
};
</script>

<template>
    <section class="order-2 grid min-w-0 grid-cols-1 gap-3 sm:grid-cols-2 xl:col-start-2 xl:row-start-1 xl:grid-cols-1">
        <DevelopmentSettingsPanel :website="website" :root-inspection="rootInspection" />
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
