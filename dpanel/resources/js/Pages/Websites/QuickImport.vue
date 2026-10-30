<script setup>
import { computed, onBeforeUnmount, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import DatabaseTargetPicker from './components/DatabaseTargetPicker.vue';
import JobPipeline from './components/JobPipeline.vue';

const props = defineProps({
    website: { type: Object, required: true },
    imports: { type: Array, default: () => [] },
    otherWebsites: { type: Array, default: () => [] },
    databaseConnection: { type: Object, default: () => ({ available: false, database_name: null, databases: [] }) },
    suggestedDatabaseName: { type: String, default: '' },
});

const page = usePage();
const panelToken = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => (
    panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params)
);

const uploading = ref(false);
const message = ref('');
const error = ref('');

const generic = reactive({ archive: null, database: null });
const trackingId = ref('');
const uploadStages = reactive({ database: { progress: 0, status: 'waiting' }, archive: { progress: 0, status: 'waiting' }, connect: { progress: 0, status: 'waiting' } });

// Existing databases linked to this website's domain — the first one is
// preselected as the replace target; "new" means create a fresh database
// instead, using the auto-filled (but editable) suggested name.
const databases = computed(() => props.databaseConnection?.databases || []);
const databaseTarget = ref(databases.value.length ? databases.value[0].id : 'new');
const newDatabaseName = ref(props.suggestedDatabaseName);
// The panel still lists a database whose MySQL database is gone — importing
// creates it, so there is nothing to overwrite or confirm.
const primaryDatabaseMissing = computed(() => !!databases.value[0]?.missing_on_server);

// Clone and share-link import: replace one of this website's databases, or
// create a new one. The list is loaded from the server once a source is
// chosen, so it reflects what exists right now rather than page-load state.
const liveDatabases = ref([]);
const liveDatabasesStatus = ref('idle'); // idle | loading | loaded | error
const loadLiveDatabases = async () => {
    if (liveDatabasesStatus.value === 'loading') return;
    liveDatabasesStatus.value = 'loading';
    try {
        const { data } = await axios.get(panelRoute('websites.import.databases', { id: props.website.id }));
        liveDatabases.value = data.databases || [];
        liveDatabasesStatus.value = 'loaded';
        for (const choice of [cloneDatabase, importDatabase]) {
            if (choice.target !== 'new' && !liveDatabases.value.some((db) => db.id === choice.target)) choice.target = '';
            if (!choice.target) choice.target = liveDatabases.value[0]?.id || 'new';
            if (!choice.newName) choice.newName = data.suggested_database_name || '';
        }
    } catch {
        liveDatabasesStatus.value = 'error';
    }
};
const cloneDatabase = reactive({ target: '', newName: '' });
const importDatabase = reactive({ target: '', newName: '' });
const chosenDatabase = (choice) => liveDatabases.value.find((db) => db.id === choice.target);
const cloneTargetDatabase = computed(() => chosenDatabase(cloneDatabase));
const importTargetDatabase = computed(() => chosenDatabase(importDatabase));
const confirmDatabaseOverwrite = (db) => !db || db.missing_on_server
    || window.confirm(`Database ${db.database_name} will be overwritten with the incoming database. A backup is taken first. Continue?`);

