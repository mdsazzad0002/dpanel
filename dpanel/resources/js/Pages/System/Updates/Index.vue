<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { usePanelApi } from '@/composables/usePanelApi';
import VersionCard from './components/VersionCard.vue';
import ReleaseNotes from './components/ReleaseNotes.vue';
import RunProgress from './components/RunProgress.vue';
import UpdateHistory from './components/UpdateHistory.vue';
import RollbackDialog from './components/RollbackDialog.vue';

// System → Updates (backlog U6). drust runs the update in its own unit; this
// page starts it and polls, and keeps polling while the panel and drust restart.
const props = defineProps({
    current: { type: Object, required: true },
    repo: { type: String, default: '' },
    runs: { type: Array, default: () => [] },
    active: { type: String, default: null },
    drustError: { type: String, default: null },
});
const { t } = useI18n();
const { panelRoute, errorMessage } = usePanelApi(t);

const runs = ref(props.runs);
const active = ref(props.active);
const check = ref(null);
const checking = ref(false);
const checkError = ref('');
const busy = ref(false);
const error = ref(props.drustError || '');
const notice = ref('');
const shownId = ref(props.active);
const log = ref('');
const offset = ref(0);
const unreachable = ref(false);
const rollbackRun = ref(null);
let timer = null;
let stopped = false;

const shown = computed(() => runs.value.find((run) => run.run_id === shownId.value) || null);
const running = computed(() => Boolean(active.value));

const loadCheck = async (refresh) => {
    checking.value = true;
    checkError.value = '';
    try {
        const { data } = await window.axios.get(panelRoute('system.updates.check'), { params: refresh ? { refresh: 1 } : {} });
        check.value = data;
    } catch (e) {
        checkError.value = errorMessage(e, t('panelUpdate.checkFailed'));
    } finally {
        checking.value = false;
    }
};

const schedule = () => {
    clearTimeout(timer);
    if (!stopped) timer = setTimeout(poll, 3000);
};

const poll = async () => {
    const from = offset.value;
    const params = shownId.value ? { run: shownId.value, offset: from } : {};
    try {
        const { data } = await window.axios.get(panelRoute('system.updates.status'), { params });
        unreachable.value = false;
        runs.value = data.runs;
        active.value = data.active;
        if (data.log && data.log.run === shownId.value && from === offset.value) {
            log.value = data.log.offset < from ? data.log.text : log.value + data.log.text;
            offset.value = data.log.offset;
        }
    } catch (e) {
        // The panel or drust is restarting as part of the update.
        unreachable.value = true;
    }
    if (active.value || (shown.value && ['queued', 'running'].includes(shown.value.status))) schedule();
};

const show = (run) => {
    shownId.value = run.run_id;
    log.value = '';
    offset.value = 0;
    poll();
};

const start = async (url, body, fallback) => {
    busy.value = true;
    error.value = '';
    notice.value = '';
    try {
        const { data } = await window.axios.post(url, body);
        notice.value = data.message;
        active.value = data.run.run_id;
        runs.value = [data.run, ...runs.value.filter((run) => run.run_id !== data.run.run_id)];
        rollbackRun.value = null;
        show(data.run);
    } catch (e) {
        error.value = errorMessage(e, fallback);
    } finally {
        busy.value = false;
    }
};

const update = (target) => start(panelRoute('system.updates.update'), { target_ref: target }, t('panelUpdate.startFailed'));
const rollback = (choice) => start(panelRoute('system.updates.rollback'), choice, t('panelUpdate.startFailed'));

onMounted(() => {
    loadCheck(false);
    if (shownId.value) poll();
});
onBeforeUnmount(() => {
    stopped = true;
    clearTimeout(timer);
});
</script>

<template>
    <Head :title="t('panelUpdate.title')" />
    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">{{ t('panelUpdate.title') }}</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ t('panelUpdate.help') }}</p>
            </div>
        </template>

        <div class="max-w-5xl space-y-4">
            <div v-if="error" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">{{ error }}</div>
            <div v-if="notice" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-300">{{ notice }}</div>
            <div v-if="running && !shown" class="rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-700 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-300">{{ t('panelUpdate.alreadyRunning') }}</div>

            <VersionCard :current="current" :repo="repo" :check="check" :checking="checking" :check-error="checkError" :busy="busy || running" @refresh="loadCheck(true)" @update="update" />
            <RunProgress v-if="shown" :run="shown" :log="log" :unreachable="unreachable" @close="shownId = null" />
            <ReleaseNotes :check="check" />
            <UpdateHistory :runs="runs" :busy="busy || running" @show="show" @rollback="(run) => (rollbackRun = run)" />
        </div>

        <RollbackDialog v-if="rollbackRun" :run="rollbackRun" :busy="busy" @confirm="rollback" @cancel="rollbackRun = null" />
    </AuthenticatedLayout>
</template>
