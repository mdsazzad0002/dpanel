<script setup>
import { computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';

const props = defineProps({
    website: {
        type: Object,
        required: true,
    },
    metrics: {
        type: Object,
        default: () => ({}),
    },
    histories: {
        type: Object,
        default: () => ({ points: [] }),
    },
});

const page = usePage();

const toNumber = (value) => {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : 0;
};

const formatDate = (value) => {
    if (!value) return '-';
    try {
        return new Date(value).toLocaleString();
    } catch (error) {
        return String(value);
    }
};

const diskUsedMb = computed(() => toNumber(props.metrics?.disk_used_mb));
const diskLimitMb = computed(() => Math.max(1, toNumber(props.metrics?.disk_limit_mb)));
const diskUsagePercent = computed(() => Math.min(100, Math.round((diskUsedMb.value / diskLimitMb.value) * 100)));
const cpuUsagePercent = computed(() => Math.min(100, Math.max(0, Math.round(toNumber(props.metrics?.cpu_usage_percent)))));

const historyPoints = computed(() => {
    const points = Array.isArray(props.histories?.points) ? props.histories.points : [];
    return points.map((point) => ({
        time: String(point?.time || '-'),
        connections: toNumber(point?.connections),
        jobs: toNumber(point?.jobs),
        databases: toNumber(point?.databases),
        disk: toNumber(point?.disk),
        cpu: toNumber(point?.cpu),
        ram: toNumber(point?.ram),
    }));
});
</script>

<template>
    <Head title="Usage Details" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-semibold">Usage Details</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        Website usage metrics and history for {{ website.domain || '-' }}.
                    </p>
                </div>

            </div>
        </template>


        <div class="flex justify-end gap-2 m-6">
                <Link :href="route('websites.manage', website.id)" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                   <i class="bi bi-arrow-left mr-2"></i> Back to Manage
                </Link>
                <Link :href="route('websites.ssl', website.id)" class="rounded-md border border-emerald-300 px-3 py-2 text-sm text-emerald-700 hover:bg-emerald-50 dark:border-emerald-700 dark:text-emerald-300 dark:hover:bg-emerald-900/20">
                   <i class="bi bi-shield-check mr-2"></i> Open SSL Manager
                </Link>
            </div>

        <div class="space-y-6">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ page.props.flash.success }}
            </div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ page.props.flash.error }}
            </div>

            <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <div class="rounded-lg border border-slate-200 p-4 dark:border-slate-700">
                        <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Disk Usage</p>
                        <p class="mt-2 text-lg font-semibold">{{ diskUsedMb.toFixed(2) }} MB</p>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ diskUsagePercent }}% of {{ diskLimitMb.toFixed(0) }} MB</p>
                    </div>
                    <div class="rounded-lg border border-slate-200 p-4 dark:border-slate-700">
                        <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">CPU / RAM</p>
                        <p class="mt-2 text-lg font-semibold">{{ cpuUsagePercent }}% CPU</p>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ toNumber(metrics.ram_usage_mb) }} MB RAM</p>
                    </div>
                    <div class="rounded-lg border border-slate-200 p-4 dark:border-slate-700">
                        <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Files</p>
                        <p class="mt-2 text-lg font-semibold">{{ toNumber(metrics.file_count) }}</p>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Last change: {{ formatDate(metrics.last_modified_at) }}</p>
                    </div>
                </div>

                <div class="mt-4 grid gap-3 sm:grid-cols-3">
                    <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                        <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Connections</p>
                        <p class="mt-1 text-xl font-semibold">{{ toNumber(metrics.connections_current) }}</p>
                    </div>
                    <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                        <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Active Cron Jobs</p>
                        <p class="mt-1 text-xl font-semibold">{{ toNumber(metrics.jobs_pending) }}</p>
                    </div>
                    <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                        <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Databases</p>
                        <p class="mt-1 text-xl font-semibold">{{ toNumber(metrics.databases_count) }}</p>
                    </div>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Usage History (Last 12 hours)</h2>
                <div class="mt-3 overflow-x-auto rounded-lg border border-slate-200 dark:border-slate-700">
                    <table class="min-w-full text-left text-sm">
                        <thead class="bg-slate-50 dark:bg-slate-800">
                            <tr>
                                <th class="px-3 py-2">Time</th>
                                <th class="px-3 py-2">Connections</th>
                                <th class="px-3 py-2">Jobs</th>
                                <th class="px-3 py-2">Databases</th>
                                <th class="px-3 py-2">Disk MB</th>
                                <th class="px-3 py-2">CPU %</th>
                                <th class="px-3 py-2">RAM MB</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="point in historyPoints" :key="`${point.time}-${point.connections}-${point.jobs}-${point.disk}`" class="border-t border-slate-200 dark:border-slate-800">
                                <td class="px-3 py-2">{{ point.time }}</td>
                                <td class="px-3 py-2">{{ point.connections }}</td>
                                <td class="px-3 py-2">{{ point.jobs }}</td>
                                <td class="px-3 py-2">{{ point.databases }}</td>
                                <td class="px-3 py-2">{{ point.disk }}</td>
                                <td class="px-3 py-2">{{ point.cpu }}</td>
                                <td class="px-3 py-2">{{ point.ram }}</td>
                            </tr>
                            <tr v-if="historyPoints.length === 0">
                                <td colspan="7" class="px-3 py-5 text-center text-slate-500 dark:text-slate-400">
                                    No usage history points available.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