const submitGeneric = async () => {
    if (!generic.archive && !generic.database) { error.value = 'Choose website files, a database dump, or both.'; return; }
    const overwriteDatabase = !!generic.database && databaseTarget.value !== 'new';
    const targetDatabase = databases.value.find((entry) => entry.id === databaseTarget.value);
    if (overwriteDatabase && !targetDatabase?.missing_on_server && !window.confirm(`Database ${targetDatabase?.database_name || ''} already exists. A backup will be created first. Continue and overwrite it?`)) return;
    uploading.value = true; error.value = ''; message.value = '';
    Object.values(uploadStages).forEach(stage => { stage.progress = 0; stage.status = 'waiting'; });
    const routeParams = { token: panelToken.value, id: props.website.id };
    try {
        const response = await axios.post(route('websites.import.store', routeParams), {
            archive_name: generic.archive?.name || null, archive_size: generic.archive?.size || null,
            database_name_file: generic.database?.name || null, database_size: generic.database?.size || null,
            database_id: generic.database && databaseTarget.value !== 'new' ? databaseTarget.value : null,
            new_database_name: generic.database && databaseTarget.value === 'new' ? newDatabaseName.value : null,
            overwrite_database: overwriteDatabase,
        });
        trackingId.value = response.data.tracking_id;
        if (generic.database) await uploadTrackedFile(generic.database, 'database', trackingId.value);
        else { uploadStages.database.progress = 100; uploadStages.database.status = 'skipped'; }
        if (generic.archive) await uploadTrackedFile(generic.archive, 'archive', trackingId.value);
        else { uploadStages.archive.progress = 100; uploadStages.archive.status = 'skipped'; }
        uploadStages.connect.status = 'connecting'; uploadStages.connect.progress = 50;
        const connected = await axios.post(route('websites.import.connect', { ...routeParams, tracking: trackingId.value }));
        uploadStages.connect.status = 'queued'; uploadStages.connect.progress = 100; message.value = connected.data.message;
        router.reload({ only: ['imports'], preserveScroll: true });
    }
    catch (e) { error.value = e.response?.data?.message || Object.values(e.response?.data?.errors || {})?.[0]?.[0] || 'Migration could not be started.'; }
    finally { uploading.value = false; }
};

const uploadTrackedFile = async (file, kind, tracking) => {
    const chunkSize = 5 * 1024 * 1024;
    const total = Math.ceil(file.size / chunkSize);
    const params = { token: panelToken.value, id: props.website.id, tracking, kind };
    uploadStages[kind].status = 'uploading';
    for (let index = 0; index < total; index++) {
        const form = new FormData(); form.append('index', index); form.append('total', total);
        form.append('chunk', file.slice(index * chunkSize, Math.min(file.size, (index + 1) * chunkSize)), `${kind}-${index}.part`);
        let lastError;
        for (let attempt = 0; attempt < 3; attempt++) {
            try {
                await axios.post(route('websites.import.chunk', params), form, { onUploadProgress: event => {
                    const current = event.total ? event.loaded / event.total : 0;
                    uploadStages[kind].progress = Math.min(99, Math.round(((index + current) / total) * 100));
                }});
                lastError = null; break;
            } catch (uploadError) { lastError = uploadError; }
        }
        if (lastError) throw lastError;
    }
    await axios.post(route('websites.import.complete', params), { total });
    uploadStages[kind].progress = 100; uploadStages[kind].status = 'ready';
};

const total = computed(() => props.imports.length);
const hasBackgroundWork = computed(() => props.imports.some(item => ['inspecting', 'restoring'].includes(item.status)));
let refreshTimer = null;
onMounted(() => {
    refreshTimer = window.setInterval(() => {
        if (hasBackgroundWork.value) router.reload({ only: ['imports'], preserveScroll: true });
    }, 3000);
});
onBeforeUnmount(() => window.clearInterval(refreshTimer));

// ---- Clone from (pull another website on this server into this website) ----
const csrfToken = computed(() => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');
const toasts = ref([]);
let toastSeq = 0;
const pushToast = (toastMessage, type = 'success') => {
    const id = ++toastSeq;
    toasts.value.push({ id, message: toastMessage, type });
    window.setTimeout(() => {
        toasts.value = toasts.value.filter((toast) => toast.id !== id);
    }, 4000);
};

const postJson = async (url, body) => {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken.value,
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(body),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || !data.ok) {
        throw new Error(data.message || 'Request failed.');
    }
    return data;
};

const otherWebsiteOptions = computed(() => props.otherWebsites.map((site) => ({ value: site.id, label: site.domain })));

