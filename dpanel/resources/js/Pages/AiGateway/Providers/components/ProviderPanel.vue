<script setup>
import Offcanvas from '@/Components/Offcanvas.vue';
import { computed, ref, watch } from 'vue';
import { useDragSort } from './useDragSort.js';
import { useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

// Create (provider null) or edit a provider, in a right-side panel; when
// editing, it also lists the models this provider serves.
const props = defineProps({
    show: { type: Boolean, default: false },
    provider: { type: Object, default: null },
    drivers: { type: Array, default: () => [] },
    panelRoute: { type: Function, required: true },
    modelPanelOpen: { type: Boolean, default: false },
});
const emit = defineEmits(['close', 'add-model', 'edit-model', 'reordered']);

const blank = () => ({
    driver: props.drivers[0]?.driver || 'openrouter',
    base_url: props.drivers[0]?.base_url || '',
    api_key: '',
    is_active: true,
    rate_limit_per_minute: 0,
});
const form = useForm(blank());
const loading = ref(false);
const loadError = ref('');
const testResult = ref(null);
const testing = ref(false);

watch(() => [props.show, props.provider?.id], async ([open]) => {
    if (!open) return;
    testResult.value = null;
    loadError.value = '';
    form.clearErrors();
    form.defaults(blank());
    form.reset();
    if (!props.provider) return;

    // The saved key is only sent when the panel opens, not with the list.
    loading.value = true;
    try {
        const { data } = await axios.get(props.panelRoute('ai-gateway.providers.edit', { provider: props.provider.id }), { headers: { Accept: 'application/json' } });
        form.defaults({
            driver: data.driver,
            base_url: data.base_url || '',
            api_key: data.api_key || '',
            is_active: !!data.is_active,
            rate_limit_per_minute: data.rate_limit_per_minute ?? 0,
        });
        form.reset();
    } catch (e) {
        loadError.value = e.response?.data?.message || t('aiGateway.providers.requestFailed');
    } finally {
        loading.value = false;
    }
}, { immediate: true });

const currentDriver = computed(() => props.drivers.find((d) => d.driver === form.driver));

// Base URL follows the driver's default while it still matches the previous one.
watch(() => currentDriver.value?.base_url, (value, previous) => {
    if (!form.base_url || form.base_url === previous) form.base_url = value || '';
});

const submit = () => {
    if (props.provider) {
        form.patch(props.panelRoute('ai-gateway.providers.update', { provider: props.provider.id }), { preserveScroll: true, onSuccess: () => emit('close') });
    } else {
        // The page reopens this panel on the new provider, ready for its models.
        form.post(props.panelRoute('ai-gateway.providers.store'), { preserveScroll: true });
    }
};

// Why the test request itself failed, in words: the server's own reason when
// it sent one, otherwise the HTTP status, otherwise "never got there" — so a
// failed test always says something.
const testErrorMessage = (e) => {
    const body = e.response?.data;
    const reason = typeof body === 'string' ? body.slice(0, 300) : (body?.message || body?.error);
    if (reason) return reason;
    if (e.response) return t('aiGateway.models.testFailedStatus', { status: [e.response.status, e.response.statusText].filter(Boolean).join(' ') });
    return t('aiGateway.models.testFailedNetwork', { error: e.message || t('aiGateway.providers.requestFailed') });
};

const runTest = async () => {
    testing.value = true;
    testResult.value = null;
    try {
        const { data } = await axios.post(props.panelRoute('ai-gateway.providers.test', { provider: props.provider.id }));
        testResult.value = { ...data, message: data.message || t('aiGateway.providers.form.testNoMessage') };
    } catch (e) {
        testResult.value = { ok: false, message: testErrorMessage(e) };
    } finally {
        testing.value = false;
    }
};

// The models this provider serves, in run order; drag to reorder.
const models = ref([]);
watch(() => props.provider?.models, (list) => { models.value = [...(list || [])]; }, { immediate: true });
const drag = useDragSort(models, async (list) => {
    try {
        await axios.post(props.panelRoute('ai-gateway.providers.models.reorder', { provider: props.provider.id }), { ids: list.map((m) => m.id) });
        emit('reordered');
    } catch (e) {
        models.value = [...(props.provider?.models || [])];
        alert(e.response?.data?.message || t('aiGateway.models.updateFailed'));
    }
});
const DROP = { 'drop-above': 'shadow-[inset_0_2px_0_0_#3b82f6]', 'drop-below': 'shadow-[inset_0_-2px_0_0_#3b82f6]' };
const input = 'w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-900';
</script>

<template>
    <Offcanvas :show="show" width="lg" :close-on-escape="!modelPanelOpen"
        :title="provider ? provider.name : t('aiGateway.providers.form.addTitle')"
        :subtitle="provider ? provider.driver_label : t('aiGateway.providers.form.addSubtitle')"
        @close="emit('close')">
        <div v-if="loading" class="py-10 text-center text-sm text-slate-500">{{ t('aiGateway.pm.loading') }}</div>
        <div v-else class="space-y-6">
            <div v-if="loadError" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{{ loadError }}</div>

            <form @submit.prevent="submit" class="space-y-4">
                <div>
                    <label class="mb-1 block text-sm font-medium">{{ t('aiGateway.providers.form.driver') }}</label>
                    <select v-model="form.driver" :class="input">
                        <option v-for="d in drivers" :key="d.driver" :value="d.driver">{{ d.label }}</option>
                    </select>
                    <div v-if="form.errors.driver" class="mt-1 text-xs text-red-600">{{ form.errors.driver }}</div>
                </div>

                <div>
                    <div class="mb-1 flex items-center justify-between gap-3">
                        <label class="block text-sm font-medium">{{ t('aiGateway.providers.form.baseUrl') }}</label>
                        <button type="button" class="text-xs text-blue-600 hover:underline dark:text-blue-400" @click="form.base_url = currentDriver?.base_url || ''">{{ t('aiGateway.providers.form.reset') }}</button>
                    </div>
                    <input v-model="form.base_url" type="text" placeholder="https://api.example.com/v1" :class="[input, 'font-mono']" />
                    <div class="mt-1 text-xs text-slate-500">{{ t('aiGateway.providers.form.baseUrlHelp', { url: currentDriver?.base_url }) }}</div>
                    <div v-if="form.errors.base_url" class="mt-1 text-xs text-red-600">{{ form.errors.base_url }}</div>
                </div>

                <div>
                    <div class="mb-1 flex items-center justify-between gap-3">
                        <label class="block text-sm font-medium">{{ t('aiGateway.providers.form.apiKey') }} <span v-if="provider?.has_credentials" class="text-xs text-emerald-600">{{ t('aiGateway.providers.form.configured') }}</span></label>
                        <a v-if="currentDriver?.api_key_url" :href="currentDriver.api_key_url" target="_blank" rel="noopener" class="text-xs text-blue-600 hover:underline dark:text-blue-400">{{ t('aiGateway.providers.form.getKey') }} &rarr;</a>
                    </div>
                    <input v-model="form.api_key" type="text" autocomplete="off" spellcheck="false" :class="[input, 'font-mono']" />
                    <div v-if="form.errors.api_key" class="mt-1 text-xs text-red-600">{{ form.errors.api_key }}</div>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium">{{ t('aiGateway.pm.rateLimit') }}</label>
                    <input v-model.number="form.rate_limit_per_minute" type="number" min="0" :class="input" />
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <input v-model="form.is_active" type="checkbox" class="rounded border-slate-300" />
                    {{ t('aiGateway.providers.form.active') }}
                </label>

                <div class="flex flex-wrap items-center gap-3">
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50">
                        {{ form.processing ? t('aiGateway.providers.form.saving') : (provider ? t('aiGateway.providers.form.saveChanges') : t('aiGateway.providers.form.create')) }}
                    </button>
                    <button v-if="provider" type="button" @click="runTest" :disabled="testing" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 disabled:opacity-50 dark:border-slate-600 dark:hover:bg-slate-700">
                        {{ testing ? t('aiGateway.providers.form.testing') : t('aiGateway.providers.form.testConnection') }}
                    </button>
                </div>
                <div v-if="testResult" class="rounded-md border px-3 py-2 text-sm" :class="testResult.ok ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300' : 'border-red-200 bg-red-50 text-red-700 dark:border-red-800 dark:bg-red-900/30 dark:text-red-300'">
                    <i class="bi" :class="testResult.ok ? 'bi-check-circle' : 'bi-x-circle'"></i>
                    <span class="font-medium">{{ testResult.ok ? t('aiGateway.models.statusWorking') : t('aiGateway.models.statusFailed') }}</span>
                    <span class="break-words"> — {{ testResult.message }}</span>
                </div>
            </form>

            <section v-if="provider" class="border-t border-slate-200 pt-4 dark:border-slate-700">
                <div class="mb-2 flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-semibold">{{ t('aiGateway.pm.modelsInUse', { count: models.filter((m) => m.is_active).length }) }}</h3>
                        <p v-if="models.length > 1" class="text-xs text-slate-500">{{ t('aiGateway.pm.dragHint') }}</p>
                    </div>
                    <button type="button" @click="emit('add-model', provider.id)" class="rounded-md border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 dark:border-slate-600 dark:hover:bg-slate-700">+ {{ t('aiGateway.models.add') }}</button>
                </div>
                <p v-if="!models.length" class="text-sm text-slate-500">{{ t('aiGateway.pm.noModels') }}</p>
                <ul v-else class="divide-y divide-slate-200 rounded-md border border-slate-200 dark:divide-slate-700 dark:border-slate-700">
                    <li v-for="(m, i) in models" :key="m.id" v-bind="drag.row(i)" class="flex items-center gap-3 bg-white px-3 py-1.5 text-xs dark:bg-slate-900"
                        :class="[m.is_active ? '' : 'opacity-50', DROP[drag.dropClass(i)], drag.isDragging(i) ? 'opacity-40' : '']">
                        <span v-bind="drag.handle(i)" class="cursor-grab touch-none text-slate-400 hover:text-slate-600 active:cursor-grabbing dark:hover:text-slate-200" :title="t('aiGateway.pm.dragHint')"><i class="bi bi-grip-vertical"></i></span>
                        <span class="w-5 shrink-0 text-center text-xs tabular-nums text-slate-400">{{ i + 1 }}</span>
                        <div class="min-w-0 flex-1">
                            <div class="truncate font-medium">{{ m.display_name || m.name }}</div>
                            <div class="truncate text-[10px] text-slate-400">{{ m.name }}<template v-if="m.capabilities?.length"> · {{ m.capabilities.join(', ') }}</template></div>
                        </div>
                        <span v-if="m.auto_disabled" class="text-xs text-amber-600">{{ t('aiGateway.models.autoDisabled') }}</span>
                        <span v-else-if="m.last_test" class="text-xs" :class="m.last_test.ok ? 'text-emerald-600' : 'text-red-600'"><i class="bi" :class="m.last_test.ok ? 'bi-check-circle' : 'bi-x-circle'"></i></span>
                        <button type="button" @click="emit('edit-model', m)" class="rounded border border-slate-300 px-2 py-0.5 text-xs hover:bg-slate-100 dark:border-slate-600 dark:hover:bg-slate-700">{{ t('aiGateway.models.edit') }}</button>
                    </li>
                </ul>
            </section>
        </div>
    </Offcanvas>
</template>
