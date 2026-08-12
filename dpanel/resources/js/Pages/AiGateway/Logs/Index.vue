<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { router } from '@inertiajs/vue3';

const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

const props = defineProps({
    logs: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    providers: { type: Array, default: () => [] },
});

const clearForm = useForm({});
const clear = () => {
    if (!confirm('Delete matching logs?')) return;
    clearForm.delete(panelRoute('ai-gateway.logs.clear'), { status: props.filters.status });
};

const apply = () => {
    router.get(panelRoute('ai-gateway.logs.index'), {
        status: props.filters.status,
        provider: props.filters.provider || '',
        q: props.filters.q || '',
    }, { preserveState: true });
};

const statusBadge = (s) => ({
    success: 'bg-emerald-100 text-emerald-700',
    error: 'bg-red-100 text-red-700',
    timeout: 'bg-amber-100 text-amber-700',
    cancelled: 'bg-slate-200 text-slate-600',
}[s] || 'bg-slate-200 text-slate-600');
</script>

<template>
    <Head title="Gateway Logs" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Gateway Logs</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Audit trail of every gateway request.</p>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ page.props.flash.success }}</div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ page.props.flash.error }}</div>

            <div class="flex flex-wrap items-end gap-3 rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                <div>
                    <label class="mb-1 block text-xs font-medium">Status</label>
                    <select v-model="filters.status" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-600 dark:bg-slate-900">
                        <option value="all">All</option>
                        <option value="success">Success</option>
                        <option value="error">Error</option>
                        <option value="timeout">Timeout</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium">Provider</label>
                    <select v-model="filters.provider" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-600 dark:bg-slate-900">
                        <option value="">All</option>
                        <option v-for="p in providers" :key="p.id" :value="p.id">{{ p.name }}</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium">Search</label>
                    <input v-model="filters.q" type="text" @keyup.enter="apply" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-600 dark:bg-slate-900" placeholder="model, trace id…" />
                </div>
                <button @click="apply" class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700">Apply</button>
                <button @click="clear" class="rounded-md border border-red-300 px-4 py-2 text-sm text-red-600 hover:bg-red-50 dark:border-red-700">Clear</button>
            </div>

            <div class="overflow-hidden rounded-lg border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800">
                <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-700">
                    <thead class="bg-slate-50 text-left text-xs text-slate-500 dark:bg-slate-800">
                        <tr>
                            <th class="px-4 py-3 font-medium">Time</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                            <th class="px-4 py-3 font-medium">Provider</th>
                            <th class="px-4 py-3 font-medium">Model</th>
                            <th class="px-4 py-3 font-medium">Operation</th>
                            <th class="px-4 py-3 font-medium">Tokens</th>
                            <th class="px-4 py-3 font-medium">Cost</th>
                            <th class="px-4 py-3 font-medium">Latency</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                        <tr v-for="l in logs.data" :key="l.id" class="hover:bg-slate-50 dark:hover:bg-slate-700/40">
                            <td class="px-4 py-3 text-slate-500">
                                <Link :href="panelRoute('ai-gateway.logs.show', { log: l.id })" class="text-blue-600 hover:underline">{{ l.created_at }}</Link>
                            </td>
                            <td class="px-4 py-3"><span class="rounded px-2 py-0.5 text-xs" :class="statusBadge(l.status)">{{ l.status }}</span></td>
                            <td class="px-4 py-3 text-slate-500">{{ l.provider_name || '—' }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ l.model }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ l.operation }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ (l.input_tokens + l.output_tokens).toLocaleString() }}</td>
                            <td class="px-4 py-3 text-slate-500">${{ Number(l.cost || 0).toFixed(4) }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ l.latency_ms }}ms</td>
                        </tr>
                    </tbody>
                </table>
                <div v-if="logs.links" class="flex flex-wrap justify-end gap-1 border-t border-slate-200 p-3 dark:border-slate-700">
                    <template v-for="(link, i) in logs.links" :key="i">
                        <a v-if="link.url" v-html="link.label" :href="link.url" class="rounded px-2 py-1 text-xs hover:bg-slate-100 dark:hover:bg-slate-700"></a>
                        <span v-else v-html="link.label" class="rounded px-2 py-1 text-xs text-slate-400"></span>
                    </template>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>