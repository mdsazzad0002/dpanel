<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

const props = defineProps({
    stats: { type: Object, default: () => ({}) },
    series: { type: Array, default: () => [] },
    providers: { type: Array, default: () => [] },
    perProviderRequests: { type: Array, default: () => [] },
    recentLogs: { type: Array, default: () => [] },
    usage: { type: Object, default: () => ({}) },
});

const filters = reactive({
    from: props.usage.filters?.from || '',
    to: props.usage.filters?.to || '',
    model: props.usage.filters?.model || '',
    provider: props.usage.filters?.provider || '',
});

const applyUsageFilters = () => {
    router.get(panelRoute('ai-gateway.dashboard'), {
        from: filters.from,
        to: filters.to,
        model: filters.model || '',
        provider: filters.provider || '',
    }, { preserveState: true, preserveScroll: true });
};

const fmt = (n) => Number(n || 0).toLocaleString();
const maxSeries = computed(() => Math.max(1, ...props.series.map((s) => s.requests)));
const maxDaily = computed(() => Math.max(1, ...(props.usage.daily || []).map((d) => d.tokens)));

const statusBadge = (status) => ({
    success: 'bg-emerald-100 text-emerald-700',
    error: 'bg-red-100 text-red-700',
    timeout: 'bg-amber-100 text-amber-700',
    cancelled: 'bg-slate-200 text-slate-600',
}[status] || 'bg-slate-200 text-slate-600');
const statusLabel = (s) => (['success', 'error', 'timeout', 'cancelled'].includes(s) ? t(`aiGateway.logs.status.${s}`) : s);
</script>

