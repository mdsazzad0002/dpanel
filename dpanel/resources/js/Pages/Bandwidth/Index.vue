<script setup>
import { computed, ref, watch } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';

const props = defineProps({
    scope: { type: String, default: 'Your websites' },
    showOwner: { type: Boolean, default: false },
    canManageWebsites: { type: Boolean, default: false },
    month: { type: String, required: true },
    months: { type: Array, default: () => [] },
    available: { type: Boolean, default: false },
    rows: { type: Array, default: () => [] },
    dailyRows: { type: Array, default: () => [] },
    totals: { type: Object, default: () => ({}) },
});

const page = usePage();
const panelRoute = (name, params = {}) => page.props.panel?.token
    ? route(name, { token: page.props.panel.token, ...params })
    : route(name, params);

const formatBytes = (bytes) => {
    let value = Math.max(0, Number(bytes) || 0);
    if (value < 1024) return `${value} B`;
    const units = ['KB', 'MB', 'GB', 'TB'];
    let unit = -1;
    do { value /= 1024; unit += 1; } while (value >= 1024 && unit < units.length - 1);
    return `${value.toFixed(value >= 100 ? 0 : 2)} ${units[unit]}`;
};
const formatCount = (value) => Number(value || 0).toLocaleString();
const monthLabelFor = (value) => {
    const [year, month] = String(value).split('-').map(Number);
    return new Intl.DateTimeFormat(undefined, { month: 'long', year: 'numeric', timeZone: 'UTC' })
        .format(new Date(Date.UTC(year, month - 1, 1)));
};
const monthLabel = computed(() => monthLabelFor(props.month));
const monthOptions = computed(() => props.months.map((value) => ({ value, label: monthLabelFor(value) })));

const selectedMonth = ref(props.month);
watch(() => props.month, (value) => { selectedMonth.value = value; });
watch(selectedMonth, (value) => {
    if (value && value !== props.month) {
        router.get(panelRoute('bandwidth.index'), { month: value }, { preserveScroll: true, preserveState: true, replace: true });
    }
});

const search = ref('');
const sortKey = ref('total_bytes');
const sortOptions = [
    { key: 'total_bytes', label: 'Traffic' },
    { key: 'requests', label: 'Requests' },
    { key: 'domain', label: 'Domain' },
];
const visibleRows = computed(() => {
    const q = search.value.trim().toLowerCase();
    const rows = q
        ? props.rows.filter((row) => row.domain.includes(q) || String(row.owner || '').toLowerCase().includes(q))
        : [...props.rows];
    return sortKey.value === 'domain'
        ? rows.sort((a, b) => a.domain.localeCompare(b.domain))
        : rows.sort((a, b) => (Number(b[sortKey.value]) || 0) - (Number(a[sortKey.value]) || 0));
});
const maxDomainBytes = computed(() => Math.max(1, ...props.rows.map((row) => Number(row.total_bytes) || 0)));
const share = (bytes) => {
    const total = Number(props.totals.total_bytes) || 0;
    if (!total) return '0%';
    const percent = (Number(bytes) || 0) / total * 100;
    return `${percent >= 10 ? percent.toFixed(0) : percent.toFixed(1)}%`;
};

const stats = computed(() => [
    { label: 'Total traffic', value: formatBytes(props.totals.total_bytes), icon: 'bi-arrow-left-right' },
    { label: 'Requests', value: formatCount(props.totals.requests), icon: 'bi-activity' },
    { label: 'Downloaded', value: formatBytes(props.totals.download_bytes), icon: 'bi-download' },
    { label: 'Uploaded', value: formatBytes(props.totals.upload_bytes), icon: 'bi-upload' },
    { label: 'Websites with traffic', value: `${formatCount(props.totals.active_websites)} / ${formatCount(props.totals.websites)}`, icon: 'bi-globe2' },
]);
const maxDailyBytes = computed(() => Math.max(1, ...props.dailyRows.map((row) => Number(row.total_bytes) || 0)));
</script>

