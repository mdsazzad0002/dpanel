<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { usePanelApi } from '@/composables/usePanelApi';

/**
 * One restore point (backlog W7): pick a file or folder, a database or a
 * mailbox to bring back. Files land in a new restored-… folder, mail in a
 * new Restored-… mail folder, and a database is saved before it is replaced.
 */
const props = defineProps({
    website: { type: Object, required: true },
    point: { type: Object, required: true },
    databases: { type: Array, default: () => [] },
    mailboxes: { type: Array, default: () => [] },
});

const { t } = useI18n();
const { panelRoute, errorMessage } = usePanelApi(t);
const params = { website: props.website.id, snapshot: props.point.id };

const path = ref(props.website.home);
const entries = ref([]);
const loading = ref(false);
const error = ref('');
const restore = ref(null);
let poll = null;

const atHome = computed(() => path.value === props.website.home);
const relative = (value) => value.startsWith(props.website.home) ? `~${value.slice(props.website.home.length)}` : value;
const sorted = computed(() => [...entries.value].sort((a, b) => (a.type === 'dir' ? 0 : 1) - (b.type === 'dir' ? 0 : 1) || String(a.name).localeCompare(String(b.name))));
const busy = computed(() => restore.value && ['queued', 'running'].includes(restore.value.stage));

const open = async (target) => {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await window.axios.post(panelRoute('backups.incremental.browse', params), { path: target });
        path.value = data.path;
        entries.value = data.entries || [];
    } catch (e) {
        error.value = errorMessage(e, t('incrementalRestore.browseFailed'));
    } finally {
        loading.value = false;
    }
};
const up = () => open(path.value.replace(/\/[^/]+$/, '') || props.website.home);

const watch = (restoreId) => {
    clearInterval(poll);
    poll = setInterval(async () => {
        try {
            const { data } = await window.axios.get(panelRoute('backups.incremental.restore.status', { restore: restoreId }));
            restore.value = data;
            if (!['queued', 'running'].includes(data.stage)) clearInterval(poll);
        } catch {
            clearInterval(poll);
        }
    }, 2000);
};

const start = async (body, question) => {
    if (busy.value || !window.confirm(question)) return;
    error.value = '';
    try {
        const { data } = await window.axios.post(panelRoute('backups.incremental.restore', params), body);
        restore.value = { stage: 'queued', message: data.message };
        watch(data.restore_id);
    } catch (e) {
        error.value = errorMessage(e, t('incrementalRestore.failed'));
    }
};
const restoreFiles = (target) => start({ kind: 'files', path: target }, t('incrementalRestore.confirmFiles', { path: relative(target) }));
const restoreDatabase = (name) => start({ kind: 'database', name }, t('incrementalRestore.confirmDatabase', { name }));
const restoreMailbox = (mailbox) => start({ kind: 'mailbox', path: mailbox.path }, t('incrementalRestore.confirmMailbox', { email: mailbox.email }));

const bytes = (value) => {
    if (!value) return '';
    const units = ['B', 'KB', 'MB', 'GB'];
    let size = Number(value);
    let unit = 0;
    while (size >= 1024 && unit < units.length - 1) { size /= 1024; unit++; }
    return `${size.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`;
};

onMounted(() => open(props.website.home));
onBeforeUnmount(() => clearInterval(poll));

const card = 'rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50';
const small = 'rounded-lg border px-3 py-1.5 text-xs font-semibold disabled:opacity-50';
</script>

