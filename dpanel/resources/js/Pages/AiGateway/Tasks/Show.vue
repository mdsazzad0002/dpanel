<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';

const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

const props = defineProps({
    task: { type: Object, default: () => ({}) },
});

const runForm = useForm({});
const run = () => runForm.post(panelRoute('ai-gateway.tasks.run', { task: props.task.id }));

const statusBadge = (s) => ({
    queued: 'bg-slate-200 text-slate-600',
    running: 'bg-blue-100 text-blue-700',
    succeeded: 'bg-emerald-100 text-emerald-700',
    failed: 'bg-red-100 text-red-700',
    cancelled: 'bg-amber-100 text-amber-700',
}[s] || 'bg-slate-200 text-slate-600');
</script>

<template>
    <Head :title="task.title || 'Task'" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">{{ task.title }}</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Task #{{ task.id }} · {{ task.type }}</p>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ page.props.flash.success }}</div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ page.props.flash.error }}</div>

            <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                <div class="rounded-lg border border-slate-200 bg-white p-4 text-sm dark:border-slate-700 dark:bg-slate-800">
                    <div class="text-xs text-slate-500">Status</div>
                    <span class="mt-1 inline-block rounded px-2 py-0.5 text-xs" :class="statusBadge(task.status)">{{ task.status }}</span>
                </div>
                <div class="rounded-lg border border-slate-200 bg-white p-4 text-sm dark:border-slate-700 dark:bg-slate-800">
                    <div class="text-xs text-slate-500">Tokens</div>
                    <div class="mt-1 font-semibold">{{ ((task.input_tokens || 0) + (task.output_tokens || 0)).toLocaleString() }}</div>
                    <div class="text-xs text-slate-400">{{ task.input_tokens || 0 }} in / {{ task.output_tokens || 0 }} out</div>
                </div>
                <div class="rounded-lg border border-slate-200 bg-white p-4 text-sm dark:border-slate-700 dark:bg-slate-800">
                    <div class="text-xs text-slate-500">Cost</div>
                    <div class="mt-1 font-semibold">${{ Number(task.cost || 0).toFixed(4) }}</div>
                </div>
                <div class="rounded-lg border border-slate-200 bg-white p-4 text-sm dark:border-slate-700 dark:bg-slate-800">
                    <div class="text-xs text-slate-500">Latency</div>
                    <div class="mt-1 font-semibold">{{ task.latency_ms }}ms</div>
                </div>
            </div>

            <div class="flex flex-wrap gap-1 rounded-lg border border-slate-200 bg-white p-4 text-sm dark:border-slate-700 dark:bg-slate-800">
                <span class="rounded bg-slate-100 px-2 py-0.5 text-xs dark:bg-slate-700">Agent: {{ task.agent_name || 'N/A' }}</span>
                <span class="rounded bg-slate-100 px-2 py-0.5 text-xs dark:bg-slate-700">Provider: {{ task.provider_name || 'Auto' }}</span>
                <span class="rounded bg-slate-100 px-2 py-0.5 text-xs dark:bg-slate-700">Model: {{ task.model_name || 'Auto' }}</span>
                <span class="rounded bg-slate-100 px-2 py-0.5 text-xs dark:bg-slate-700">Created by: {{ task.created_by || '—' }}</span>
                <span class="rounded bg-slate-100 px-2 py-0.5 text-xs dark:bg-slate-700">Created: {{ task.created_at }}</span>
            </div>

            <div v-if="['queued', 'failed', 'cancelled'].includes(task.status)" class="flex gap-2">
                <button @click="run" :disabled="runForm.processing" class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50">{{ runForm.processing ? 'Running…' : 'Run Task' }}</button>
                <Link :href="panelRoute('ai-gateway.tasks.index')" class="rounded-md border border-slate-300 px-4 py-2 text-sm hover:bg-slate-100 dark:border-slate-600">Back</Link>
            </div>

            <div v-if="task.error" class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700 dark:border-red-700 dark:bg-red-900/40">
                <strong>Error:</strong> {{ task.error }}
            </div>

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <div class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                    <h2 class="mb-2 text-sm font-semibold">Request Payload</h2>
                    <pre class="max-h-96 overflow-auto whitespace-pre-wrap rounded bg-slate-50 p-3 text-xs dark:bg-slate-900">{{ JSON.stringify(task.payload || {}, null, 2) }}</pre>
                </div>
                <div class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                    <h2 class="mb-2 text-sm font-semibold">Response</h2>
                    <pre class="max-h-96 overflow-auto whitespace-pre-wrap rounded bg-slate-50 p-3 text-xs dark:bg-slate-900">{{ task.response || '(no response yet)' }}</pre>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>