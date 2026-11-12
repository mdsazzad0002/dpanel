<script setup>
// Migrate → DPanel to DPanel → Live connection: link another dPanel with an API
// token made there, then pull websites / the whole panel from it or push them to
// it, and sync changed files later. Backend: PanelLinkController, RunPanelTransferJob.
import axios from 'axios';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import ConnectionForm from './ConnectionForm.vue';
import ConnectionList from './ConnectionList.vue';
import PullPanel from './PullPanel.vue';
import PushPanel from './PushPanel.vue';
import TransferList from './TransferList.vue';

const { t } = useI18n();

const state = ref({ connections: [], transfers: [], local_websites: [], local_users: [], panel_url: '' });
const selectedId = ref(null);
const tab = ref('pull');
const loading = ref(true);
const error = ref('');
let pollTimer = null;

const selected = computed(() => state.value.connections.find((connection) => connection.id === selectedId.value) ?? null);
const hasActive = computed(() => state.value.transfers.some((transfer) => ['queued', 'running'].includes(transfer.status)));

const load = async () => {
    try {
        const { data } = await axios.get(route('migrations.dpanel.link.index'));
        state.value = data;
        if (!selected.value) selectedId.value = data.connections[0]?.id ?? null;
        error.value = '';
    } catch (e) {
        error.value = e.response?.data?.message || t('common.sessionExpired');
    } finally {
        loading.value = false;
    }
};

const refreshTransfers = async () => {
    try {
        const { data } = await axios.get(route('migrations.dpanel.link.transfers'));
        state.value.transfers = data.transfers;
    } catch {
        // Next poll tries again.
    }
};

const onCreated = (connection) => {
    state.value.connections.push(connection);
    selectedId.value = connection.id;
};

const onConnectionChanged = (connection) => {
    const index = state.value.connections.findIndex((item) => item.id === connection.id);
    if (index >= 0) state.value.connections[index] = connection;
};

const onConnectionRemoved = (id) => {
    state.value.connections = state.value.connections.filter((item) => item.id !== id);
    if (selectedId.value === id) selectedId.value = state.value.connections[0]?.id ?? null;
};

const onStarted = (transfers) => {
    state.value.transfers = [...transfers, ...state.value.transfers];
};

onMounted(() => {
    load();
    pollTimer = window.setInterval(() => {
        if (hasActive.value) refreshTransfers();
    }, 4000);
});
onBeforeUnmount(() => window.clearInterval(pollTimer));
</script>

<template>
    <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900/50">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 class="flex items-center gap-2 text-lg font-semibold text-slate-900 dark:text-slate-100">
                    <i class="bi bi-link-45deg text-indigo-500"></i>{{ t('panelLink.title') }}
                </h3>
                <p class="mt-1 max-w-3xl text-sm text-slate-500 dark:text-slate-400">{{ t('panelLink.intro') }}</p>
            </div>
        </div>

        <p v-if="error" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-500/10 dark:text-red-300">{{ error }}</p>
        <p v-if="loading" class="mt-4 text-sm text-slate-500">{{ t('common.loading') }}</p>

        <div v-else class="mt-5 grid gap-5 lg:grid-cols-[minmax(0,320px)_minmax(0,1fr)]">
            <div class="space-y-4">
                <ConnectionList
                    :connections="state.connections"
                    :selected-id="selectedId"
                    @select="selectedId = $event"
                    @changed="onConnectionChanged"
                    @removed="onConnectionRemoved"
                />
                <ConnectionForm :panel-url="state.panel_url" @created="onCreated" />
            </div>

            <div class="min-w-0">
                <div v-if="!selected" class="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500 dark:border-slate-700">
                    {{ t('panelLink.noConnection') }}
                </div>
                <template v-else>
                    <div class="flex gap-1 rounded-xl bg-slate-100 p-1 text-sm font-medium dark:bg-slate-800">
                        <button
                            v-for="item in ['pull', 'push']"
                            :key="item"
                            type="button"
                            class="flex-1 rounded-lg px-3 py-2 transition"
                            :class="tab === item ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-900 dark:text-slate-100' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400'"
                            @click="tab = item"
                        >
                            <i :class="item === 'pull' ? 'bi bi-cloud-download' : 'bi bi-cloud-upload'" class="mr-1"></i>
                            {{ item === 'pull' ? t('panelLink.pullTab', { name: selected.name }) : t('panelLink.pushTab', { name: selected.name }) }}
                        </button>
                    </div>
                    <PullPanel v-if="tab === 'pull'" :key="`pull-${selected.id}`" class="mt-4" :connection="selected" :local-users="state.local_users" @started="onStarted" />
                    <PushPanel v-else :key="`push-${selected.id}`" class="mt-4" :connection="selected" :local-websites="state.local_websites" @started="onStarted" />
                </template>
            </div>
        </div>

        <TransferList class="mt-6" :transfers="state.transfers" :connections="state.connections" @started="onStarted" @changed="refreshTransfers" />
    </section>
</template>
