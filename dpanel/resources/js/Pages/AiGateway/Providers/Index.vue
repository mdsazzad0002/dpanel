<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ProviderCard from './components/ProviderCard.vue';
import ProviderPanel from './components/ProviderPanel.vue';
import ModelPanel from './components/ModelPanel.vue';
import LocalVoiceCard from './components/LocalVoiceCard.vue';
import { useRemoteModels } from './components/useRemoteModels.js';
import { useDragSort } from './components/useDragSort.js';
import { computed, reactive, ref, watch } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

// Providers & Models on one page. No default model and no priority numbers:
// drag providers (and the models inside each) into order; top runs first.
const props = defineProps({
    providers: { type: Array, default: () => [] },
    drivers: { type: Array, default: () => [] },
    defaultModelSeed: { type: Object, default: () => ({}) },
    localTranscription: { type: Object, default: null },
    openPanel: { type: Object, default: () => ({}) },
});

const remote = useRemoteModels({
    providers: () => props.providers,
    seed: () => props.defaultModelSeed,
    panelRoute,
    failedMessage: () => t('aiGateway.models.remoteFailed'),
});

// Provider order: drag by the handle, saved at once, then the page reloads
// its providers so the panels and the router see the same order.
const ordered = ref([...props.providers]);
watch(() => props.providers, (list) => { ordered.value = [...list]; });
const refresh = () => router.reload({ only: ['providers'], preserveScroll: true });
const providerDrag = useDragSort(ordered, async (list) => {
    try {
        await axios.post(panelRoute('ai-gateway.providers.reorder'), { ids: list.map((p) => p.id) });
        refresh();
    } catch (e) {
        ordered.value = [...props.providers];
        alert(e.response?.data?.message || t('aiGateway.models.updateFailed'));
    }
});
const DROP = { 'drop-above': 'shadow-[0_-3px_0_0_#3b82f6] rounded-lg', 'drop-below': 'shadow-[0_3px_0_0_#3b82f6] rounded-lg' };

// Provider panel (right). Kept by id so it shows fresh data after each save.
const providerPanel = reactive({ show: false, id: null });
const panelProvider = computed(() => props.providers.find((p) => p.id === providerPanel.id) || null);
const openProvider = (p) => { providerPanel.id = p?.id ?? null; providerPanel.show = true; };

// Model panel, opens on top of the provider panel.
const modelPanel = reactive({ show: false, modelId: null, providerId: null });
const allModels = computed(() => props.providers.flatMap((p) => p.models));
const panelModel = computed(() => allModels.value.find((m) => m.id === modelPanel.modelId) || null);
const addModel = (providerId) => Object.assign(modelPanel, { show: true, modelId: null, providerId });
const editModel = (m) => Object.assign(modelPanel, { show: true, modelId: m.id, providerId: m.provider_id });

// ?create / ?edit / ?provider (old links, or a provider just created) open a panel.
watch(() => props.openPanel, (o) => {
    if (!o) return;
    if (o.create) openProvider(null);
    else if (o.edit) openProvider(props.providers.find((p) => p.id === o.edit));
    else if (o.provider && props.providers.some((p) => p.id === o.provider)) openProvider({ id: o.provider });
}, { immediate: true });

// Model tester: asks the model directly (no failover).
const testing = reactive({});
// Why the test request itself failed, in words: the server's own reason when
// it sent one, otherwise the HTTP status, otherwise "never got there" — so a
// failed test never shows up as a bare "Failed" with nothing to go on.
const testErrorMessage = (e) => {
    const body = e.response?.data;
    const reason = typeof body === 'string' ? body.slice(0, 300) : (body?.message || body?.error);
    if (reason) return reason;
    if (e.response) return t('aiGateway.models.testFailedStatus', { status: [e.response.status, e.response.statusText].filter(Boolean).join(' ') });
    return t('aiGateway.models.testFailedNetwork', { error: e.message || t('aiGateway.models.testFailed') });
};
const testAllRunning = ref(false);
const testModel = async (m) => {
    testing[m.id] = true;
    try {
        const { data } = await axios.post(panelRoute('ai-gateway.models.test', { model: m.id }));
        m.last_test = data;
        if (data.capabilities) m.capabilities = data.capabilities;
    } catch (e) {
        m.last_test = { ok: false, message: testErrorMessage(e), latency_ms: 0, tested_at: new Date().toISOString() };
    } finally {
        delete testing[m.id];
    }
};
// One at a time: local models share one machine and providers rate-limit.
const testAll = async () => {
    testAllRunning.value = true;
    for (const m of allModels.value.filter((x) => x.is_active && x.testable)) await testModel(m);
    testAllRunning.value = false;
};
const testedSummary = computed(() => {
    const tested = allModels.value.filter((m) => m.last_test);
    return { ok: tested.filter((m) => m.last_test.ok).length, failed: tested.filter((m) => !m.last_test.ok).length };
});
</script>

