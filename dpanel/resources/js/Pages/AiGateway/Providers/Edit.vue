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
    provider: { type: Object, default: () => ({}) },
    drivers: { type: Array, default: () => [] },
    defaultModelSeed: { type: Object, default: () => ({}) },
});

const form = useForm({
    name: props.provider.name || '',
    driver: props.provider.driver || 'openai_compatible',
    base_url: props.provider.base_url || '',
    api_key: '',
    organization: '',
    project: '',
    default_model: props.provider.default_model || '',
    is_active: !!props.provider.is_active,
    weight: props.provider.weight ?? 100,
    rate_limit_per_minute: props.provider.rate_limit_per_minute ?? 0,
});

const testForm = useForm({});
const syncForm = useForm({});
const suggestedModels = computed(() => props.defaultModelSeed?.[form.driver] || []);
</script>

<template>
    <Head :title="`Edit ${provider.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Edit Provider — {{ provider.name }}</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ provider.driver_label }}</p>
            </div>
        </template>

        <div class="max-w-3xl space-y-4">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ page.props.flash.success }}</div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ page.props.flash.error }}</div>

            <div class="flex gap-2">
                <button @click="testForm.post(panelRoute('ai-gateway.providers.test', { provider: provider.id }))" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-600 dark:hover:bg-slate-700">Test Connection</button>
                <button @click="syncForm.post(panelRoute('ai-gateway.providers.sync-models', { provider: provider.id }))" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-600 dark:hover:bg-slate-700">Sync Default Models</button>
            </div>


            <form @submit.prevent="form.patch(panelRoute('ai-gateway.providers.update', { provider: provider.id }))" class="space-y-4 rounded-lg border border-slate-200 bg-white p-6 dark:border-slate-700 dark:bg-slate-800">
                <div>
                    <label class="mb-1 block text-sm font-medium">Provider Name</label>
                    <input v-model="form.name" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-900" />
                    <div v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</div>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium">Driver</label>
                    <select v-model="form.driver" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-900">
                        <option v-for="d in drivers" :key="d.driver" :value="d.driver">{{ d.label }}</option>
                    </select>
                    <div v-if="form.errors.driver" class="mt-1 text-xs text-red-600">{{ form.errors.driver }}</div>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium">Base URL</label>
                    <input v-model="form.base_url" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-900" />
                    <div v-if="form.errors.base_url" class="mt-1 text-xs text-red-600">{{ form.errors.base_url }}</div>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium">API Key <span v-if="provider.has_credentials" class="text-xs text-emerald-600">(configured — leave blank to keep)</span></label>
                    <input v-model="form.api_key" type="password" autocomplete="new-password" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-900" />
                    <div v-if="form.errors.api_key" class="mt-1 text-xs text-red-600">{{ form.errors.api_key }}</div>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium">Default Model</label>
                        <input v-model="form.default_model" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-900" list="suggested-models" />
                        <datalist id="suggested-models"><option v-for="m in suggestedModels" :key="m.name" :value="m.name">{{ m.display_name }}</option></datalist>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">Weight</label>
                        <input v-model.number="form.weight" type="number" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-900" />
                    </div>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium">Rate Limit (req/min, 0 = unlimited)</label>
                    <input v-model.number="form.rate_limit_per_minute" type="number" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-900" />
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <input v-model="form.is_active" type="checkbox" class="rounded border-slate-300" />
                    Active
                </label>

                <div class="flex items-center gap-3">
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50">
                        {{ form.processing ? 'Saving…' : 'Save Changes' }}
                    </button>
                    <Link :href="panelRoute('ai-gateway.providers.index')" class="text-sm text-slate-500 hover:underline">Cancel</Link>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
