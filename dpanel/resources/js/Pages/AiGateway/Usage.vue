<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

const props = defineProps({
    filters: { type: Object, default: () => ({}) },
    totals: { type: Object, default: () => ({}) },
    daily: { type: Array, default: () => [] },
    byModel: { type: Array, default: () => [] },
    byProvider: { type: Array, default: () => [] },
    models: { type: Array, default: () => [] },
    providers: { type: Array, default: () => [] },
});

const apply = () => {
    router.get(panelRoute('ai-gateway.usage.index'), {
        from: props.filters.from,
        to: props.filters.to,
        model: props.filters.model || '',
        provider: props.filters.provider || '',
    }, { preserveState: true });
};

const maxDaily = computed(() => Math.max(1, ...props.daily.map((d) => d.tokens)));
const fmt = (n) => Number(n || 0).toLocaleString();
const fmtCost = (c) => `$${(Number(c) || 0).toFixed(4)}`;
</script>

<template>
    <Head title="Usage & Cost" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Usage & Cost</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Token consumption and spend across the gateway.</p>
            </div>
        </template>

        <div class="space-y-4">
            <div class="flex flex-wrap items-end gap-3 rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                <div>
                    <label class="mb-1 block text-xs font-medium">From</label>
                    <input v-model="filters.from" type="date" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-600 dark:bg-slate-900" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium">To</label>
                    <input v-model="filters.to" type="date" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-600 dark:bg-slate-900" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium">Model</label>
                    <select v-model="filters.model" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-600 dark:bg-slate-900">
                        <option value="">All</option>
                        <option v-for="m in models" :key="m.id" :value="m.id">{{ m.display_name || m.name }}</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium">Provider</label>
                    <select v-model="filters.provider" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-600 dark:bg-slate-900">
                        <option value="">All</option>
                        <option v-for="p in providers" :key="p.id" :value="p.id">{{ p.name }}</option>
                    </select>
                </div>
                <button @click="apply" class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700">Apply</button>
            </div>

            <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                <div class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                    <div class="text-xs text-slate-500">Requests</div>
                    <div class="mt-1 text-2xl font-bold">{{ fmt(totals.requests) }}</div>
                </div>
                <div class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                    <div class="text-xs text-slate-500">Tokens</div>
                    <div class="mt-1 text-2xl font-bold">{{ fmt((totals.input_tokens || 0) + (totals.output_tokens || 0)) }}</div>
                    <div class="text-xs text-slate-400">{{ fmt(totals.input_tokens) }} in / {{ fmt(totals.output_tokens) }} out</div>
                </div>
                <div class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                    <div class="text-xs text-slate-500">Cost</div>
                    <div class="mt-1 text-2xl font-bold">{{ fmtCost(totals.cost) }}</div>
                </div>
                <div class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                    <div class="text-xs text-slate-500">Failures</div>
                    <div class="mt-1 text-2xl font-bold">{{ fmt(totals.failures) }}</div>
                </div>
            </div>

            <div class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                <h2 class="mb-3 text-sm font-semibold">Daily usage</h2>
                <div class="flex h-40 items-end gap-1">
                    <div v-for="d in daily" :key="d.date" class="flex flex-1 flex-col items-center gap-1">
                        <span class="text-[10px] text-slate-400">{{ fmt(d.tokens) }}</span>
                        <div class="w-full rounded-t bg-blue-500" :style="{ height: `${Math.max(2, (d.tokens / maxDaily) * 100)}px` }"></div>
                        <span class="text-[10px] text-slate-500">{{ d.date.slice(5) }}</span>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <div class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                    <h2 class="mb-2 text-sm font-semibold">By model</h2>
                    <div v-if="!byModel.length" class="text-sm text-slate-500">No usage in range.</div>
                    <table v-else class="w-full text-sm">
                        <thead><tr class="text-left text-xs text-slate-500"><th class="py-1">Model</th><th class="py-1">Requests</th><th class="py-1">Tokens</th><th class="py-1">Cost</th></tr></thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                            <tr v-for="row in byModel" :key="row.model_id"><td class="py-1.5">{{ row.model_name }}</td><td class="py-1.5">{{ row.requests }}</td><td class="py-1.5">{{ fmt(row.tokens) }}</td><td class="py-1.5">{{ fmtCost(row.cost) }}</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                    <h2 class="mb-2 text-sm font-semibold">By provider</h2>
                    <div v-if="!byProvider.length" class="text-sm text-slate-500">No usage in range.</div>
                    <table v-else class="w-full text-sm">
                        <thead><tr class="text-left text-xs text-slate-500"><th class="py-1">Provider</th><th class="py-1">Requests</th><th class="py-1">Tokens</th><th class="py-1">Cost</th></tr></thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                            <tr v-for="row in byProvider" :key="row.provider_id"><td class="py-1.5">{{ row.provider_name }}</td><td class="py-1.5">{{ row.requests }}</td><td class="py-1.5">{{ fmt(row.tokens) }}</td><td class="py-1.5">{{ fmtCost(row.cost) }}</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
