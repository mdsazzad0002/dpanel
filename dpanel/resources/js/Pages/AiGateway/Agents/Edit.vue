<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { computed } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';

const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

const props = defineProps({
    agent: { type: Object, default: () => ({}) },
    providers: { type: Array, default: () => [] },
    models: { type: Array, default: () => [] },
});

const form = useForm({
    name: props.agent.name || '',
    description: props.agent.description || '',
    system_prompt: props.agent.system_prompt || '',
    provider_id: props.agent.provider_id || '',
    model_id: props.agent.model_id || '',
    temperature: props.agent.temperature ?? 0.3,
    max_tokens: props.agent.max_tokens || '',
    tools: props.agent.tools || null,
    is_active: !!props.agent.is_active,
});

const testForm = useForm({ prompt: '' });
const filteredModels = computed(() => form.provider_id
    ? props.models.filter((m) => m.provider_id === Number(form.provider_id))
    : props.models);

const runTest = () => testForm.post(panelRoute('ai-gateway.agents.test', { agent: props.agent.id }));
const submit = () => form.patch(panelRoute('ai-gateway.agents.update', { agent: props.agent.id }));
</script>

<template>
    <Head :title="`Edit ${agent.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Edit Agent — {{ agent.name }}</h1>
            </div>
        </template>

        <div class="max-w-3xl space-y-4">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ page.props.flash.success }}</div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ page.props.flash.error }}</div>

            <form @submit.prevent="runTest" class="flex gap-2 rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                <input v-model="testForm.prompt" type="text" class="flex-1 rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-900" placeholder="Test prompt…" />
                <button type="submit" :disabled="testForm.processing" class="rounded-md bg-slate-700 px-4 py-2 text-sm text-white hover:bg-slate-800 disabled:opacity-50">Run Test</button>
            </form>

            <form @submit.prevent="submit" class="space-y-4 rounded-lg border border-slate-200 bg-white p-6 dark:border-slate-700 dark:bg-slate-800">
                <div>
                    <label class="mb-1 block text-sm font-medium">Name</label>
                    <input v-model="form.name" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-900" />
                    <div v-if="form.errors.name" class="text-xs text-red-600">{{ form.errors.name }}</div>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Description</label>
                    <input v-model="form.description" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-900" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">System Prompt</label>
                    <textarea v-model="form.system_prompt" rows="5" class="w-full rounded-md border border-slate-300 px-3 py-2 font-mono text-sm dark:border-slate-600 dark:bg-slate-900"></textarea>
                </div>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium">Provider</label>
                        <select v-model="form.provider_id" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-900">
                            <option value="">Auto (routing)</option>
                            <option v-for="p in providers" :key="p.id" :value="p.id">{{ p.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">Model</label>
                        <select v-model="form.model_id" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-900">
                            <option value="">Default</option>
                            <option v-for="m in filteredModels" :key="m.id" :value="m.id">{{ m.name }}</option>
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium">Temperature</label>
                        <input v-model.number="form.temperature" type="number" step="0.1" min="0" max="2" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-900" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">Max Tokens</label>
                        <input v-model="form.max_tokens" type="number" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-900" />
                    </div>
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <input v-model="form.is_active" type="checkbox" class="rounded border-slate-300" />
                    Active
                </label>
                <div class="flex items-center gap-3">
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50">{{ form.processing ? 'Saving…' : 'Save Changes' }}</button>
                    <Link :href="panelRoute('ai-gateway.agents.index')" class="text-sm text-slate-500 hover:underline">Cancel</Link>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>