const sourceWebsiteId = ref('');
// Blocks that appear as the form fills in fade/slide in instead of popping.
const reveal = {
    enterActiveClass: 'transition duration-300 ease-out',
    enterFromClass: 'opacity-0 -translate-y-1',
    leaveActiveClass: 'transition duration-150 ease-in',
    leaveToClass: 'opacity-0 -translate-y-1',
};
watch(sourceWebsiteId, (id) => { if (id) loadLiveDatabases(); });
const pullRunning = ref(false);
const pullStage = ref(null);
const pullJobId = ref('');
const pullFailedAt = ref(null);
watch(pullStage, (stage, previous) => { if (stage === 'failed') pullFailedAt.value = previous; });
const CLONE_STEPS = [
    { key: 'queued', label: 'Queued' },
    { key: 'archiving', label: 'Website files' },
    { key: 'exporting_database', label: 'Database export' },
    { key: 'backing_up_target', label: 'Backup of this website' },
    { key: 'restoring', label: 'Restore into this website' },
];
const pullMessage = ref('');
let pullPollTimer = null;

const stopPullPoll = () => {
    window.clearInterval(pullPollTimer);
    pullPollTimer = null;
};

const pollPullStatus = async (cloneId) => {
    try {
        const response = await fetch(panelRoute('websites.clone-share.status', { id: props.website.id, jobId: cloneId }), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.ok) return;
        pullStage.value = data.stage;
        if (data.stage === 'ready') {
            stopPullPoll();
            pullRunning.value = false;
            pushToast('Clone completed — this website now has the cloned content.');
        } else if (data.stage === 'failed') {
            stopPullPoll();
            pullRunning.value = false;
            pullMessage.value = data.message || 'Clone failed.';
            pushToast(pullMessage.value, 'error');
        }
    } catch {
        // Network hiccup — next tick retries.
    }
};

const startPull = async () => {
    if (pullRunning.value || !sourceWebsiteId.value) return;
    if (liveDatabasesStatus.value !== 'loaded' || !confirmDatabaseOverwrite(cloneTargetDatabase.value)) return;
    pullRunning.value = true;
    pullStage.value = 'queued';
    pullMessage.value = '';
    try {
        const data = await postJson(panelRoute('websites.clone-share.clone', { id: sourceWebsiteId.value }), {
            target_website_id: props.website.id,
            database_target: cloneDatabase.target,
            new_database_name: cloneDatabase.target === 'new' ? cloneDatabase.newName : null,
        });
        stopPullPoll();
        pullJobId.value = data.clone_id;
        pullPollTimer = window.setInterval(() => pollPullStatus(data.clone_id), 5000);
        pollPullStatus(data.clone_id);
    } catch (error) {
        pullRunning.value = false;
        pullStage.value = 'failed';
        pullMessage.value = error?.message || 'Failed to start clone.';
        pushToast(pullMessage.value, 'error');
    }
};

// ---- Import from another server's share link ----
const importUrl = ref('');
watch(importUrl, (url) => { if (/^https?:\/\/\S+/.test(url.trim())) loadLiveDatabases(); });
const importRunning = ref(false);
const importStage = ref(null);
const importJobId = ref('');
const importFailedAt = ref(null);
watch(importStage, (stage, previous) => { if (stage === 'failed') importFailedAt.value = previous; });
const IMPORT_STEPS = [
    { key: 'queued', label: 'Queued' },
    { key: 'downloading', label: 'Download package' },
    { key: 'restoring', label: 'Restore into this website' },
];
const importMessage = ref('');
let importPollTimer = null;

const stopImportPoll = () => {
    window.clearInterval(importPollTimer);
    importPollTimer = null;
};