<template>
    <Head :title="t('incrementalRestore.title')" />
    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">{{ t('incrementalRestore.title') }} · {{ website.domain }}</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    {{ t('incrementalRestore.subtitle', { when: new Date(point.taken_at).toLocaleString(), id: point.short_id }) }}
                    · <Link :href="route('backups.incremental')" class="text-blue-600 hover:underline">{{ t('incrementalRestore.back') }}</Link>
                </p>
            </div>
        </template>

        <div class="max-w-4xl space-y-5">
            <div v-if="error" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">{{ error }}</div>
            <div v-if="restore" :class="restore.stage === 'failed' ? 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300' : restore.stage === 'completed' ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-300' : 'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-300'" class="rounded-xl border px-4 py-3 text-sm">
                <i v-if="busy" class="bi bi-arrow-repeat mr-2 inline-block animate-spin"></i>{{ restore.message }}
            </div>

            <section :class="card">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-100">{{ t('incrementalRestore.files') }}</h2>
                    <button type="button" :disabled="busy || loading" class="border-teal-200 text-teal-700 hover:bg-teal-50 dark:border-teal-800 dark:text-teal-300" :class="small" @click="restoreFiles(path)">{{ atHome ? t('incrementalRestore.restoreEverything') : t('incrementalRestore.restoreFolder') }}</button>
                </div>
                <p class="mt-1 text-xs text-slate-500">{{ t('incrementalRestore.filesHelp') }}</p>
                <div class="mt-3 flex items-center gap-2 font-mono text-xs text-slate-600 dark:text-slate-300">
                    <button v-if="!atHome" type="button" class="rounded border border-slate-200 px-2 py-0.5 dark:border-slate-700" :title="t('incrementalRestore.up')" @click="up"><i class="bi bi-arrow-up"></i></button>
                    <span class="break-all">{{ relative(path) }}</span>
                    <i v-if="loading" class="bi bi-arrow-repeat animate-spin"></i>
                </div>
                <ul class="mt-2 divide-y divide-slate-100 text-sm dark:divide-slate-800">
                    <li v-for="entry in sorted" :key="entry.path" class="flex items-center justify-between gap-2 py-1.5">
                        <button v-if="entry.type === 'dir'" type="button" class="flex min-w-0 items-center gap-2 text-left text-blue-700 hover:underline dark:text-blue-300" @click="open(entry.path)"><i class="bi bi-folder2"></i><span class="truncate">{{ entry.name }}</span></button>
                        <span v-else class="flex min-w-0 items-center gap-2"><i class="bi" :class="entry.type === 'symlink' ? 'bi-link-45deg' : 'bi-file-earmark'"></i><span class="truncate">{{ entry.name }}</span><span class="text-xs text-slate-400">{{ bytes(entry.size) }}</span></span>
                        <button v-if="entry.type === 'dir' || entry.type === 'file'" type="button" :disabled="busy" class="shrink-0 border-slate-200 text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300" :class="small" @click="restoreFiles(entry.path)">{{ t('incrementalRestore.restore') }}</button>
                    </li>
                    <li v-if="!loading && !entries.length" class="py-2 text-xs text-slate-500">{{ t('incrementalRestore.empty') }}</li>
                </ul>
            </section>

            <section :class="card">
                <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-100">{{ t('incrementalRestore.databases') }}</h2>
                <p class="mt-1 text-xs text-slate-500">{{ t('incrementalRestore.databasesHelp') }}</p>
                <ul v-if="databases.length" class="mt-2 divide-y divide-slate-100 text-sm dark:divide-slate-800">
                    <li v-for="name in databases" :key="name" class="flex items-center justify-between py-1.5">
                        <span class="font-mono">{{ name }}</span>
                        <button type="button" :disabled="busy" class="border-amber-200 text-amber-700 hover:bg-amber-50 dark:border-amber-800 dark:text-amber-300" :class="small" @click="restoreDatabase(name)">{{ t('incrementalRestore.restore') }}</button>
                    </li>
                </ul>
                <p v-else class="mt-2 text-xs text-slate-500">{{ t('incrementalRestore.noDatabases') }}</p>
            </section>

            <section :class="card">
                <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-100">{{ t('incrementalRestore.mailboxes') }}</h2>
                <p class="mt-1 text-xs text-slate-500">{{ t('incrementalRestore.mailboxesHelp') }}</p>
                <ul v-if="mailboxes.length" class="mt-2 divide-y divide-slate-100 text-sm dark:divide-slate-800">
                    <li v-for="mailbox in mailboxes" :key="mailbox.path" class="flex items-center justify-between py-1.5">
                        <span>{{ mailbox.email }}</span>
                        <button type="button" :disabled="busy" class="border-slate-200 text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300" :class="small" @click="restoreMailbox(mailbox)">{{ t('incrementalRestore.restore') }}</button>
                    </li>
                </ul>
                <p v-else class="mt-2 text-xs text-slate-500">{{ t('incrementalRestore.noMailboxes') }}</p>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