<template>
    <Head :title="t('aiGateway.pm.title')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-semibold">{{ t('aiGateway.pm.title') }}</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ t('aiGateway.pm.subtitle') }}</p>
                </div>
                <div class="flex gap-2">
                    <button @click="addModel(null)" :disabled="!providers.length" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 disabled:opacity-50 dark:border-slate-600 dark:hover:bg-slate-700">+ {{ t('aiGateway.models.add') }}</button>
                    <button @click="openProvider(null)" class="rounded-md bg-blue-600 px-3 py-2 text-sm text-white hover:bg-blue-700">+ {{ t('aiGateway.providers.add') }}</button>
                </div>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ page.props.flash.success }}</div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ page.props.flash.error }}</div>
            <div v-if="page.props.errors?.name && !modelPanel.show" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ page.props.errors.name }}</div>

            <div class="rounded-md border border-blue-200 bg-blue-50 px-4 py-2 text-xs text-blue-800 dark:border-blue-900 dark:bg-blue-950/40 dark:text-blue-300">
                <i class="bi bi-signpost-split"></i> {{ t('aiGateway.pm.routingHelp') }}
            </div>

            <LocalVoiceCard v-if="localTranscription" :voice="localTranscription" :panel-route="panelRoute" />

            <div v-if="!providers.length" class="rounded-lg border border-dashed border-slate-300 p-10 text-center text-sm text-slate-500 dark:border-slate-700">
                {{ t('aiGateway.pm.empty') }}
                <button @click="openProvider(null)" class="ml-1 text-blue-600 hover:underline">{{ t('aiGateway.providers.addFirst') }}</button>
            </div>

            <template v-else>
                <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500">
                    <div>
                        {{ t('aiGateway.models.testHelp') }}
                        <span v-if="testedSummary.ok || testedSummary.failed" class="ml-1">
                            <span class="text-emerald-600">{{ t('aiGateway.models.working', { count: testedSummary.ok }) }}</span><template v-if="testedSummary.failed"> · <span class="text-red-600">{{ t('aiGateway.models.failing', { count: testedSummary.failed }) }}</span></template>
                        </span>
                    </div>
                    <button @click="testAll" :disabled="testAllRunning" class="rounded-md border border-slate-300 px-3 py-1 text-xs hover:bg-slate-100 disabled:opacity-50 dark:border-slate-600 dark:hover:bg-slate-700">
                        <i class="bi bi-play-circle"></i> {{ testAllRunning ? t('aiGateway.models.testing') : t('aiGateway.models.testAll') }}
                    </button>
                </div>

                <div v-for="(p, i) in ordered" :key="p.id" v-bind="providerDrag.row(i)"
                    :class="[DROP[providerDrag.dropClass(i)], providerDrag.isDragging(i) ? 'opacity-40' : '']">
                    <ProviderCard :provider="p" :position="i + 1" :drag-handle="providerDrag.handle(i)" :testing="testing" :panel-route="panelRoute"
                        @edit="openProvider" @add-model="addModel" @edit-model="editModel" @test-model="testModel" @reordered="refresh" />
                </div>
            </template>
        </div>

        <ProviderPanel :show="providerPanel.show" :provider="panelProvider" :drivers="drivers" :panel-route="panelRoute"
            :model-panel-open="modelPanel.show"
            @close="providerPanel.show = false" @add-model="addModel" @edit-model="editModel" @reordered="refresh" />

        <ModelPanel :show="modelPanel.show" :model="panelModel" :provider-id="modelPanel.providerId" :providers="providers"
            :remote="remote" :panel-route="panelRoute" @close="modelPanel.show = false" />
    </AuthenticatedLayout>
</template>