<template>
    <Head title="Bandwidth" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-semibold text-slate-900 dark:text-white">Bandwidth</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ scope }} · traffic and requests per website</p>
                </div>
                <div class="flex items-center gap-2">
                    <div class="w-48">
                        <SearchableSelect v-model="selectedMonth" :options="monthOptions" search-placeholder="Search month…" />
                    </div>
                    <Link :href="panelRoute('dashboard')" class="rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50 dark:border-white/10 dark:text-slate-200 dark:hover:bg-white/5">
                        <i class="bi bi-arrow-left mr-1"></i> Dashboard
                    </Link>
                </div>
            </div>
        </template>

        <div class="space-y-5">
            <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                <article v-for="stat in stats" :key="stat.label" class="rounded-xl border border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-slate-900">
                    <div class="flex items-center justify-between text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
                        <span>{{ stat.label }}</span><i :class="['bi text-base text-emerald-500', stat.icon]"></i>
                    </div>
                    <p class="mt-2 text-2xl font-semibold text-slate-900 dark:text-white">{{ stat.value }}</p>
                </article>
            </section>

            <section class="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-white/10 dark:bg-slate-900">
                <header class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3 dark:border-white/10 sm:px-5">
                    <div>
                        <h2 class="font-medium text-slate-900 dark:text-white">Websites</h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ monthLabel }} · bandwidth and requests per website</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="relative">
                            <i class="bi bi-search pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400"></i>
                            <input v-model="search" type="search" :placeholder="showOwner ? 'Search domain or owner…' : 'Search domain…'"
                                class="w-56 rounded-lg border-slate-300 bg-white py-1.5 pl-8 text-sm dark:border-white/10 dark:bg-slate-800 dark:text-white" />
                        </div>
                        <div class="inline-flex overflow-hidden rounded-lg border border-slate-200 text-xs dark:border-white/10">
                            <button v-for="option in sortOptions" :key="option.key" type="button" @click="sortKey = option.key"
                                :class="['px-3 py-1.5', sortKey === option.key ? 'bg-emerald-500 text-white' : 'text-slate-600 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-white/5']">
                                {{ option.label }}
                            </button>
                        </div>
                    </div>
                </header>

                <div v-if="!available" class="p-8 text-center">
                    <i class="bi bi-hourglass-split text-2xl text-slate-400"></i>
                    <p class="mt-2 font-medium text-slate-700 dark:text-slate-200">No bandwidth data for {{ monthLabel }}</p>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">The gateway writes its monthly usage snapshot about every 30 seconds once traffic arrives.</p>
                </div>
                <div v-else-if="rows.length === 0" class="p-8 text-center text-sm text-slate-500 dark:text-slate-400">
                    No websites are assigned to this account.
                </div>
                <div v-else class="overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                            <tr>
                                <th class="px-4 py-3 font-medium">Website</th>
                                <th v-if="showOwner" class="px-4 py-3 font-medium">Owner</th>
                                <th class="px-4 py-3 text-right font-medium">Requests</th>
                                <th class="px-4 py-3 text-right font-medium">Upload</th>
                                <th class="px-4 py-3 text-right font-medium">Download</th>
                                <th class="min-w-48 px-4 py-3 font-medium">Total bandwidth</th>
                                <th class="px-4 py-3 text-right font-medium">Share</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in visibleRows" :key="row.domain" class="border-t border-slate-100 dark:border-white/[0.06]">
                                <td class="px-4 py-3 font-medium text-slate-800 dark:text-slate-100">
                                    <Link v-if="canManageWebsites && row.id" :href="panelRoute('websites.manage', { id: row.id })" class="hover:text-emerald-600 hover:underline dark:hover:text-emerald-300">{{ row.domain }}</Link>
                                    <span v-else :class="{ 'text-slate-500 dark:text-slate-400': row.removed }">{{ row.domain }}</span>
                                    <p v-if="row.aliases?.length" class="mt-0.5 text-xs font-normal text-slate-500 dark:text-slate-400">
                                        + {{ row.aliases.length }} alias{{ row.aliases.length > 1 ? 'es' : '' }}: {{ row.aliases.join(', ') }}
                                    </p>
                                    <p v-if="row.note" class="mt-0.5 text-xs font-normal text-amber-600 dark:text-amber-400">{{ row.note }}</p>
                                </td>
                                <td v-if="showOwner" class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ row.owner || '—' }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ formatCount(row.requests) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ formatBytes(row.upload_bytes) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ formatBytes(row.download_bytes) }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100 dark:bg-white/10">
                                            <div class="h-full rounded-full bg-gradient-to-r from-emerald-400 to-cyan-400" :style="{ width: `${Math.max(2, Number(row.total_bytes || 0) / maxDomainBytes * 100)}%` }"></div>
                                        </div>
                                        <span class="w-20 text-right font-medium tabular-nums text-slate-700 dark:text-slate-200">{{ formatBytes(row.total_bytes) }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-500 dark:text-slate-400">{{ share(row.total_bytes) }}</td>
                            </tr>
                            <tr v-if="visibleRows.length === 0">
                                <td :colspan="showOwner ? 7 : 6" class="px-4 py-6 text-center text-sm text-slate-500 dark:text-slate-400">No website matches “{{ search }}”.</td>
                            </tr>
                        </tbody>
                        <tfoot class="border-t border-slate-200 bg-slate-50 font-semibold dark:border-white/10 dark:bg-slate-800">
                            <tr>
                                <td class="px-4 py-3" :colspan="showOwner ? 2 : 1">Total ({{ formatCount(totals.websites) }} websites)</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ formatCount(totals.requests) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ formatBytes(totals.upload_bytes) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ formatBytes(totals.download_bytes) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ formatBytes(totals.total_bytes) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">100%</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <p class="border-t border-slate-100 px-4 py-3 text-xs text-slate-500 dark:border-white/[0.06] dark:text-slate-400">
                    Each website includes the traffic of its alias domains. Upload and download count HTTP body payload bytes for requests routed by the gateway; TLS and protocol overhead are excluded. Times are UTC.
                </p>
            </section>

            <section class="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-white/10 dark:bg-slate-900">
                <header class="border-b border-slate-200 px-4 py-3 dark:border-white/10 sm:px-5">
                    <h2 class="font-medium text-slate-900 dark:text-white">Daily usage</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Daily totals across all websites listed above</p>
                </header>
                <div v-if="dailyRows.length" class="overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                            <tr>
                                <th class="px-4 py-3 font-medium">Date (UTC)</th>
                                <th class="px-4 py-3 text-right font-medium">Requests</th>
                                <th class="px-4 py-3 text-right font-medium">Upload</th>
                                <th class="px-4 py-3 text-right font-medium">Download</th>
                                <th class="min-w-48 px-4 py-3 font-medium">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="day in dailyRows" :key="day.date" class="border-t border-slate-100 dark:border-white/[0.06]">
                                <td class="px-4 py-3 font-medium text-slate-800 dark:text-slate-100">{{ day.date }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ formatCount(day.requests) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ formatBytes(day.upload_bytes) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ formatBytes(day.download_bytes) }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100 dark:bg-white/10">
                                            <div class="h-full rounded-full bg-emerald-400" :style="{ width: `${Math.max(2, Number(day.total_bytes || 0) / maxDailyBytes * 100)}%` }"></div>
                                        </div>
                                        <span class="w-20 text-right font-semibold tabular-nums">{{ formatBytes(day.total_bytes) }}</span>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="px-4 py-6 text-center text-sm text-slate-500 dark:text-slate-400">
                    No daily breakdown exists for this month yet.
                </p>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
