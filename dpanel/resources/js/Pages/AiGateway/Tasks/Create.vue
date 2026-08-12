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
    agents: { type: Array, default: () => [] },
    providers: { type: Array, default: () => [] },
    models: { type: Array, default: () => [] },
});

const form = useForm({
    title: '', type: 'chat', agent_id: '', provider_id: '', model_id: '',
    prompt: '', system: '', temperature: '', max_tokens: '', run_now: false,
});

const filteredModels = computed(() => form.provider_id
    ? props.models.filter((m) => m.provider_id === Number(form.provider_id))
    : props.models);

const submit = () => form.post(panelRoute('ai-gateway.tasks.store'));
</script>

<template>
    <Head title="New AI Task" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">New AI Task</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Queue a chat/completion run through the gateway.</p>
            </div>
        </template>

        <div class="max-w-3xl space-y-4">
            <form @submit.prevent="submit" class="space-y-4 rounded-lg border border-slate-200 bg-white p-6 dark:border-slate-700 dark:bg-slate-800">
                <div>
                    <label class="mb-1 block text-sm font-medium">Title</label>
                    <input v-model="form.title" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-900" />
                    <div v-if="form.errors.title" class="text-xs text-red-600">{{ form.errors.title }}</div>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium">Type</label>
                    <select v-model="form.type" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-900">
                        <option value="chat">Chat</option>
                        <option value="agent">Agent</option>
                    </select>
                </div>

                <div v-if="form.type === 'agent'">
                    <label class="mb-1 block text-sm font-medium">Agent</label>
                    <select v-model="form.agent_id" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-900">
                        <option value="">None</option>
                        <option v-for="a in agents" :key="a.id" :value="a.id">{{ a.name }}</option>
                    </select>
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

                <div>
                    <label class="mb-1 block text-sm font-medium">System Prompt <span class="text-slate-400">(optional)</span></label>
                    <textarea v-model="form.system" rows="3" class="w-full rounded-md border border-slate-300 px-3 py-2 font-mono text-sm dark:border-slate-600 dark:bg-slate-900"></textarea>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium">Prompt</label>
                    <textarea v-model="form.prompt" rows="4" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-900"></textarea>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium">Temperature (0–2)</label>
                        <input v-model.number="form.temperature" type="number" step="0.1" min="0" max="2" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-900" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">Max Tokens</label>
                        <input v-model.number="form.max_tokens" type="number" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-900" />
                    </div>
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <input v-model="form.run_now" type="checkbox" class="rounded border-slate-300" />
                    Run immediately
                </label>

                <div class="flex items-center gap-3">
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50">{{ form.processing ? 'Saving…' : 'Create Task' }}</button>
                    <Link :href="panelRoute('ai-gateway.tasks.index')" class="text-sm text-slate-500 hover:underline">Cancel</Link>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>