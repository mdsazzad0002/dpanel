<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';

const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

const props = defineProps({
    rules: { type: Array, default: () => [] },
    providers: { type: Array, default: () => [] },
    models: { type: Array, default: () => [] },
});

const addForm = useForm({ name: '', match_type: 'model', match_value: '', provider_id: '', model_id: '', priority: 0, is_active: true });
const delForm = useForm({});

const add = () => addForm.post(panelRoute('ai-gateway.routing.store'));
const remove = (r) => { if (confirm(`Remove rule "${r.name}"?`)) delForm.delete(panelRoute('ai-gateway.routing.destroy', { rule: r.id })); };

const MATCH_LABELS = { model: 'Model', agent: 'Agent', task: 'Task', always: 'Always' };
</script>

<template>
    <Head title="Routing Rules" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Routing Rules</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Control which provider/model serves each request.</p>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ page.props.flash.success }}</div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ page.props.flash.error }}</div>

            <form @submit.prevent="add" class="grid grid-cols-1 gap-3 rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <label class="mb-1 block text-xs font-medium">Rule Name</label>
                    <input v-model="addForm.name" type="text" class="w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-600 dark:bg-slate-900" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium">Match Type</label>
                    <select v-model="addForm.match_type" class="w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-600 dark:bg-slate-900">
                        <option v-for="(label, val) in MATCH_LABELS" :key="val" :value="val">{{ label }}</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium">Match Value <span class="text-slate-400">(glob)</span></label>
                    <input v-model="addForm.match_value" type="text" class="w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-600 dark:bg-slate-900" :disabled="addForm.match_type === 'always'" placeholder="gpt-*" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium">Provider</label>
                    <select v-model="addForm.provider_id" class="w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-600 dark:bg-slate-900">
                        <option value="">Auto (by model)</option>
                        <option v-for="p in providers" :key="p.id" :value="p.id">{{ p.name }}</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium">Model</label>
                    <select v-model="addForm.model_id" class="w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-600 dark:bg-slate-900">
                        <option value="">Provider default</option>
                        <option v-for="m in models" :key="m.id" :value="m.id">{{ m.provider_name }} · {{ m.name }}</option>
                    </select>
                </div>
                <div class="flex items-end gap-2">
                    <input v-model.number="addForm.priority" type="number" class="w-24 rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-600 dark:bg-slate-900" placeholder="Priority" />
                    <button type="submit" :disabled="addForm.processing" class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50">Add Rule</button>
                </div>
            </form>

            <div class="overflow-hidden rounded-lg border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800">
                <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-700">
                    <thead class="bg-slate-50 text-left text-xs text-slate-500 dark:bg-slate-800">
                        <tr>
                            <th class="px-4 py-3 font-medium">Priority</th>
                            <th class="px-4 py-3 font-medium">Name</th>
                            <th class="px-4 py-3 font-medium">Match</th>
                            <th class="px-4 py-3 font-medium">Provider</th>
                            <th class="px-4 py-3 font-medium">Model</th>
                            <th class="px-4 py-3 font-medium">Active</th>
                            <th class="px-4 py-3 text-right font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                        <tr v-for="r in rules" :key="r.id" class="hover:bg-slate-50 dark:hover:bg-slate-700/40">
                            <td class="px-4 py-3 text-slate-500">{{ r.priority }}</td>
                            <td class="px-4 py-3 font-medium">{{ r.name }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ MATCH_LABELS[r.match_type] }}: <span class="font-mono">{{ r.match_value || '*' }}</span></td>
                            <td class="px-4 py-3 text-slate-500">{{ r.provider_name || 'Auto' }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ r.model_name || 'Default' }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded px-2 py-0.5 text-xs" :class="r.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600'">{{ r.is_active ? 'On' : 'Off' }}</span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <button @click="remove(r)" class="rounded border border-red-300 px-2 py-1 text-xs text-red-600 hover:bg-red-50 dark:border-red-700">Delete</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AuthenticatedLayout>
</template>