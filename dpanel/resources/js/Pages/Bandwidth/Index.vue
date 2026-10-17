<script setup>
import { computed, ref, watch } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';

const props = defineProps({
    scope: { type: String, default: 'Your account usage' },
    month: { type: String, required: true },
    months: { type: Array, default: () => [] },
    available: { type: Boolean, default: false },
    rows: { type: Array, default: () => [] },
    dailyRows: { type: Array, default: () => [] },
    totals: { type: Object, default: () => ({}) },
});

const page = usePage();
const panelRoute = (name) => page.props.panel?.token
    ? route(name, { token: page.props.panel.token })
    : route(name);
const selectedMonth = ref(props.month);
watch(() => props.month, (value) => { selectedMonth.value = value; });

const formatBytes = (bytes) => {
    let value = Math.max(0, Number(bytes) || 0);
    if (value < 1024) return `${value} B`;
    const units = ['KB', 'MB', 'GB', 'TB'];
    let unit = -1;
    do { value /= 1024; unit += 1; } while (value >= 1024 && unit < units.length - 1);
    return `${value.toFixed(value >= 100 ? 0 : 2)} ${units[unit]}`;
};
const monthLabel = computed(() => {
    const [year, month] = props.month.split('-').map(Number);
    return new Intl.DateTimeFormat(undefined, { month: 'long', year: 'numeric', timeZone: 'UTC' })
        .format(new Date(Date.UTC(year, month - 1, 1)));
});
const maxDomainBytes = computed(() => Math.max(1, ...props.rows.map((row) => Number(row.total_bytes) || 0)));

const changeMonth = () => router.get(panelRoute('dashboard.usage'), { month: selectedMonth.value }, {
    preserveScroll: true,
    preserveState: true,
    replace: true,
});
</script>

<template>
    <Head title="Bandwidth usage" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-semibold text-slate-900 dark:text-white">Bandwidth usage</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ scope }} · Monthly traffic by website domain</p>
                </div>
                <Link :href="panelRoute('dashboard')" class="rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50 dark:border-white/10 dark:text-slate-200 dark:hover:bg-white/5">
                    <i class="bi bi-arrow-left mr-1"></i> Dashboard
                </Link>
            </div>
        </template>

        <div class="space-y-5">
            <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <article v-for="stat in [
                    { label: 'Total traffic', value: formatBytes(totals.total_bytes), icon: 'bi-arrow-left-right' },
                    { label: 'Downloaded', value: formatBytes(totals.download_bytes), icon: 'bi-download' },
                    { label: 'Uploaded', value: formatBytes(totals.upload_bytes), icon: 'bi-upload' },
                    { label: 'Requests', value: Number(totals.requests || 0).toLocaleString(), icon: 'bi-activity' },
                ]" :key="stat.label" class="rounded-xl border border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-slate-900">
                    <div class="flex items-center justify-between text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
                        <span>{{ stat.label }}</span><i :class="['bi text-base text-emerald-500', stat.icon]"></i>
                    </div>
                    <p class="mt-2 text-2xl font-semibold text-slate-900 dark:text-white">{{ stat.value }}</p>
                </article>
            </section>

            <section class="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-white/10 dark:bg-slate-900">
                <header class="border-b border-slate-200 px-4 py-3 dark:border-white/10 sm:px-5">
                    <h2 class="font-medium text-slate-900 dark:text-white">Daily usage log</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Daily totals across your assigned website domains</p>
                </header>
                <div v-if="dailyRows.length" class="overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                            <tr>
                                <th class="px-4 py-3 font-medium">Date (UTC)</th>
                                <th class="px-4 py-3 text-right font-medium">Requests</th>
                                <th class="px-4 py-3 text-right font-medium">Upload</th>
                                <th class="px-4 py-3 text-right font-medium">Download</th>
                                <th class="px-4 py-3 text-right font-medium">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="day in dailyRows" :key="day.date" class="border-t border-slate-100 dark:border-white/[0.06]">
                                <td class="px-4 py-3 font-medium text-slate-800 dark:text-slate-100">{{ day.date }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ Number(day.requests || 0).toLocaleString() }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ formatBytes(day.upload_bytes) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ formatBytes(day.download_bytes) }}</td>
                                <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ formatBytes(day.total_bytes) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="px-4 py-6 text-center text-sm text-slate-500 dark:text-slate-400">
                    No daily breakdown exists for this month yet. Daily tracking starts with the gateway update.
                </p>
            </section>

            <section class="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-white/10 dark:bg-slate-900">
                <header class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3 dark:border-white/10 sm:px-5">
                    <div>
                        <h2 class="font-medium text-slate-900 dark:text-white">Usage log</h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ monthLabel }} · traffic payload totals per domain</p>
                    </div>
                    <select v-model="selectedMonth" @change="changeMonth" class="rounded-lg border-slate-300 bg-white text-sm dark:border-white/10 dark:bg-slate-800 dark:text-white">
                        <option v-for="item in months" :key="item" :value="item">{{ item }}</option>
                    </select>
                </header>

                <div v-if="!available" class="p-8 text-center">
                    <i class="bi bi-hourglass-split text-2xl text-slate-400"></i>
                    <p class="mt-2 font-medium text-slate-700 dark:text-slate-200">Usage data is not available yet</p>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">The gateway writes its monthly usage snapshot about every 30 seconds.</p>
                </div>
                <div v-else-if="rows.length === 0" class="p-8 text-center text-sm text-slate-500 dark:text-slate-400">
                    No website or mail domains are assigned to this account.
                </div>
                <div v-else class="overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                            <tr>
                                <th class="px-4 py-3 font-medium">Domain</th>
                                <th class="px-4 py-3 text-right font-medium">Requests</th>
                                <th class="px-4 py-3 text-right font-medium">Upload</th>
                                <th class="px-4 py-3 text-right font-medium">Download</th>
                                <th class="min-w-40 px-4 py-3 font-medium">Total traffic</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in rows" :key="row.domain" class="border-t border-slate-100 dark:border-white/[0.06]">
                                <td class="px-4 py-3 font-medium text-slate-800 dark:text-slate-100">{{ row.domain }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ Number(row.requests || 0).toLocaleString() }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ formatBytes(row.upload_bytes) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ formatBytes(row.download_bytes) }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100 dark:bg-white/10">
                                            <div class="h-full rounded-full bg-gradient-to-r from-emerald-400 to-cyan-400" :style="{ width: `${Math.max(2, Number(row.total_bytes || 0) / maxDomainBytes * 100)}%` }"></div>
                                        </div>
                                        <span class="w-20 text-right tabular-nums text-slate-700 dark:text-slate-200">{{ formatBytes(row.total_bytes) }}</span>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                        <tfoot class="border-t border-slate-200 bg-slate-50 font-semibold dark:border-white/10 dark:bg-slate-800">
                            <tr>
                                <td class="px-4 py-3">Account total</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ Number(totals.requests || 0).toLocaleString() }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ formatBytes(totals.upload_bytes) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ formatBytes(totals.download_bytes) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ formatBytes(totals.total_bytes) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <p class="border-t border-slate-100 px-4 py-3 text-xs text-slate-500 dark:border-white/[0.06] dark:text-slate-400">
                    Upload and download count HTTP body payload bytes for requests routed by this gateway. TLS and protocol overhead are excluded.
                </p>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
