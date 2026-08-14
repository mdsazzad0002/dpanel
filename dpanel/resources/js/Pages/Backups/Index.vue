<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import axios from 'axios';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    backupRoot: { type: String, default: '' },
    retentionDays: { type: Number, default: 7 },
    backupSchedule: { type: Object, default: () => ({ enabled: true, time: '02:30' }) },
    storageDriver: { type: String, default: 'local' },
    remoteUpload: { type: Object, default: () => ({ enabled: false, host: '', path: '', user: '', port: '22' }) },
    scpStatus: { type: Object, default: () => ({ status: 'never', updated_at: null }) },
    websites: { type: Array, default: () => [] },
    runs: { type: Array, default: () => [] },
});

const backupCanvasOpen = ref(false);
const runs = ref([...props.runs]);
const responseMessage = ref('');
const responseOk = ref(true);
const deletingRun = ref('');
const restoringFile = ref('');
const runsLoading = ref(false);
const batch = ref(null);
let batchPoll = null;
const runForm = useForm({
    filter: 'all',
    content: 'all',
    website_id: '',
});
const totals = computed(() => ({
    runs: runs.value.length,
    files: runs.value.reduce((carry, run) => carry + Number(run.file_count ?? 0), 0),
    size: runs.value.reduce((carry, run) => carry + Number(run.total_size_bytes ?? 0), 0),
}));

const bytesToLabel = (bytes) => {
    const value = Number(bytes || 0);
    if (value < 1024) return `${value} B`;
    if (value < 1024 ** 2) return `${(value / 1024).toFixed(2)} KB`;
    if (value < 1024 ** 3) return `${(value / 1024 ** 2).toFixed(2)} MB`;

    return `${(value / 1024 ** 3).toFixed(2)} GB`;
};

