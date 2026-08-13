<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import CyberPanelSshMigration from './components/CyberPanelSshMigration.vue';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';

const props = defineProps({
    provider: { type: String, default: null },
    imports: { type: Array, default: () => [] },
    website: { type: Object, default: null },
    panelToken: { type: String, default: '' },
    databaseConnection: { type: Object, default: () => ({ available: false, database_name: null }) },
    savedSshConnections: { type: Array, default: () => [] },
    phpVersions: { type: Array, default: () => [] },
});
const archive = ref(null);
const uploading = ref(false);
const restoring = ref(null);
const message = ref('');
const error = ref('');
const choices = reactive({});
const services = [
    { id: 'cyberpanel-ssh', name: 'CyberPanel / HestiaCP / aaPanel / Plesk via SSH', description: 'Connect over SSH and discover websites with their databases. Panel type is auto-detected.', icon: 'bi bi-terminal', available: true, formats: 'SSH password or private key' },
    { id: 'cpanel-full', name: 'cPanel Full Backup', description: 'Restore a complete cpmove account or selected resources.', icon: 'bi bi-server', available: true, formats: 'cpmove-USER.tar.gz, .tgz' },
    { id: 'cpanel-partial', name: 'cPanel Partial Backup', description: 'Import Home Directory, MySQL, or domain backups separately.', icon: 'bi bi-ui-checks-grid', available: false, formats: 'Coming soon' },
    { id: 'directadmin', name: 'DirectAdmin Backup', description: 'Migrate users and selected domains from DirectAdmin.', icon: 'bi bi-hdd-rack', available: false, formats: 'Coming soon' },
    { id: 'plesk', name: 'Plesk Backup Archive', description: 'Import from an uploaded .tar Plesk backup file. For a live Plesk server, use CyberPanel / HestiaCP / aaPanel / Plesk via SSH above instead.', icon: 'bi bi-boxes', available: false, formats: 'Coming soon' },
    { id: 'wordpress', name: 'WordPress Migration', description: 'Import a WordPress archive and database package.', icon: 'bi bi-wordpress', available: false, formats: 'Coming soon' },
];
const openService = (service) => {
    if (service.id === 'cpanel-full') router.visit(route('migrations.cpanel'));
    if (service.id === 'cyberpanel-ssh') router.visit(route('migrations.cyberpanel-ssh'));
};
const generic = reactive({ archive: null, database: null });
const trackingId = ref('');
const uploadStages = reactive({ database: { progress: 0, status: 'waiting' }, archive: { progress: 0, status: 'waiting' }, connect: { progress: 0, status: 'waiting' } });
const submitGeneric = async () => {
    const overwriteDatabase = !!generic.database && !!props.databaseConnection?.available;
    if (overwriteDatabase && !window.confirm(`Database ${props.databaseConnection.database_name || ''} already exists. A backup will be created first. Continue and overwrite it?`)) return;
    uploading.value = true; error.value = ''; message.value = '';
    Object.values(uploadStages).forEach(stage => { stage.progress = 0; stage.status = 'waiting'; });
    const routeParams = { token: props.panelToken, id: props.website.id };
    try {
        const response = await axios.post(route('websites.import.store', routeParams), {
            archive_name: generic.archive.name, archive_size: generic.archive.size,
            database_name_file: generic.database?.name || null, database_size: generic.database?.size || null,
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
    const params = { token: props.panelToken, id: props.website.id, tracking, kind };
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

const state = (item) => choices[item.id] ||= { domains: [], files: [], databases: [], full_account: false };
const items = (item, type) => item.inventory?.[type] || [];
const allChecked = (item, type) => items(item, type).length > 0 && state(item)[type].length === items(item, type).length;
const toggleAll = (item, type) => state(item)[type] = allChecked(item, type) ? [] : items(item, type).map(x => x.id);
const hasSelection = (item) => state(item).full_account || ['domains', 'files', 'databases'].some(k => state(item)[k].length);
const total = computed(() => props.imports.length);
let refreshTimer = null;
const hasBackgroundWork = computed(() => props.imports.some(item => ['inspecting', 'restoring'].includes(item.status)));
onMounted(() => {
    refreshTimer = window.setInterval(() => {
        if (hasBackgroundWork.value) router.reload({ only: ['imports'], preserveScroll: true });
    }, 3000);
});
onBeforeUnmount(() => window.clearInterval(refreshTimer));

const upload = async () => {
    if (!archive.value) return;
    uploading.value = true; error.value = ''; message.value = '';
    const data = new FormData(); data.append('provider', 'cpanel'); data.append('archive', archive.value);
    try { const response = await axios.post(route('migrations.store'), data); message.value = response.data.message; router.reload(); }
    catch (e) { error.value = e.response?.data?.message || 'Upload failed.'; }
    finally { uploading.value = false; }
};
const restore = async (item) => {
    restoring.value = item.id; error.value = ''; message.value = '';
    try { const response = await axios.post(route('migrations.restore', item.id), state(item)); message.value = response.data.message; }
    catch (e) { error.value = e.response?.data?.message || 'Restore failed.'; }
    finally { restoring.value = null; }
};
const remove = async (item) => {
    if (!confirm(`Remove ${item.original_name}?`)) return;
    await axios.delete(route('migrations.destroy', item.id)); router.reload();
};
</script>

<template>
  <Head :title="props.provider === 'cpanel' ? 'cPanel Migration' : props.provider === 'cyberpanel-ssh' ? 'CyberPanel / HestiaCP / aaPanel / Plesk SSH Migration' : 'Migration Import'" />
  <AuthenticatedLayout>
    <template #header><h2 class="text-xl font-semibold text-slate-800 dark:text-slate-100">{{ props.provider === 'cpanel' ? 'cPanel Migration' : props.provider === 'cyberpanel-ssh' ? 'CyberPanel / HestiaCP / aaPanel / Plesk SSH Migration' : 'Migration Import' }}</h2></template>
    <div class="mx-auto space-y-6 p-4 sm:p-6">
      <section v-if="!props.provider">
        <div class="mb-4"><h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Choose migration service</h3><p class="text-sm text-slate-500 dark:text-slate-400">Select where the backup came from and what kind of package you have.</p></div>
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
          <button v-for="service in services" :key="service.id" type="button" :disabled="!service.available" class="relative rounded-2xl border border-slate-200 bg-white p-5 text-left shadow-sm transition dark:border-slate-800 dark:bg-slate-900/50" :class="service.available ? 'hover:border-indigo-300 hover:shadow-md dark:hover:border-indigo-700' : 'cursor-not-allowed opacity-60'" @click="openService(service)">
            <span v-if="!service.available" class="absolute right-4 top-4 rounded-full bg-slate-100 px-2 py-1 text-[10px] font-bold uppercase text-slate-500 dark:bg-slate-800 dark:text-slate-400">Coming soon</span>
            <i :class="service.icon" class="text-2xl text-indigo-600 dark:text-indigo-400"></i>
            <h4 class="mt-3 font-semibold text-slate-900 dark:text-slate-100">{{ service.name }}</h4>
            <p class="mt-1 min-h-10 text-sm text-slate-500 dark:text-slate-400">{{ service.description }}</p>
            <p class="mt-3 text-xs font-medium text-slate-400 dark:text-slate-500">{{ service.formats }}</p>
          </button>
        </div>
      </section>

      <CyberPanelSshMigration v-else-if="props.provider === 'cyberpanel-ssh'" :saved-connections="props.savedSshConnections" :php-versions="props.phpVersions" />
      <template v-else-if="props.provider === 'cpanel'">
      <button type="button" class="inline-flex items-center gap-2 text-sm font-semibold text-indigo-600 hover:text-indigo-700 dark:text-indigo-400 dark:hover:text-indigo-300" @click="router.visit(route('migrations.index'))"><i class="bi bi-arrow-left"></i> All migration services</button>
      <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900/50">
        <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">cPanel Full Backup</h3>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Supports cpmove-USER.tar.gz and .tgz. After inspection, restore the whole account or select domains, files, and databases independently.</p>
        <form class="mt-5 flex flex-col gap-3 sm:flex-row" @submit.prevent="upload">
          <input type="file" accept=".gz,.tgz,application/gzip" required class="block w-full rounded-lg border border-slate-300 bg-white p-2 text-sm text-slate-700 file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:file:bg-slate-700 dark:file:text-slate-200" @change="archive = $event.target.files[0]" />
          <button :disabled="uploading" class="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-semibold text-white disabled:opacity-50">{{ uploading ? 'Inspecting…' : 'Upload & Inspect' }}</button>
        </form>
      </section>

      <div v-if="message" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">{{ message }}</div>
      <div v-if="error" class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-800 dark:bg-red-500/10 dark:text-red-300">{{ error }}</div>

      <div v-if="total" class="pt-2"><h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Uploaded migrations</h3><p class="text-sm text-slate-500 dark:text-slate-400">Continue a full or partial restore from any retained archive.</p></div>
      <section v-for="item in imports" :key="item.id" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900/50">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div><h3 class="font-semibold text-slate-900 dark:text-slate-100">{{ item.original_name }}</h3><p class="text-sm text-slate-500 dark:text-slate-400">Account: {{ item.inventory?.account || '—' }} · {{ (item.archive_size / 1048576).toFixed(1) }} MB</p></div>
          <div class="flex items-center gap-3"><span class="rounded-full px-3 py-1 text-xs font-semibold" :class="['ready', 'completed'].includes(item.status) ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400' : ['inspecting', 'restoring'].includes(item.status) ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400' : 'bg-red-100 text-red-700 dark:bg-red-500/10 dark:text-red-400'">{{ item.status }}</span><button :disabled="['inspecting', 'restoring'].includes(item.status)" class="text-sm text-red-600 disabled:opacity-40 dark:text-red-400" @click="remove(item)">Remove</button></div>
        </div>
        <p v-if="item.last_error" class="mt-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-500/10 dark:text-red-300">{{ item.last_error }}</p>

        <div v-if="item.status === 'ready'" class="mt-5 space-y-5">
          <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-indigo-200 bg-indigo-50 p-4 dark:border-indigo-800 dark:bg-indigo-500/10"><input v-model="state(item).full_account" type="checkbox" class="rounded" /><span class="text-slate-900 dark:text-slate-100"><b>Restore full account</b><small class="block text-slate-600 dark:text-slate-400">All domains, home files, and databases</small></span></label>
          <div class="grid gap-4 lg:grid-cols-3" :class="{ 'pointer-events-none opacity-50': state(item).full_account }">
            <div v-for="type in ['domains', 'files', 'databases']" :key="type" class="rounded-xl border border-slate-200 p-4 dark:border-slate-700 dark:bg-slate-800/40">
              <div class="mb-3 flex items-center justify-between"><h4 class="font-semibold capitalize text-slate-900 dark:text-slate-100">{{ type }}</h4><button v-if="items(item,type).length" type="button" class="text-xs font-semibold text-indigo-600 dark:text-indigo-400" @click="toggleAll(item,type)">{{ allChecked(item,type) ? 'Clear all' : 'Select all' }}</button></div>
              <div v-if="!items(item,type).length" class="text-sm text-slate-400 dark:text-slate-500">None found</div>
              <label v-for="entry in items(item,type)" :key="entry.id" class="mb-2 flex cursor-pointer items-start gap-2 text-sm text-slate-700 dark:text-slate-300"><input v-model="state(item)[type]" :value="entry.id" type="checkbox" class="mt-0.5 rounded" /><span class="min-w-0 break-all">{{ entry.label }}<small v-if="entry.document_root" class="block text-slate-400 dark:text-slate-500">{{ entry.document_root }}</small></span></label>
            </div>
          </div>
          <button :disabled="!hasSelection(item) || restoring === item.id" class="rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50" @click="restore(item)">{{ restoring === item.id ? 'Restoring…' : 'Restore selected items' }}</button>
        </div>
      </section>
      <p v-if="!total" class="py-12 text-center text-slate-500 dark:text-slate-400">No migration archive uploaded yet.</p>
      </template>
      <template v-else-if="props.provider === 'generic'">
        <button type="button" class="inline-flex items-center gap-2 text-sm font-semibold text-indigo-600 dark:text-indigo-400" @click="router.visit(route('websites.manage', { token: props.panelToken, id: props.website.id }))"><i class="bi bi-arrow-left"></i> Back to {{ props.website.domain }}</button>
        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900/50">
          <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Import into {{ props.website.domain }}</h3>
          <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Upload website files and an optional SQL dump. Existing database credentials for this website are selected automatically—no connection form is required.</p>
          <form class="mt-6 grid gap-4 md:grid-cols-2" @submit.prevent="submitGeneric">
            <label class="text-sm font-medium text-slate-700 dark:text-slate-300">Website files<input required type="file" accept=".zip,.gz,.tgz,application/zip,application/gzip" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white p-2 dark:border-slate-700 dark:bg-slate-800" @change="generic.archive = $event.target.files[0]" /></label>
            <label class="text-sm font-medium text-slate-700 dark:text-slate-300">Database dump <span class="font-normal text-slate-400">(optional)</span><input type="file" accept=".sql,application/sql,text/plain" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white p-2 dark:border-slate-700 dark:bg-slate-800" @change="generic.database = $event.target.files[0]" /><span class="mt-1 block text-xs font-normal text-slate-500">Automatically imports into this website's active database.</span></label>
            <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-300 md:col-span-2">
              <template v-if="props.databaseConnection?.available"><i class="bi bi-database-check mr-1 text-emerald-500"></i>Existing database <b>{{ props.databaseConnection.database_name }}</b> requires confirmation and will be backed up before overwrite.</template>
              <template v-else><i class="bi bi-database-add mr-1 text-indigo-500"></i>No active database exists. Uploading SQL will automatically create and connect one.</template>
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
        <section v-for="item in imports" :key="item.id" class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900/50">
          <div class="flex items-center justify-between gap-3"><div><h4 class="font-semibold dark:text-slate-100">{{ item.inventory?.domain || item.original_name }}</h4><p class="text-sm text-slate-500 dark:text-slate-400">{{ item.original_name }}</p></div><span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold dark:bg-slate-800">{{ item.status }}</span></div>
          <p v-if="item.last_error" class="mt-3 text-sm text-red-600 dark:text-red-400">{{ item.last_error }}</p>
        </section>
      </template>
    </div>
  </AuthenticatedLayout>
</template>