const pollImportStatus = async (cloneId) => {
    try {
        const response = await fetch(panelRoute('websites.clone-share.status', { id: props.website.id, jobId: cloneId }), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.ok) return;
        importStage.value = data.stage;
        if (data.stage === 'ready') {
            stopImportPoll();
            importRunning.value = false;
            pushToast('Import completed — this website now has the cloned content.');
        } else if (data.stage === 'failed') {
            stopImportPoll();
            importRunning.value = false;
            importMessage.value = data.message || 'Import failed.';
            pushToast(importMessage.value, 'error');
        }
    } catch {
        // Network hiccup — next tick retries.
    }
};

const startUrlImport = async () => {
    if (importRunning.value || !importUrl.value) return;
    if (liveDatabasesStatus.value !== 'loaded' || !confirmDatabaseOverwrite(importTargetDatabase.value)) return;
    importRunning.value = true;
    importStage.value = 'queued';
    importMessage.value = '';
    try {
        const data = await postJson(panelRoute('websites.clone-share.import', { id: props.website.id }), {
            source_url: importUrl.value,
            database_target: importDatabase.target,
            new_database_name: importDatabase.target === 'new' ? importDatabase.newName : null,
        });
        stopImportPoll();
        importJobId.value = data.clone_id;
        importPollTimer = window.setInterval(() => pollImportStatus(data.clone_id), 5000);
        pollImportStatus(data.clone_id);
    } catch (error) {
        importRunning.value = false;
        importStage.value = 'failed';
        importMessage.value = error?.message || 'Failed to start import.';
        pushToast(importMessage.value, 'error');
    }
};

onUnmounted(() => {
    stopPullPoll();
    stopImportPoll();
});
</script>

