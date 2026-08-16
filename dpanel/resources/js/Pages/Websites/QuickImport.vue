<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    website: { type: Object, required: true },
    imports: { type: Array, default: () => [] },
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

const submitGeneric = async () => {
    const overwriteDatabase = !!generic.database && databaseTarget.value !== 'new';
    const targetDatabase = databases.value.find((entry) => entry.id === databaseTarget.value);
    if (overwriteDatabase && !window.confirm(`Database ${targetDatabase?.database_name || ''} already exists. A backup will be created first. Continue and overwrite it?`)) return;
    uploading.value = true; error.value = ''; message.value = '';
    Object.values(uploadStages).forEach(stage => { stage.progress = 0; stage.status = 'waiting'; });
    const routeParams = { token: panelToken.value, id: props.website.id };
    try {
        const response = await axios.post(route('websites.import.store', routeParams), {
            archive_name: generic.archive.name, archive_size: generic.archive.size,
            database_name_file: generic.database?.name || null, database_size: generic.database?.size || null,
            database_id: generic.database && databaseTarget.value !== 'new' ? databaseTarget.value : null,
            new_database_name: generic.database && databaseTarget.value === 'new' ? newDatabaseName.value : null,
            overwrite_database: overwriteDatabase,
        });
        trackingId.value = response.data.tracking_id;
        if (generic.database) await uploadTrackedFile(generic.database, 'database', trackingId.value);
        else { uploadStages.database.progress = 100; uploadStages.database.status = 'skipped'; }
        await uploadTrackedFile(generic.archive, 'archive', trackingId.value);
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
</script>

<template>
    <Head :title="`Quick Import - ${website.domain}`" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h1 class="text-lg font-semibold">Quick Import</h1>
                    <p class="text-sm text-slate-500">{{ website.domain }} — files and database are restored into this website</p>
                </div>
                <Link :href="panelRoute('websites.manage', { id: website.id })" class="rounded-lg border px-3 py-2 text-sm dark:border-slate-700">
                    <i class="bi bi-arrow-left mr-1"></i> Website dashboard
                </Link>
            </div>
        </template>

        <div class="mx-auto space-y-6 p-4 sm:p-6">
            <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900/50">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Import into {{ website.domain }}</h3>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Upload website files and an optional SQL dump. Existing database credentials for this website are selected automatically—no connection form is required.</p>
                <form class="mt-6 grid gap-4 md:grid-cols-2" @submit.prevent="submitGeneric">
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-300">Website files<input required type="file" accept=".zip,.gz,.tgz,application/zip,application/gzip" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white p-2 dark:border-slate-700 dark:bg-slate-800" @change="generic.archive = $event.target.files[0]" /></label>
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-300">Database dump <span class="font-normal text-slate-400">(optional)</span><input type="file" accept=".sql,application/sql,text/plain" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white p-2 dark:border-slate-700 dark:bg-slate-800" @change="generic.database = $event.target.files[0]" /><span class="mt-1 block text-xs font-normal text-slate-500">Automatically imports into this website's active database.</span></label>
                    <div v-if="!generic.database" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-300 md:col-span-2">
                        <template v-if="databaseConnection?.available"><i class="bi bi-database-check mr-1 text-emerald-500"></i>{{ databases.length > 1 ? `${databases.length} databases are linked to this website — choose a database dump to pick which one to replace.` : `Existing database ${databaseConnection.database_name} requires confirmation and will be backed up before overwrite.` }}</template>
                        <template v-else><i class="bi bi-database-add mr-1 text-indigo-500"></i>No active database exists. Uploading SQL will automatically create and connect one.</template>
                    </div>
                    <div v-if="generic.database" class="space-y-2 rounded-lg border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-800/60 md:col-span-2">
                        <span class="block text-xs font-semibold text-slate-700 dark:text-slate-200">Database target</span>
                        <label v-for="db in databases" :key="db.id" class="flex cursor-pointer items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900/50">
                            <input v-model="databaseTarget" :value="db.id" type="radio" class="text-indigo-600 focus:ring-indigo-500" />
                            <span class="text-slate-700 dark:text-slate-200">Replace <b>{{ db.database_name }}</b></span>
                        </label>
                        <label class="flex cursor-pointer items-start gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900/50">
                            <input v-model="databaseTarget" value="new" type="radio" class="mt-0.5 text-indigo-600 focus:ring-indigo-500" />
                            <span class="min-w-0 flex-1">
                                <span class="block text-slate-700 dark:text-slate-200">Create new database</span>
                                <input v-if="databaseTarget === 'new'" v-model="newDatabaseName" type="text" class="mt-1.5 block w-full rounded-lg border border-slate-300 bg-white p-1.5 text-xs dark:border-slate-700 dark:bg-slate-800" placeholder="database name" />
                            </span>
                        </label>
                    </div>
                    <div v-if="uploading || trackingId" class="space-y-3 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-800/50 md:col-span-2">
                        <div class="flex flex-wrap items-center justify-between gap-2"><h4 class="text-sm font-semibold text-slate-800 dark:text-slate-200">Tracked import pipeline</h4><code v-if="trackingId" class="rounded bg-slate-200 px-2 py-1 text-xs text-slate-600 dark:bg-slate-700 dark:text-slate-300">{{ trackingId }}</code></div>
                        <div v-for="(stage, key) in uploadStages" :key="key">
                            <div class="mb-1 flex justify-between text-xs"><span class="font-semibold capitalize text-slate-600 dark:text-slate-300">{{ key === 'archive' ? 'Website files' : key }}</span><span class="text-slate-500">{{ stage.status }} · {{ stage.progress }}%</span></div>
                            <div class="h-2 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700"><div class="h-full rounded-full transition-all duration-200" :class="stage.status === 'ready' || stage.status === 'queued' || stage.status === 'skipped' ? 'bg-emerald-500' : 'bg-indigo-500'" :style="{ width: `${stage.progress}%` }"></div></div>
                        </div>
                    </div>
                    <div class="md:col-span-2"><button :disabled="uploading" class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50">{{ uploading ? 'Starting…' : 'Upload & migrate website' }}</button></div>
                </form>
            </section>

            <div v-if="message" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">{{ message }}</div>
            <div v-if="error" class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-800 dark:bg-red-500/10 dark:text-red-300">{{ error }}</div>

            <div v-if="total" class="pt-2"><h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Import history</h3></div>
            <section v-for="item in imports" :key="item.id" class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900/50">
                <div class="flex items-center justify-between gap-3"><div><h4 class="font-semibold dark:text-slate-100">{{ item.inventory?.domain || item.original_name }}</h4><p class="text-sm text-slate-500 dark:text-slate-400">{{ item.original_name }}</p></div><span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold dark:bg-slate-800">{{ item.status }}</span></div>
                <p v-if="item.last_error" class="mt-3 text-sm text-red-600 dark:text-red-400">{{ item.last_error }}</p>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