<template>
    <Head :title="t('aiGateway.dashboard.title')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-lg font-semibold">{{ t('aiGateway.dashboard.title') }}</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ t('aiGateway.dashboard.subtitle') }}</p>
                </div>
                <Link :href="panelRoute('ai-gateway.providers.create')" class="rounded-md bg-blue-600 px-3 py-2 text-sm text-white hover:bg-blue-700">
                    + {{ t('aiGateway.providers.add') }}
                </Link>
            </div>
        </template>

        <div class="space-y-6">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ page.props.flash.success }}
            </div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ page.props.flash.error }}
            </div>

            <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                <div class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                    <div class="text-xs text-slate-500">{{ t('aiGateway.dashboard.requests7d') }}</div>
                    <div class="mt-1 text-2xl font-bold">{{ fmt(stats.period?.requests) }}</div>
                    <div class="text-xs text-slate-400">{{ t('aiGateway.dashboard.thisMonth', { count: fmt(stats.month?.requests) }) }}</div>
                </div>
                <div class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                    <div class="text-xs text-slate-500">{{ t('aiGateway.dashboard.tokens7d') }}</div>
                    <div class="mt-1 text-2xl font-bold">{{ fmt((stats.period?.input_tokens || 0) + (stats.period?.output_tokens || 0)) }}</div>
                    <div class="text-xs text-slate-400">{{ t('aiGateway.dashboard.inOut', { input: fmt(stats.period?.input_tokens), output: fmt(stats.period?.output_tokens) }) }}</div>
                </div>
                <div class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                    <div class="text-xs text-slate-500">{{ t('aiGateway.dashboard.activeProviders') }}</div>
                    <div class="mt-1 text-2xl font-bold">{{ stats.activeProviders }} <span class="text-base text-slate-400">/ {{ stats.totalProviders }}</span></div>
                    <div class="text-xs text-slate-400">{{ t('aiGateway.dashboard.modelCount', { count: stats.models }) }}</div>
                </div>
                <div class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                    <div class="text-xs text-slate-500">{{ t('aiGateway.dashboard.failuresRange') }}</div>
                    <div class="mt-1 text-2xl font-bold">{{ fmt(usage.totals?.failures) }}</div>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <!-- 7 day bar chart -->
                <div class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                    <h2 class="mb-3 text-sm font-semibold">{{ t('aiGateway.dashboard.last7') }}</h2>
                    <div class="flex h-40 items-end gap-2">
                        <div v-for="s in series" :key="s.date" class="flex flex-1 flex-col items-center gap-1">
                            <span class="text-[10px] text-slate-400">{{ s.requests }}</span>
                            <div class="w-full rounded-t bg-blue-500" :style="{ height: `${Math.max(2, (s.requests / maxSeries) * 100)}px` }"></div>
                            <span class="text-[10px] text-slate-500">{{ s.date.slice(5) }}</span>
                        </div>
                    </div>
                </div>

                <!-- Provider health -->
                <div class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                    <h2 class="mb-3 text-sm font-semibold">{{ t('aiGateway.dashboard.providers') }}</h2>
                    <div v-if="!providers.length" class="text-sm text-slate-500">{{ t('aiGateway.dashboard.noProviders') }}</div>
                    <ul v-else class="space-y-2">
                        <li v-for="p in providers" :key="p.id" class="flex items-center justify-between rounded-md border border-slate-100 px-3 py-2 text-sm dark:border-slate-700">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="h-2 w-2 rounded-full" :class="p.is_active ? 'bg-emerald-500' : 'bg-slate-300'"></span>
                                    <span class="font-medium">{{ p.name }}</span>
                                    <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-500 dark:bg-slate-700">{{ p.driver_label }}</span>
                                </div>
                                <div class="mt-0.5 truncate text-xs text-slate-400">{{ p.default_model }} · {{ t('aiGateway.dashboard.modelCount', { count: p.models_count }) }}</div>
                            </div>
                            <span v-if="p.last_test_status" class="text-[11px]" :class="p.last_test_status === 'ok' ? 'text-emerald-600' : 'text-red-500'">
                                {{ p.last_test_status === 'ok' ? t('aiGateway.dashboard.online') : t('aiGateway.dashboard.failed') }}
                            </span>
                        </li>
                    </ul>
                    <Link :href="panelRoute('ai-gateway.providers.index')" class="mt-3 inline-block text-xs text-blue-600 hover:underline">{{ t('aiGateway.dashboard.manageProviders') }} →</Link>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <div class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                    <h2 class="mb-3 text-sm font-semibold">{{ t('aiGateway.dashboard.byProvider7d') }}</h2>
                    <div v-if="!perProviderRequests.length" class="text-sm text-slate-500">{{ t('aiGateway.dashboard.noUsage') }}</div>
                    <ul v-else class="space-y-2">
                        <li v-for="row in perProviderRequests" :key="row.name" class="flex items-center justify-between text-sm">
                            <span>{{ row.name }}</span>
                            <span class="text-slate-500">{{ t('aiGateway.dashboard.req', { count: fmt(row.requests) }) }}</span>
                        </li>
                    </ul>
                </div>

                <div class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                    <div class="mb-3 flex items-center justify-between">
                        <h2 class="text-sm font-semibold">{{ t('aiGateway.dashboard.recent') }}</h2>
                        <Link :href="panelRoute('ai-gateway.logs.index')" class="text-xs text-blue-600 hover:underline">{{ t('aiGateway.dashboard.viewLogs') }} →</Link>
                    </div>
                    <div v-if="!recentLogs.length" class="text-sm text-slate-500">{{ t('aiGateway.dashboard.noRecent') }}</div>
                    <ul v-else class="space-y-2">
                        <li v-for="log in recentLogs" :key="log.id" class="flex items-center justify-between rounded-md border border-slate-100 px-3 py-2 text-sm dark:border-slate-700">
                            <div class="min-w-0">
                                <span class="font-medium">{{ log.provider || '-' }} · {{ log.model }}</span>
                                <div class="text-xs text-slate-400">{{ log.created_at }} · {{ log.latency_ms }}ms</div>
                            </div>
                            <span class="rounded px-2 py-0.5 text-[11px]" :class="statusBadge(log.status)">{{ statusLabel(log.status) }}</span>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Usage explorer -->
            <div class="space-y-4 border-t border-slate-200 pt-6 dark:border-slate-700">
                <h2 class="text-sm font-semibold">{{ t('aiGateway.dashboard.explorer') }}</h2>

                <div class="flex flex-wrap items-end gap-3 rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                    <div>
                        <label class="mb-1 block text-xs font-medium">{{ t('aiGateway.dashboard.from') }}</label>
                        <input v-model="filters.from" type="date" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-600 dark:bg-slate-900" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium">{{ t('aiGateway.dashboard.to') }}</label>
                        <input v-model="filters.to" type="date" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-600 dark:bg-slate-900" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium">{{ t('aiGateway.dashboard.model') }}</label>
                        <select v-model="filters.model" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-600 dark:bg-slate-900">
                            <option value="">{{ t('aiGateway.logs.all') }}</option>
                            <option v-for="m in usage.models" :key="m.id" :value="m.id">{{ m.display_name || m.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium">{{ t('aiGateway.dashboard.provider') }}</label>
                        <select v-model="filters.provider" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-600 dark:bg-slate-900">
                            <option value="">{{ t('aiGateway.logs.all') }}</option>
                            <option v-for="p in usage.providers" :key="p.id" :value="p.id">{{ p.name }}</option>
                        </select>
                    </div>
                    <button @click="applyUsageFilters" class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700">{{ t('aiGateway.logs.apply') }}</button>
                </div>

                <div class="grid grid-cols-2 gap-4 md:grid-cols-3">
                    <div class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                        <div class="text-xs text-slate-500">{{ t('aiGateway.dashboard.requests') }}</div>
                        <div class="mt-1 text-2xl font-bold">{{ fmt(usage.totals?.requests) }}</div>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                        <div class="text-xs text-slate-500">{{ t('aiGateway.dashboard.tokens') }}</div>
                        <div class="mt-1 text-2xl font-bold">{{ fmt((usage.totals?.input_tokens || 0) + (usage.totals?.output_tokens || 0)) }}</div>
                        <div class="text-xs text-slate-400">{{ t('aiGateway.dashboard.inOut', { input: fmt(usage.totals?.input_tokens), output: fmt(usage.totals?.output_tokens) }) }}</div>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                        <div class="text-xs text-slate-500">{{ t('aiGateway.dashboard.failures') }}</div>
                        <div class="mt-1 text-2xl font-bold">{{ fmt(usage.totals?.failures) }}</div>
                    </div>
                </div>

                <div class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                    <h3 class="mb-3 text-sm font-semibold">{{ t('aiGateway.dashboard.daily') }}</h3>
                    <div class="flex h-40 items-end gap-1">
                        <div v-for="d in usage.daily" :key="d.date" class="flex flex-1 flex-col items-center gap-1">
                            <span class="text-[10px] text-slate-400">{{ fmt(d.tokens) }}</span>
                            <div class="w-full rounded-t bg-blue-500" :style="{ height: `${Math.max(2, (d.tokens / maxDaily) * 100)}px` }"></div>
                            <span class="text-[10px] text-slate-500">{{ d.date.slice(5) }}</span>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                    <div class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                        <h3 class="mb-2 text-sm font-semibold">{{ t('aiGateway.dashboard.byModel') }}</h3>
                        <div v-if="!usage.byModel?.length" class="text-sm text-slate-500">{{ t('aiGateway.dashboard.noUsageRange') }}</div>
                        <table v-else class="w-full text-sm">
                            <thead><tr class="text-left text-xs text-slate-500"><th class="py-1">{{ t('aiGateway.dashboard.model') }}</th><th class="py-1">{{ t('aiGateway.dashboard.requests') }}</th><th class="py-1">{{ t('aiGateway.dashboard.tokens') }}</th></tr></thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                                <tr v-for="row in usage.byModel" :key="row.model_id"><td class="py-1.5">{{ row.model_name }}</td><td class="py-1.5">{{ row.requests }}</td><td class="py-1.5">{{ fmt(row.tokens) }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                        <h3 class="mb-2 text-sm font-semibold">{{ t('aiGateway.dashboard.byProvider') }}</h3>
                        <div v-if="!usage.byProvider?.length" class="text-sm text-slate-500">{{ t('aiGateway.dashboard.noUsageRange') }}</div>
                        <table v-else class="w-full text-sm">
                            <thead><tr class="text-left text-xs text-slate-500"><th class="py-1">{{ t('aiGateway.dashboard.provider') }}</th><th class="py-1">{{ t('aiGateway.dashboard.requests') }}</th><th class="py-1">{{ t('aiGateway.dashboard.tokens') }}</th></tr></thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                                <tr v-for="row in usage.byProvider" :key="row.provider_id"><td class="py-1.5">{{ row.provider_name }}</td><td class="py-1.5">{{ row.requests }}</td><td class="py-1.5">{{ fmt(row.tokens) }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