const downloadToken = (filename) => btoa(String(filename))
    .replace(/\+/g, '-')
    .replace(/\//g, '_')
    .replace(/=+$/g, '');

const showError = (error, fallback) => {
    const data = error.response?.data;
    Object.entries(data?.errors || {}).forEach(([field, messages]) => {
        runForm.setError(field, Array.isArray(messages) ? messages[0] : messages);
    });
    responseOk.value = false;
    responseMessage.value = data?.message || fallback;
};

const runBackup = async () => {
    runForm.processing = true;
    runForm.clearErrors();
    responseMessage.value = '';
    try {
        const { data } = await axios.post(route('backups.run'), runForm.data(), { headers: { Accept: 'application/json' } });
        runs.value = data.runs || runs.value;
        responseOk.value = true;
        responseMessage.value = data.message;
        backupCanvasOpen.value = false;
        if (data.queued) startBatchPolling();
    } catch (error) {
        showError(error, 'Backup could not be completed.');
    } finally {
        runForm.processing = false;
    }
};

const deleteRun = async (runName) => {
    if (!confirm(`Delete backup run "${runName}"?`)) return;
    deletingRun.value = runName;
    try {
        const { data } = await axios.delete(route('backups.destroy', runName), { headers: { Accept: 'application/json' } });
        runs.value = data.runs || [];
        responseOk.value = true;
        responseMessage.value = data.message;
    } catch (error) {
        showError(error, 'Backup run could not be deleted.');
    } finally {
        deletingRun.value = '';
    }
};

const restoreBackup = async (runName, file) => {
    if (!confirm(`Restore ${file.label || file.name}? Current website files and database data will be replaced.`)) return;
    restoringFile.value = `${runName}:${file.name}`;
    try {
        const { data } = await axios.post(route('backups.restore', { run: runName, encoded: downloadToken(file.name) }), {}, { headers: { Accept: 'application/json' } });
        responseOk.value = true;
        responseMessage.value = data.message;
    } catch (error) {
        showError(error, 'Backup restore failed.');
    } finally {
        restoringFile.value = '';
    }
};

const loadRuns = async () => {
    runsLoading.value = true;
    try {
        const { data } = await axios.get(route('backups.data'), { headers: { Accept: 'application/json' } });
        runs.value = data.runs || [];
        batch.value = data.batch || null;
        if (batch.value?.message) {
            responseOk.value = batch.value.stage !== 'failed';
            responseMessage.value = batch.value.message;
        }
        if (['completed', 'failed'].includes(batch.value?.stage)) stopBatchPolling();
    } catch (error) {
        showError(error, 'Backup list could not be loaded.');
    } finally {
        runsLoading.value = false;
    }
};

const startBatchPolling = () => {
    stopBatchPolling();
    batchPoll = window.setInterval(loadRuns, 4000);
};
const stopBatchPolling = () => {
    if (batchPoll) window.clearInterval(batchPoll);
    batchPoll = null;
};

onMounted(async () => {
    await loadRuns();
    if (['queued', 'running'].includes(batch.value?.stage)) startBatchPolling();
});
onBeforeUnmount(stopBatchPolling);

</script>

<template>
    <Head title="Backups" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Backups</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Create portable, cPanel-style migration backups.</p>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="responseMessage" :class="responseOk ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-red-200 bg-red-50 text-red-700'" class="rounded-md border px-4 py-3 text-sm whitespace-pre-line">
                {{ responseMessage }}
                <span v-if="batch && ['queued', 'running'].includes(batch.stage)" class="ml-2 font-semibold">{{ batch.completed || 0 }}/{{ batch.total || 0 }}</span>
            </div>

            <section class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div class="flex items-center justify-between">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Total Runs</p>
                        <i class="itc bi bi-archive text-slate-400"></i>
                    </div>
                    <p class="mt-2 text-3xl font-semibold leading-none">{{ totals.runs }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div class="flex items-center justify-between">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Total Files</p>
                        <i class="itc bi bi-files text-slate-400"></i>
                    </div>
                    <p class="mt-2 text-3xl font-semibold leading-none">{{ totals.files }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div class="flex items-center justify-between">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Total Size</p>
                        <i class="itc bi bi-hdd-stack text-slate-400"></i>
                    </div>
                    <p class="mt-2 text-3xl font-semibold leading-none">{{ bytesToLabel(totals.size) }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div class="flex items-center justify-between">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Retention</p>
                        <i class="itc bi bi-calendar-check text-slate-400"></i>
                    </div>
                    <p class="mt-2 text-3xl font-semibold leading-none">{{ retentionDays }} days</p>
                </div>
            </section>

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <Link :href="route('backups.storage.index')" class="group flex min-h-24 items-center gap-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition hover:border-indigo-300 hover:shadow-md dark:border-slate-800 dark:bg-slate-900 dark:hover:border-indigo-800">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-indigo-100 text-lg text-indigo-600 transition group-hover:bg-indigo-600 group-hover:text-white dark:bg-indigo-950"><i class="itc bi bi-cloud-arrow-up"></i></span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="font-semibold">Backup Storage</h2>
                            <span :class="remoteUpload?.enabled ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-slate-100 text-slate-500 dark:bg-slate-800'" class="rounded-full px-2 py-0.5 text-[11px] font-medium">
                                {{ remoteUpload?.enabled ? 'Enabled' : 'Disabled' }}
                            </span>
                        </div>
                        <p class="mt-1 text-sm capitalize text-slate-500">{{ storageDriver.replace('_', ' ') }} · Configure destination and sequential processing.</p>
                    </div>
                    <i class="itc bi bi-chevron-right shrink-0 text-slate-400 transition group-hover:translate-x-0.5 group-hover:text-indigo-600"></i>
            </Link>

            <section class="flex min-h-24 items-center gap-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition hover:border-blue-300 hover:shadow-md dark:border-slate-800 dark:bg-slate-900 dark:hover:border-blue-800">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-blue-100 text-lg text-blue-600 dark:bg-blue-950"><i class="itc bi bi-archive"></i></span>
                    <div class="min-w-0 flex-1">
                        <h2 class="font-semibold">Create a Backup</h2>
                        <p class="mt-1 text-sm text-slate-500">Choose backup scope and content.</p>
                    </div>
                <button type="button" class="shrink-0 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700" @click="backupCanvasOpen = true">
                    <i class="itc bi bi-plus-circle mr-1"></i> New Backup
                </button>
            </section>
            </div>

            <div v-if="backupCanvasOpen" class="fixed inset-0 z-[100]" role="dialog" aria-modal="true" aria-label="Create backup">
                <button type="button" class="absolute inset-0 h-full w-full bg-slate-950/50 backdrop-blur-[1px]" aria-label="Close backup drawer" @click="backupCanvasOpen = false"></button>
                <aside class="absolute right-0 top-0 flex h-full w-full max-w-xl flex-col bg-white shadow-2xl dark:bg-slate-900">
                    <div class="flex items-start justify-between border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                        <div>
                            <h2 class="text-lg font-semibold">Create Backup</h2>
                            <p class="text-xs text-slate-500">Configure a dRust backup job.</p>
                        </div>
                        <button type="button" class="grid h-9 w-9 place-items-center rounded-lg text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800" aria-label="Close" @click="backupCanvasOpen = false"><i class="itc bi bi-x-lg"></i></button>
                    </div>
                    <form class="flex min-h-0 flex-1 flex-col" @submit.prevent="runBackup">
                        <div class="flex-1 space-y-6 overflow-y-auto p-5">
                            <div class="rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs leading-5 text-blue-800 dark:border-blue-900 dark:bg-blue-950/40 dark:text-blue-200">
                                Full backups create a portable cPanel-style <span class="font-mono">backup-*.tar.gz</span> migration package. All-account backups run in the background, one account at a time.
                            </div>
                    <div>
                        <div class="mb-3">
                            <h3 class="text-sm font-semibold">1. Backup Filter</h3>
                            <p class="text-xs text-slate-500">Choose the account scope for this backup.</p>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <button type="button" :class="runForm.filter === 'all' ? 'border-blue-500 bg-blue-50 ring-2 ring-blue-100 dark:bg-blue-950/40 dark:ring-blue-900' : 'border-slate-200 hover:border-slate-300 dark:border-slate-700'" class="flex items-center gap-3 rounded-xl border p-4 text-left transition" @click="runForm.filter = 'all'; runForm.website_id = ''">
                                <span class="grid h-10 w-10 place-items-center rounded-lg bg-blue-100 text-blue-600 dark:bg-blue-900/50"><i class="itc bi bi-hdd-stack"></i></span>
                                <span><span class="block text-sm font-semibold">All Websites</span><span class="block text-xs text-slate-500">One account archive per website</span></span>
                                <i v-if="runForm.filter === 'all'" class="itc bi bi-check-circle-fill ml-auto text-blue-600"></i>
                            </button>
                            <button type="button" :class="runForm.filter === 'website' ? 'border-violet-500 bg-violet-50 ring-2 ring-violet-100 dark:bg-violet-950/40 dark:ring-violet-900' : 'border-slate-200 hover:border-slate-300 dark:border-slate-700'" class="flex items-center gap-3 rounded-xl border p-4 text-left transition" @click="runForm.filter = 'website'">
                                <span class="grid h-10 w-10 place-items-center rounded-lg bg-violet-100 text-violet-600 dark:bg-violet-900/50"><i class="itc bi bi-globe2"></i></span>
                                <span><span class="block text-sm font-semibold">Website Wise</span><span class="block text-xs text-slate-500">One main domain only</span></span>
                                <i v-if="runForm.filter === 'website'" class="itc bi bi-check-circle-fill ml-auto text-violet-600"></i>
                            </button>
                        </div>
                    </div>

                    <div v-if="runForm.filter === 'website'" class="rounded-xl border border-violet-200 bg-violet-50/60 p-4 dark:border-violet-900 dark:bg-violet-950/20">
                        <label class="mb-2 block text-sm font-semibold">Select Main Domain</label>
                        <select v-model="runForm.website_id" required :disabled="websites.length === 0" class="w-full rounded-lg border border-violet-200 bg-white px-3 py-2.5 text-sm focus:border-violet-500 focus:ring-violet-500 disabled:cursor-not-allowed disabled:opacity-60 dark:border-violet-800 dark:bg-slate-900">
                            <option value="" disabled>{{ websites.length ? 'Choose a website' : 'No customer main domain available' }}</option>
                            <option v-for="website in websites" :key="website.id" :value="website.id">{{ website.domain }}</option>
                        </select>
                        <p class="mt-2 text-xs text-slate-500">Aliases and subdomains are covered by their parent folder.</p>
                        <p v-if="runForm.errors.website_id" class="mt-1 text-xs text-red-600">{{ runForm.errors.website_id }}</p>
                    </div>

                    <div>
                        <div class="mb-3">
                            <h3 class="text-sm font-semibold">2. Backup Content</h3>
                            <p class="text-xs text-slate-500">Select what should be included in the archive.</p>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-3">
                            <button v-for="item in [{ value: 'all', label: 'All', hint: 'Files + database', icon: 'bi-archive' }, { value: 'files', label: 'Files', hint: 'Website or panel files', icon: 'bi-folder2-open' }, { value: 'database', label: 'Database', hint: 'SQL dump only', icon: 'bi-database' }]" :key="item.value" type="button" :class="runForm.content === item.value ? 'border-emerald-500 bg-emerald-50 ring-2 ring-emerald-100 dark:bg-emerald-950/30 dark:ring-emerald-900' : 'border-slate-200 hover:border-slate-300 dark:border-slate-700'" class="relative rounded-xl border p-4 text-left transition" @click="runForm.content = item.value">
                                <i :class="item.icon" class="itc bi text-lg text-emerald-600"></i>
                                <span class="mt-2 block text-sm font-semibold">{{ item.label }}</span>
                                <span class="block text-xs text-slate-500">{{ item.hint }}</span>
                                <i v-if="runForm.content === item.value" class="itc bi bi-check-circle-fill absolute right-3 top-3 text-emerald-600"></i>
                            </button>
                        </div>
                    </div>

                        </div>
                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <p class="text-xs text-slate-500"><span class="font-medium text-slate-700 dark:text-slate-200">Selected:</span> {{ runForm.filter === 'all' ? 'All' : 'Website Wise' }} · {{ runForm.content === 'database' ? 'Database' : runForm.content.charAt(0).toUpperCase() + runForm.content.slice(1) }}</p>
                        <div class="flex items-center gap-2">
                            <Link :href="route('monitoring.index')" class="rounded-lg border border-slate-300 px-4 py-2.5 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">Monitoring</Link>
                            <button type="submit" :disabled="runForm.processing || (runForm.filter === 'website' && (!runForm.website_id || websites.length === 0))" class="rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50">
                                <i class="itc bi bi-play-circle mr-1"></i>{{ runForm.processing ? 'Running Backup...' : 'Run Backup Now' }}
                            </button>
                        </div>
                    </div>
                    </form>
                </aside>
            </div>

            <section class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-800">
                        <tr>
                            <th class="px-4 py-3">Run</th>
                            <th class="px-4 py-3">Created</th>
                            <th class="px-4 py-3">Files</th>
                            <th class="px-4 py-3">Size</th>
                            <th class="px-4 py-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="run in runs" :key="run.name" class="border-t border-slate-200 align-top dark:border-slate-800">
                            <td class="px-4 py-3">
                                <p class="font-mono text-xs">{{ run.name }}</p>
                                <ul class="mt-2 space-y-1">
                                    <li v-for="file in run.files || []" :key="`${run.name}-${file.name}`" class="flex flex-wrap items-center gap-2 text-xs text-slate-600 dark:text-slate-300">
                                        <span>
                                            <span class="block font-medium text-slate-700 dark:text-slate-200">{{ file.label || file.name }}</span>
                                            <span class="block font-mono text-[10px] text-slate-400">{{ file.name }}</span>
                                        </span>
                                        <span class="text-slate-500">({{ bytesToLabel(file.size_bytes) }})</span>
                                        <a :href="route('backups.download', { run: run.name, encoded: downloadToken(file.name) })" class="rounded border border-slate-300 px-2 py-0.5 hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" download>
                                            Download
                                        </a>
                                        <button v-if="file.restorable" type="button" :disabled="restoringFile !== ''" class="rounded border border-emerald-300 px-2 py-0.5 text-emerald-700 hover:bg-emerald-50 disabled:opacity-50 dark:border-emerald-800 dark:text-emerald-400" @click="restoreBackup(run.name, file)">
                                            {{ restoringFile === `${run.name}:${file.name}` ? 'Restoring...' : 'Restore' }}
                                        </button>
                                    </li>
                                </ul>
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-500">{{ run.created_at || '-' }}</td>
                            <td class="px-4 py-3">{{ run.file_count || 0 }}</td>
                            <td class="px-4 py-3">{{ bytesToLabel(run.total_size_bytes || 0) }}</td>
                            <td class="px-4 py-3">
                                <button type="button" :disabled="deletingRun !== ''" class="rounded-md border border-red-300 px-3 py-1.5 text-xs text-red-700 hover:bg-red-50 disabled:opacity-50 dark:border-red-700 dark:text-red-400" @click="deleteRun(run.name)">
                                    {{ deletingRun === run.name ? 'Deleting...' : 'Delete Run' }}
                                </button>
                            </td>
                        </tr>
                        <tr v-if="runsLoading">
                            <td colspan="5" class="px-4 py-8 text-center text-slate-500"><i class="itc bi bi-arrow-repeat mr-2 animate-spin"></i>Loading backups...</td>
                        </tr>
                        <tr v-else-if="runs.length === 0">
                            <td colspan="5" class="px-4 py-8 text-center text-slate-500">No backup runs found.</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
