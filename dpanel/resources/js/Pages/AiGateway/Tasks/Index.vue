<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';

const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

const props = defineProps({
    tasks: { type: Object, default: () => ({}) },
    agents: { type: Array, default: () => [] },
    providers: { type: Array, default: () => [] },
    models: { type: Array, default: () => [] },
});

const runForm = useForm({});
const delForm = useForm({});
const run = (t) => { if (t.status === 'running') return; runForm.post(panelRoute('ai-gateway.tasks.run', { task: t.id })); };
const remove = (t) => { if (confirm(`Delete task "${t.title}"?`)) delForm.delete(panelRoute('ai-gateway.tasks.destroy', { task: t.id })); };

const statusBadge = (s) => ({
    queued: 'bg-slate-200 text-slate-600',
    running: 'bg-blue-100 text-blue-700',
    succeeded: 'bg-emerald-100 text-emerald-700',
    failed: 'bg-red-100 text-red-700',
    cancelled: 'bg-amber-100 text-amber-700',
}[s] || 'bg-slate-200 text-slate-600');
</script>

<template>
    <Head title="AI Tasks" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-lg font-semibold">AI Tasks</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Discrete AI completion runs with token and cost tracking.</p>
                </div>
                <Link :href="panelRoute('ai-gateway.tasks.create')" class="rounded-md bg-blue-600 px-3 py-2 text-sm text-white hover:bg-blue-700">+ New Task</Link>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ page.props.flash.success }}</div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ page.props.flash.error }}</div>

            <div class="overflow-hidden rounded-lg border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800">
                <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-700">
                    <thead class="bg-slate-50 text-left text-xs text-slate-500 dark:bg-slate-800">
                        <tr>
                            <th class="px-4 py-3 font-medium">Title</th>
                            <th class="px-4 py-3 font-medium">Type</th>
                            <th class="px-4 py-3 font-medium">Agent</th>
                            <th class="px-4 py-3 font-medium">Provider/Model</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                            <th class="px-4 py-3 font-medium">Tokens</th>
                            <th class="px-4 py-3 font-medium">Cost</th>
                            <th class="px-4 py-3 font-medium">Created</th>
                            <th class="px-4 py-3 text-right font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                        <tr v-for="t in tasks.data" :key="t.id" class="hover:bg-slate-50 dark:hover:bg-slate-700/40">
                            <td class="px-4 py-3">
                                <Link :href="panelRoute('ai-gateway.tasks.show', { task: t.id })" class="font-medium text-blue-600 hover:underline">{{ t.title }}</Link>
                            </td>
                            <td class="px-4 py-3 text-slate-500">{{ t.type }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ t.agent_name || '—' }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ t.provider_name || 'Auto' }} / {{ t.model_name || 'Auto' }}</td>
                            <td class="px-4 py-3"><span class="rounded px-2 py-0.5 text-xs" :class="statusBadge(t.status)">{{ t.status }}</span></td>
                            <td class="px-4 py-3 text-slate-500">{{ (t.input_tokens + t.output_tokens).toLocaleString() }}</td>
                            <td class="px-4 py-3 text-slate-500">${{ Number(t.cost || 0).toFixed(4) }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ t.created_at }}</td>
                            <td class="space-x-1 px-4 py-3 text-right">
                                <button v-if="['queued', 'failed', 'cancelled'].includes(t.status)" @click="run(t)" class="rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 dark:border-slate-600">Run</button>
                                <button @click="remove(t)" class="rounded border border-red-300 px-2 py-1 text-xs text-red-600 hover:bg-red-50 dark:border-red-700">Delete</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div v-if="tasks.links" class="flex justify-end gap-1 border-t border-slate-200 p-3 dark:border-slate-700">
                    <span v-for="(link, i) in tasks.links" :key="i">
                        <a v-if="link.url" v-html="link.label" :href="link.url" class="rounded px-2 py-1 text-xs hover:bg-slate-100 dark:hover:bg-slate-700"></a>
                        <span v-else v-html="link.label" class="rounded px-2 py-1 text-xs text-slate-400"></span>
                    </span>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>