<template>
    <Head :title="`Import & Clone - ${website.domain}`" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h1 class="text-lg font-semibold">Import & Clone</h1>
                    <p class="text-sm text-slate-500">{{ website.domain }} — files and database are restored into this website</p>
                </div>
                <Link :href="panelRoute('websites.manage', { id: website.id })" class="rounded-lg border px-3 py-2 text-sm dark:border-slate-700">
                    <i class="bi bi-arrow-left mr-1"></i> Website dashboard
                </Link>
            </div>
        </template>

        <div class="mx-auto grid gap-6  lg:grid-cols-2">
            <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900/50">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Import into {{ website.domain }}</h3>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Upload website files, a database dump, or both — either one alone is fine. Files upload in chunks, so phpMyAdmin's PHP upload-size limit doesn't apply here. Existing database credentials for this website are selected automatically—no connection form is required.</p>
                <form class="mt-6 grid gap-4 md:grid-cols-2" @submit.prevent="submitGeneric">
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-300">Website files <span class="font-normal text-slate-400">(optional)</span><input type="file" accept=".zip,.gz,.tgz,application/zip,application/gzip" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white p-2 dark:border-slate-700 dark:bg-slate-800" @change="generic.archive = $event.target.files[0]" /></label>
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-300">Database dump <span class="font-normal text-slate-400">(optional)</span><input type="file" accept=".sql,application/sql,text/plain" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white p-2 dark:border-slate-700 dark:bg-slate-800" @change="generic.database = $event.target.files[0]" /><span class="mt-1 block text-xs font-normal text-slate-500">Automatically imports into this website's active database. Leave "Website files" empty to import the database only.</span></label>
                    <div v-if="!generic.database" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-300 md:col-span-2">
                        <template v-if="databaseConnection?.available && databases.length === 1 && primaryDatabaseMissing"><i class="bi bi-database-exclamation mr-1 text-amber-500"></i>Database {{ databaseConnection.database_name }} is linked to this website but doesn't exist on the server. Uploading SQL will create it and import into it.</template>
                        <template v-else-if="databaseConnection?.available"><i class="bi bi-database-check mr-1 text-emerald-500"></i>{{ databases.length > 1 ? `${databases.length} databases are linked to this website — choose a database dump to pick which one to replace.` : `Existing database ${databaseConnection.database_name} requires confirmation and will be backed up before overwrite.` }}</template>
                        <template v-else><i class="bi bi-database-add mr-1 text-indigo-500"></i>No active database exists. Uploading SQL will automatically create and connect one.</template>
                    </div>
                    <Transition v-bind="reveal">
                        <DatabaseTargetPicker v-if="generic.database" v-model:target="databaseTarget" v-model:new-name="newDatabaseName" :databases="databases" name="upload-database-target" class="md:col-span-2" />
                    </Transition>
                    <div v-if="uploading || trackingId" class="space-y-3 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-800/50 md:col-span-2">
                        <div class="flex flex-wrap items-center justify-between gap-2"><h4 class="text-sm font-semibold text-slate-800 dark:text-slate-200">Tracked import pipeline</h4><code v-if="trackingId" class="rounded bg-slate-200 px-2 py-1 text-xs text-slate-600 dark:bg-slate-700 dark:text-slate-300">{{ trackingId }}</code></div>
                        <div v-for="(stage, key) in uploadStages" :key="key">
                            <div class="mb-1 flex justify-between text-xs"><span class="font-semibold capitalize text-slate-600 dark:text-slate-300">{{ key === 'archive' ? 'Website files' : key }}</span><span class="text-slate-500">{{ stage.status }} · {{ stage.progress }}%</span></div>
                            <div class="h-2 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700"><div class="h-full rounded-full transition-all duration-200" :class="stage.status === 'ready' || stage.status === 'queued' || stage.status === 'skipped' ? 'bg-emerald-500' : 'bg-indigo-500'" :style="{ width: `${stage.progress}%` }"></div></div>
                        </div>
                    </div>
                    <div class="md:col-span-2"><button :disabled="uploading || (!generic.archive && !generic.database)" class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50">{{ uploading ? 'Starting…' : 'Upload & migrate' }}</button></div>
                </form>
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900/50">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Clone from another website</h3>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Pull another website's files and database from this server into {{ website.domain }} — {{ website.domain }} is always the destination. The source website is never changed.</p>
                <form class="mt-6 grid gap-4" @submit.prevent="startPull">
                    <div class="text-sm font-medium text-slate-700 dark:text-slate-300">
                        Clone from
                        <SearchableSelect v-model="sourceWebsiteId" class="mt-1 font-normal" :options="otherWebsiteOptions" :disabled="pullRunning"
                            placeholder="Select a website you own…" search-placeholder="Search websites…" />
                        <span v-if="otherWebsites.length === 0" class="mt-1 block text-xs font-normal text-amber-600">You need at least one other website on this server to clone from.</span>
                    </div>
                    <Transition v-bind="reveal" mode="out-in">
                        <div v-if="!sourceWebsiteId" key="hint" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-300">
                            <i class="bi bi-database mr-1 text-indigo-500"></i>{{ 'Pick a website to clone — then choose which database to replace or create.' }}
                        </div>
                        <div v-else-if="liveDatabasesStatus === 'loading'" key="loading" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-300">
                            <i class="bi bi-arrow-repeat mr-1 inline-block animate-spin"></i>Loading {{ website.domain }}'s databases…
                        </div>
                        <div v-else-if="liveDatabasesStatus === 'error'" key="error" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700 dark:border-red-800 dark:bg-red-500/10 dark:text-red-300">
                            Couldn't load {{ website.domain }}'s databases. <button type="button" class="underline" @click="loadLiveDatabases">Retry</button>
                        </div>
                        <DatabaseTargetPicker v-else-if="liveDatabasesStatus === 'loaded'" key="picker" v-model:target="cloneDatabase.target" v-model:new-name="cloneDatabase.newName" :databases="liveDatabases" :disabled="pullRunning" name="clone-database-target" />
                    </Transition>
                    <Transition v-bind="reveal">
                        <JobPipeline v-if="pullStage" title="Clone pipeline" :job-id="pullJobId" :steps="CLONE_STEPS" :stage="pullStage" :failed-at="pullFailedAt" :message="pullMessage" />
                    </Transition>
                    <div><button :disabled="pullRunning || !sourceWebsiteId || liveDatabasesStatus !== 'loaded'" class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50">{{ pullRunning ? 'Cloning…' : 'Clone & migrate' }}</button></div>
                </form>

                <hr class="my-6 border-slate-200 dark:border-slate-700" />

                <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Import from another server</h3>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Paste a share link generated on another server's Export & Share page to clone it into {{ website.domain }}.</p>
                <form class="mt-6 grid gap-4" @submit.prevent="startUrlImport">
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-300">Share link
                        <input v-model="importUrl" type="url" :disabled="importRunning" placeholder="https://other-server.example.com/clone-share/download/…"
                            class="mt-1 block w-full rounded-lg border border-slate-300 bg-white p-2 font-normal dark:border-slate-700 dark:bg-slate-800" />
                    </label>
                    <Transition v-bind="reveal" mode="out-in">
                        <div v-if="!importUrl" key="hint" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-300">
                            <i class="bi bi-database mr-1 text-indigo-500"></i>{{ 'Paste a share link — then choose which database to replace or create.' }}
                        </div>
                        <div v-else-if="liveDatabasesStatus === 'loading'" key="loading" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-300">
                            <i class="bi bi-arrow-repeat mr-1 inline-block animate-spin"></i>Loading {{ website.domain }}'s databases…
                        </div>
                        <div v-else-if="liveDatabasesStatus === 'error'" key="error" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700 dark:border-red-800 dark:bg-red-500/10 dark:text-red-300">
                            Couldn't load {{ website.domain }}'s databases. <button type="button" class="underline" @click="loadLiveDatabases">Retry</button>
                        </div>
                        <DatabaseTargetPicker v-else-if="liveDatabasesStatus === 'loaded'" key="picker" v-model:target="importDatabase.target" v-model:new-name="importDatabase.newName" :databases="liveDatabases" :disabled="importRunning" name="import-database-target" />
                    </Transition>
                    <Transition v-bind="reveal">
                        <JobPipeline v-if="importStage" title="Import pipeline" :job-id="importJobId" :steps="IMPORT_STEPS" :stage="importStage" :failed-at="importFailedAt" :message="importMessage" />
                    </Transition>
                    <div><button :disabled="importRunning || !importUrl || liveDatabasesStatus !== 'loaded'" class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50">{{ importRunning ? 'Importing…' : 'Import & migrate' }}</button></div>
                </form>
            </section>

            <div v-if="message" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300 lg:col-span-2">{{ message }}</div>
            <div v-if="error" class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-800 dark:bg-red-500/10 dark:text-red-300 lg:col-span-2">{{ error }}</div>

            <div v-if="total" class="pt-2 lg:col-span-2"><h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Import history</h3></div>
            <section v-for="item in imports" :key="item.id" class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900/50 lg:col-span-2">
                <div class="flex items-center justify-between gap-3"><div><h4 class="font-semibold dark:text-slate-100">{{ item.inventory?.domain || item.original_name }}</h4><p class="text-sm text-slate-500 dark:text-slate-400">{{ item.original_name }}</p></div><span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold dark:bg-slate-800">{{ item.status }}</span></div>
                <p v-if="item.last_error" class="mt-3 text-sm text-red-600 dark:text-red-400">{{ item.last_error }}</p>
            </section>
        </div>

        <Teleport to="body">
            <div class="pointer-events-none fixed bottom-4 right-4 z-[80] flex flex-col gap-2">
                <div v-for="toast in toasts" :key="toast.id" class="pointer-events-auto rounded-lg px-4 py-2.5 text-sm font-medium text-white shadow-lg" :class="toast.type === 'error' ? 'bg-red-600' : 'bg-emerald-600'">
                    {{ toast.message }}
                </div>
            </div>
        </Teleport>
    </AuthenticatedLayout>
</template>
