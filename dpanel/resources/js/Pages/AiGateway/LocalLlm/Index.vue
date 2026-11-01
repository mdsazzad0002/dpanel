<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import StatusCard from './components/StatusCard.vue';
import ConnectCard from './components/ConnectCard.vue';
import InstalledModels from './components/InstalledModels.vue';
import Downloads from './components/Downloads.vue';
import ModelCatalog from './components/ModelCatalog.vue';

const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

const props = defineProps({
    configured: { type: Boolean, default: false },
    baseUrl: { type: String, default: '' },
    catalog: { type: Array, default: () => [] },
    initialState: { type: Object, required: true },
});

const state = ref(props.initialState);
const error = ref('');
const notice = ref('');
const modelBusy = reactive({});
const pulling = ref(false);
const connecting = ref(false);

const installedIds = computed(() => state.value.models.map((m) => m.id));
const downloading = computed(() => Object.values(state.value.pulls || {}).some((p) => p.state === 'downloading'));

const failure = (e, fallback) => e.response?.data?.message || fallback;

const refresh = async () => {
    const wasDownloading = downloading.value;
    try {
        const { data } = await axios.get(panelRoute('ai-gateway.local.state'));
        state.value = data;
    } catch (e) {
        return;
    }
    // A finished download should show up in the gateway without another click.
    if (wasDownloading && !downloading.value && state.value.provider) {
        await connect(true);
    }
};

// Poll fast while something downloads, slowly otherwise (memory, idle timers).
let timer = null;
const schedule = () => {
    timer = setTimeout(async () => {
        await refresh();
        schedule();
    }, downloading.value ? 2000 : 15000);
};
onMounted(schedule);
onBeforeUnmount(() => clearTimeout(timer));

const pull = async (payload, done) => {
    pulling.value = true;
    error.value = '';
    try {
        await axios.post(panelRoute('ai-gateway.local.pull'), payload);
        done?.();
        await refresh();
        clearTimeout(timer);
        schedule();
    } catch (e) {
        error.value = failure(e, 'Could not start the download.');
    } finally {
        pulling.value = false;
    }
};

const modelAction = async (m, action, label) => {
    modelBusy[m.id] = label;
    error.value = '';
    try {
        const method = action === 'destroy' ? 'delete' : 'post';
        await axios[method](panelRoute(`ai-gateway.local.${action}`, { model: m.id }));
        await refresh();
    } catch (e) {
        error.value = failure(e, `Could not ${action} ${m.id}.`);
    } finally {
        delete modelBusy[m.id];
    }
};

const remove = (m) => {
    if (!confirm(`Delete ${m.id}? The file is removed from the server and the AI Gateway stops using it.`)) return;
    modelAction(m, 'destroy', 'Deleting');
};

const connect = async (quiet = false) => {
    connecting.value = true;
    if (!quiet) error.value = '';
    try {
        const { data } = await axios.post(panelRoute('ai-gateway.local.connect'));
        state.value = { ...state.value, provider: data.provider };
        if (!quiet) notice.value = 'The AI Gateway now uses the installed models.';
    } catch (e) {
        error.value = failure(e, 'Could not connect to the AI Gateway.');
    } finally {
        connecting.value = false;
    }
};
</script>

<template>
    <Head title="Local Models" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Local Models</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Run open models on this server with drust-llm, without a cloud API key.</p>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="error" class="flex items-start justify-between gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-950 dark:text-red-300">
                <span>{{ error }}</span>
                <button @click="error = ''" class="shrink-0 text-red-400 hover:text-red-600"><i class="bi bi-x-lg"></i></button>
            </div>
            <div v-if="notice" class="flex items-start justify-between gap-3 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">
                <span>{{ notice }}</span>
                <button @click="notice = ''" class="shrink-0 text-emerald-400 hover:text-emerald-600"><i class="bi bi-x-lg"></i></button>
            </div>

            <StatusCard :configured="configured" :online="state.online" :status="state.status" :base-url="baseUrl" />

            <template v-if="state.online">
                <ConnectCard :provider="state.provider" :has-models="state.models.length > 0" :busy="connecting" @connect="connect()" />

                <InstalledModels :models="state.models" :busy="modelBusy"
                    @load="(m) => modelAction(m, 'load', 'Loading')"
                    @unload="(m) => modelAction(m, 'unload', 'Unloading')"
                    @remove="remove" />

                <ModelCatalog :catalog="catalog" :installed="installedIds" :pulls="state.pulls"
                    :total-memory="state.status?.memory?.total_bytes || 0" :busy="pulling" @pull="pull" />

                <Downloads :pulls="state.pulls" :busy="pulling" @pull="pull" />
            </template>
        </div>
    </AuthenticatedLayout>
</template>
