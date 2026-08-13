<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';

const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

const props = defineProps({
    log: { type: Object, default: () => ({}) },
});

const fmtCost = (c) => `$${Number(c || 0).toFixed(4)}`;
</script>

<template>
    <Head title="Log Detail" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Gateway Log #{{ log.id }}</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ log.created_at }} · {{ log.status }}</p>
            </div>
        </template>

        <div class="space-y-4">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-lg border border-slate-200 bg-white p-4 text-sm dark:border-slate-700 dark:bg-slate-800">
                    <div class="text-xs text-slate-500">Trace ID</div>
                    <div class="mt-1 break-all font-mono text-xs">{{ log.trace_id }}</div>
                </div>
                <div class="rounded-lg border border-slate-200 bg-white p-4 text-sm dark:border-slate-700 dark:bg-slate-800">
                    <div class="text-xs text-slate-500">Provider / Model</div>
                    <div class="mt-1">{{ log.provider_name }} · {{ log.model }}</div>
                </div>
                <div class="rounded-lg border border-slate-200 bg-white p-4 text-sm dark:border-slate-700 dark:bg-slate-800">
                    <div class="text-xs text-slate-500">Tokens</div>
                    <div class="mt-1">{{ (log.input_tokens + log.output_tokens).toLocaleString() }} (in {{ log.input_tokens }} / out {{ log.output_tokens }})</div>
                </div>
                <div class="rounded-lg border border-slate-200 bg-white p-4 text-sm dark:border-slate-700 dark:bg-slate-800">
                    <div class="text-xs text-slate-500">Cost / Latency</div>
                    <div class="mt-1">{{ fmtCost(log.cost) }} · {{ log.latency_ms }}ms</div>
                </div>
            </div>

            <div class="flex flex-wrap gap-1 rounded-lg border border-slate-200 bg-white p-4 text-sm dark:border-slate-700 dark:bg-slate-800">
                <span class="rounded bg-slate-100 px-2 py-0.5 text-xs dark:bg-slate-700">Operation: {{ log.operation }}</span>
                <span class="rounded bg-slate-100 px-2 py-0.5 text-xs dark:bg-slate-700">Channel: {{ log.channel }}</span>
            </div>

            <div v-if="log.error_message" class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700 dark:border-red-700 dark:bg-red-900/40">
                <strong>Error:</strong> {{ log.error_message }}
            </div>

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <div class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                    <h2 class="mb-2 text-sm font-semibold">Request Payload</h2>
                    <pre class="max-h-96 overflow-auto whitespace-pre-wrap rounded bg-slate-50 p-3 text-xs dark:bg-slate-900">{{ log.request_payload || '(not logged)' }}</pre>
                </div>
                <div class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                    <h2 class="mb-2 text-sm font-semibold">Response</h2>
                    <pre class="max-h-96 overflow-auto whitespace-pre-wrap rounded bg-slate-50 p-3 text-xs dark:bg-slate-900">{{ log.response_snippet || '(not logged)' }}</pre>
                </div>
            </div>

            <Link :href="panelRoute('ai-gateway.logs.index')" class="inline-block rounded-md border border-slate-300 px-4 py-2 text-sm hover:bg-slate-100 dark:border-slate-600 dark:hover:bg-slate-700">Back to logs</Link>
        </div>
    </AuthenticatedLayout>
</template>