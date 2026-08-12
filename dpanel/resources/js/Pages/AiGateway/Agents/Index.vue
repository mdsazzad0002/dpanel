<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';

const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

const props = defineProps({
    agents: { type: Array, default: () => [] },
});

const delForm = useForm({});

const remove = (a) => { if (confirm(`Delete agent "${a.name}"?`)) delForm.delete(panelRoute('ai-gateway.agents.destroy', { agent: a.id })); };
</script>

<template>
    <Head title="AI Agents" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-lg font-semibold">AI Agents</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Reusable, prompt-tuned wrappers around providers and models.</p>
                </div>
                <Link :href="panelRoute('ai-gateway.agents.create')" class="rounded-md bg-blue-600 px-3 py-2 text-sm text-white hover:bg-blue-700">+ New Agent</Link>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ page.props.flash.success }}</div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ page.props.flash.error }}</div>

            <div v-if="!agents.length" class="rounded-lg border border-dashed border-slate-300 p-10 text-center text-sm text-slate-500 dark:border-slate-700">
                No agents yet. <Link :href="panelRoute('ai-gateway.agents.create')" class="text-blue-600 hover:underline">Create your first agent</Link>.
            </div>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                <div v-for="a in agents" :key="a.id" class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                    <div class="flex items-start justify-between">
                        <div>
                            <h3 class="font-semibold">{{ a.name }}</h3>
                            <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-500 dark:bg-slate-700">{{ a.is_active ? 'Active' : 'Inactive' }}</span>
                        </div>
                        <div class="flex gap-1">
                            <Link :href="panelRoute('ai-gateway.agents.edit', { agent: a.id })" class="rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 dark:border-slate-600">Edit</Link>
                            <button @click="remove(a)" class="rounded border border-red-300 px-2 py-1 text-xs text-red-600 hover:bg-red-50 dark:border-red-700">Delete</button>
                        </div>
                    </div>
                    <p class="mt-2 line-clamp-2 text-sm text-slate-500">{{ a.description || 'No description' }}</p>
                    <div class="mt-3 text-xs text-slate-400">
                        <div>{{ a.provider_name || 'Auto provider' }} · {{ a.model_name || 'Default model' }}</div>
                        <div v-if="a.temperature != null">Temperature {{ a.temperature }} · Max {{ a.max_tokens || 'default' }} tokens</div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>