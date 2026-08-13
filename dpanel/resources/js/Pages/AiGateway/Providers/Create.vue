<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { computed, watch } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';

const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

const props = defineProps({
    drivers: { type: Array, default: () => [] },
    defaultModelSeed: { type: Object, default: () => ({}) },
});

const form = useForm({
    name: '',
    driver: props.drivers[0]?.driver || 'openrouter',
    base_url: '',
    api_key: '',
    organization: '',
    project: '',
    default_model: '',
    is_active: true,
    weight: 100,
    rate_limit_per_minute: 0,
});

const currentDriver = computed(() => props.drivers.find((d) => d.driver === form.driver));
const suggestedModels = computed(() => props.defaultModelSeed?.[form.driver] || []);
const currentAutoName = computed(() => {
    const label = currentDriver.value?.label?.replace(/\s*\(.*\)$/, '');
    return label ? `${label} Provider` : 'AI Provider';
});

watch(
    suggestedModels,
    (models) => {
        if (!models.some((model) => model.name === form.default_model)) {
            form.default_model = '';
        }
    },
    { immediate: true },
);

watch(currentAutoName, (value) => {
    if (!form.name || form.name === currentAutoName.value) {
        form.name = value;
    }
}, { immediate: true });

// Base URL auto-fills from the selected driver's default, but stays
// editable — switching drivers only overwrites it while the field still
// matches the previous driver's default (i.e. the user hasn't customised it).
watch(() => currentDriver.value?.base_url, (value, previous) => {
    if (!form.base_url || form.base_url === previous) {
        form.base_url = value || '';
    }
}, { immediate: true });

</script>

<template>
    <Head title="Add AI Provider" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Add AI Provider</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Register an upstream AI service for the gateway.</p>
            </div>
        </template>

        <div class="max-w-3xl space-y-4">
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ page.props.flash.error }}</div>

            <form @submit.prevent="form.post(panelRoute('ai-gateway.providers.store'))" class="space-y-4 rounded-lg border border-slate-200 bg-white p-6 dark:border-slate-700 dark:bg-slate-800">
                <div>
                    <label class="mb-1 block text-sm font-medium">Provider Name</label>
                    <input v-model="form.name" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-900" placeholder="e.g. OpenAI Production" />
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
                    <div class="mb-1 flex items-center justify-between gap-3">
                        <label class="block text-sm font-medium">Base URL</label>
                        <button
                            type="button"
                            class="text-xs text-blue-600 hover:underline dark:text-blue-400"
                            @click="form.base_url = currentDriver?.base_url || ''"
                        >Reset to default</button>
                    </div>
                    <input v-model="form.base_url" type="text" placeholder="https://api.example.com/v1" class="w-full rounded-md border border-slate-300 px-3 py-2 font-mono text-sm dark:border-slate-600 dark:bg-slate-900" />
                    <div class="mt-1 text-xs text-slate-500">Defaults to {{ currentDriver?.base_url }} for the selected driver — edit for a proxy or self-hosted endpoint.</div>
                    <div v-if="form.errors.base_url" class="mt-1 text-xs text-red-600">{{ form.errors.base_url }}</div>
                </div>

                <div>
                    <div class="mb-1 flex items-center justify-between gap-3">
                        <label class="block text-sm font-medium">API Key</label>
                        <a v-if="currentDriver?.api_key_url" :href="currentDriver.api_key_url" target="_blank" rel="noopener" class="text-xs text-blue-600 hover:underline dark:text-blue-400">Get an API key &rarr;</a>
                    </div>
                    <input v-model="form.api_key" type="password" autocomplete="new-password" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-900" />
                    <div v-if="form.errors.api_key" class="mt-1 text-xs text-red-600">{{ form.errors.api_key }}</div>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium">Default Model</label>
                        <select v-model="form.default_model" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-900">
                            <option value="">Auto select</option>
                            <option v-for="m in suggestedModels" :key="m.name" :value="m.name">
                                {{ m.display_name || m.name }}
                            </option>
                        </select>
                        <div class="mt-1 text-xs text-slate-400">Auto select uses the provider's seeded model map for the selected driver.</div>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">Weight</label>
                        <input v-model.number="form.weight" type="number" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-900" />
                        <div class="text-xs text-slate-400">Higher = preferred when routing</div>
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
                        {{ form.processing ? 'Saving…' : 'Create Provider' }}
                    </button>
                    <Link :href="panelRoute('ai-gateway.providers.index')" class="text-sm text-slate-500 hover:underline">Cancel</Link>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
