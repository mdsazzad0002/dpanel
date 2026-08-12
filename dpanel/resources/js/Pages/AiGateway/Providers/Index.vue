<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';

const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

const props = defineProps({
    providers: { type: Array, default: () => [] },
    drivers: { type: Array, default: () => [] },
    defaultModelSeed: { type: Object, default: () => ({}) },
});

const toggleForm = useForm({});
const deleteForm = useForm({});
const testForm = useForm({});

const driverLabel = (driver) => props.drivers.find((d) => d.driver === driver)?.label || driver;

const toggle = (p) => {
    toggleForm.patch(panelRoute('ai-gateway.providers.toggle', { provider: p.id }), {
        is_active: !p.is_active,
    });
};

const remove = (p) => {
    if (!confirm(`Delete provider "${p.name}"? Its models will also be removed.`)) return;
    deleteForm.delete(panelRoute('ai-gateway.providers.destroy', { provider: p.id }));
};

const test = (p) => {
    testForm.post(panelRoute('ai-gateway.providers.test', { provider: p.id }));
};
</script>

<template>
    <Head title="AI Providers" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-lg font-semibold">AI Providers</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Connect Claude, Codex, OpenAI, Gemini and local model endpoints.</p>
                </div>
                <Link :href="panelRoute('ai-gateway.providers.create')" class="rounded-md bg-blue-600 px-3 py-2 text-sm text-white hover:bg-blue-700">+ Add Provider</Link>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ page.props.flash.success }}</div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ page.props.flash.error }}</div>

            <div v-if="!providers.length" class="rounded-lg border border-dashed border-slate-300 p-10 text-center text-sm text-slate-500 dark:border-slate-700">
                No providers configured yet.
                <Link :href="panelRoute('ai-gateway.providers.create')" class="ml-1 text-blue-600 hover:underline">Add your first provider</Link>.
            </div>

            <div class="overflow-hidden rounded-lg border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800">
                <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-700">
                    <thead class="bg-slate-50 text-left text-xs text-slate-500 dark:bg-slate-800">
                        <tr>
                            <th class="px-4 py-3 font-medium">Provider</th>
                            <th class="px-4 py-3 font-medium">Driver</th>
                            <th class="px-4 py-3 font-medium">Default Model</th>
                            <th class="px-4 py-3 font-medium">Models</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                            <th class="px-4 py-3 font-medium">Health</th>
                            <th class="px-4 py-3 text-right font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                        <tr v-for="p in providers" :key="p.id" class="hover:bg-slate-50 dark:hover:bg-slate-700/40">
                            <td class="px-4 py-3 font-medium">{{ p.name }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ driverLabel(p.driver) }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ p.default_model || '—' }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ p.models_count }}</td>
                            <td class="px-4 py-3">
                                <button @click="toggle(p)" class="rounded px-2 py-0.5 text-xs font-medium" :class="p.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600'">
                                    {{ p.is_active ? 'Active' : 'Inactive' }}
                                </button>
                            </td>
                            <td class="px-4 py-3">
                                <span v-if="p.last_test_status" class="text-xs" :class="p.last_test_status === 'ok' ? 'text-emerald-600' : 'text-red-500'">{{ p.last_test_status }}</span>
                                <span v-else class="text-xs text-slate-400">untested</span>
                            </td>
                            <td class="space-x-1 px-4 py-3 text-right">
                                <Link :href="panelRoute('ai-gateway.providers.edit', { provider: p.id })" class="rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 dark:border-slate-600 dark:hover:bg-slate-700">Edit</Link>
                                <button @click="test(p)" class="rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 dark:border-slate-600 dark:hover:bg-slate-700">Test</button>
                                <button @click="remove(p)" class="rounded border border-red-300 px-2 py-1 text-xs text-red-600 hover:bg-red-50 dark:border-red-700">Delete</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
