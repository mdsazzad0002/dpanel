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
    providers: { type: Array, default: () => [] },
    models: { type: Array, default: () => [] },
});

const form = useForm({
    name: '', description: '', system_prompt: '', provider_id: '', model_id: '',
    temperature: 0.3, max_tokens: '', tools: null, is_active: true,
});

const filteredModels = computed(() => form.provider_id
    ? props.models.filter((m) => m.provider_id === Number(form.provider_id))
    : props.models);

const submit = () => form.post(panelRoute('ai-gateway.agents.store'));
</script>

<template>
    <Head title="New AI Agent" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">New AI Agent</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Create a reusable, prompt-tuned agent.</p>
            </div>
        </template>

        <div class="max-w-3xl space-y-4">
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
                        <label class="mb-1 block text-sm font-medium">Temperature (0–2)</label>
                        <input v-model.number="form.temperature" type="number" step="0.1" min="0" max="2" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-900" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">Max Tokens (optional)</label>
                        <input v-model="form.max_tokens" type="number" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-900" />
                    </div>
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <input v-model="form.is_active" type="checkbox" class="rounded border-slate-300" />
                    Active
                </label>
                <div class="flex items-center gap-3">
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50">{{ form.processing ? 'Saving…' : 'Create Agent' }}</button>
                    <Link :href="panelRoute('ai-gateway.agents.index')" class="text-sm text-slate-500 hover:underline">Cancel</Link>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>