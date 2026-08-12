<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';

const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

const props = defineProps({
    models: { type: Array, default: () => [] },
    providers: { type: Array, default: () => [] },
});

const addForm = useForm({ provider_id: '', name: '', display_name: '', context_window: 0, max_output_tokens: 0, input_price: 0, output_price: 0, is_active: true });
const statusForm = useForm({});
const delForm = useForm({});

const add = () => addForm.post(panelRoute('ai-gateway.models.store'));
const setDefault = (m) => statusForm.post(panelRoute('ai-gateway.models.default', { model: m.id }));
const toggle = (m) => statusForm.patch(panelRoute('ai-gateway.models.update', { model: m.id }), { is_active: !m.is_active });
const remove = (m) => { if (confirm(`Remove model "${m.name}"?`)) delForm.delete(panelRoute('ai-gateway.models.destroy', { model: m.id })); };
const fmtPrice = (p) => `$${Number(p || 0).toFixed(2)}/1M`;
</script>

<template>

    <Head title="AI Models" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">AI Models</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Model catalog with context limits and pricing per
                    provider.</p>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="page.props.flash?.success"
                class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{
                    page.props.flash.success }}</div>
            <div v-if="page.props.flash?.error"
                class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{
                    page.props.flash.error }}
            </div>

            <form @submit.prevent="add"
                class="grid grid-cols-1 gap-3 rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <label class="mb-1 block text-xs font-medium">Provider</label>
                    <select v-model="addForm.provider_id"
                        class="w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-600 dark:bg-slate-900">
                        <option value="">Select…</option>
                        <option v-for="p in providers" :key="p.id" :value="p.id">{{ p.name }}</option>
                    </select>
                    <div v-if="addForm.errors.provider_id" class="text-xs text-red-600">{{ addForm.errors.provider_id }}
                    </div>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium">Model ID</label>
                    <input v-model="addForm.name" type="text"
                        class="w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-600 dark:bg-slate-900"
                        placeholder="gpt-4o" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium">Display Name</label>
                    <input v-model="addForm.display_name" type="text"
                        class="w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-600 dark:bg-slate-900" />
                </div>
                <div class="flex items-end">
                    <button type="submit" :disabled="addForm.processing"
                        class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50">Add
                        Model</button>
                </div>
            </form>

            <div
                class="overflow-hidden rounded-lg border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800">
                    <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-700">
                        <thead class="bg-slate-50 text-left text-xs text-slate-500 dark:bg-slate-800">
                            <tr>
                                <th class="px-4 py-3 font-medium">Provider</th>
                                <th class="px-4 py-3 font-medium">Model</th>
                                <th class="px-4 py-3 font-medium">Context</th>
                                <th class="px-4 py-3 font-medium">Max Output</th>
                                <th class="px-4 py-3 font-medium">Input $</th>
                                <th class="px-4 py-3 font-medium">Output $</th>
                                <th class="px-4 py-3 font-medium">Capabilities</th>
                                <th class="px-4 py-3 font-medium">Default</th>
                                <th class="px-4 py-3 text-right font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                            <tr v-for="m in models" :key="m.id" class="hover:bg-slate-50 dark:hover:bg-slate-700/40">
                                <td class="px-4 py-3 text-slate-500">{{ m.provider_name }}</td>
                                <td class="px-4 py-3 font-medium">{{ m.display_name || m.name }} <span
                                        class="text-xs text-slate-400">{{ m.name }}</span></td>
                                <td class="px-4 py-3 text-slate-500">{{ m.context_window ?
                                    m.context_window.toLocaleString() :
                                    '—' }}</td>
                                <td class="px-4 py-3 text-slate-500">{{ m.max_output_tokens || '—' }}</td>
                                <td class="px-4 py-3 text-slate-500">{{ fmtPrice(m.input_price) }}</td>
                                <td class="px-4 py-3 text-slate-500">{{ fmtPrice(m.output_price) }}</td>
                                <td class="px-4 py-3 text-slate-500">{{ (m.capabilities || []).join(', ') || '—' }}</td>
                                <td class="px-4 py-3">
                                    <button v-if="m.is_default"
                                        class="rounded bg-emerald-100 px-2 py-0.5 text-xs text-emerald-700">Default</button>
                                    <button v-else @click="setDefault(m)"
                                        class="rounded border border-slate-300 px-2 py-0.5 text-xs hover:bg-slate-100 dark:border-slate-600">Set</button>
                                </td>
                                <td class="space-x-1 px-4 py-3 text-right">
                                    <button @click="toggle(m)"
                                        class="rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 dark:border-slate-600">{{
                                            m.is_active ? 'Active' : 'Inactive' }}</button>
                                    <button @click="remove(m)"
                                        class="rounded border border-red-300 px-2 py-1 text-xs text-red-600 hover:bg-red-50 dark:border-red-700">Delete</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
        </div>
    </AuthenticatedLayout>
</template>

