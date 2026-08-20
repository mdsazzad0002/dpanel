<script setup>
import { computed, onUnmounted, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    website: { type: Object, required: true },
    databaseConnection: { type: Object, default: () => ({ available: false }) },
});

const page = usePage();
const panelToken = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => (
    panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params)
);
const csrfToken = computed(() => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');

const databases = computed(() => props.databaseConnection?.databases || []);
const hasMultipleDatabases = computed(() => databases.value.length > 1);

const exportFiles = ref(true);
// Single-database sites keep the old one-click behavior (pre-checked); with
// several databases linked to the same domain, nothing is pre-selected —
// the user must explicitly choose which one(s) to export.
const selectedDatabaseIds = ref(databases.value.length === 1 ? [databases.value[0].id] : []);
// When checked, each step's download starts by itself the moment its link is
// ready. The per-step Download button stays available either way, so a
// blocked/missed auto-download can always be retried by hand.
const autoDownload = ref(true);

const exportPhase = ref('select');
const exportLoading = ref(false);
const activeExportItems = ref([]);
// Per-item state, keyed by item.key: stage ('pending'|'preparing'|'zipping'|'ready'|'downloaded'|'error'),
// downloadUrl (set once the server responds), and message (error detail).
const itemState = ref({});
const toasts = ref([]);
let toastSeq = 0;

const pushToast = (message, type = 'success') => {
    const id = ++toastSeq;
    toasts.value.push({ id, message, type });
    window.setTimeout(() => {
        toasts.value = toasts.value.filter((toast) => toast.id !== id);
    }, 4000);
};

const exportItemLabel = (item) => (item.type === 'database' ? `SQL — ${item.databaseName}` : 'Website files');
const stepStatusText = (item) => {
    const state = itemState.value[item.key] || {};
    switch (state.stage) {
        case 'queued': return 'Step 1/2 — queued…';
        case 'zipping': return 'Step 1/2 — preparing (zip/dump running in the background)…';
        case 'ready': return 'Step 2/2 — download link generated';
        case 'downloaded': return 'Download started';
        case 'error': return state.message || 'Failed';
        default: return 'Queued';
    }
};

let statusPollTimer = null;

const stopStatusPolling = () => {
    window.clearInterval(statusPollTimer);
    statusPollTimer = null;
};

const resetExport = () => {
    stopStatusPolling();
    exportPhase.value = 'select';
    activeExportItems.value = [];
    itemState.value = {};
};

onUnmounted(() => {
    // Leaving the page stops polling — the export itself keeps running on the
    // queue worker regardless, and a Notification covers completion/failure
    // for whoever isn't watching this page anymore.
    stopStatusPolling();
    stopSharePoll();
});

const triggerDownload = (item) => {
    const url = itemState.value[item.key]?.downloadUrl;
    if (!url) return;
    // Plain same-origin navigation: the browser sends the session cookie itself
    // and handles the download natively, instead of buffering the whole file as a JS blob.
    const link = document.createElement('a');
    link.href = url;
    document.body.appendChild(link);
    link.click();
    link.remove();
    itemState.value[item.key].stage = 'downloaded';
};

const copyDownloadLink = async (item) => {
    const url = itemState.value[item.key]?.downloadUrl;
    if (!url) return;
    try {
        await navigator.clipboard.writeText(url);
        pushToast('Link copied to clipboard.');
    } catch {
        pushToast('Could not copy the link — copy it manually.', 'error');
    }
};

// One shared 5s poll drives every still-running item's status. Runs only while
// this page is open — the job itself is already running on the queue worker
// independently, so closing the tab doesn't stop or lose the export; the
// Notification bell picks up completion/failure instead.
const pollExportStatuses = async () => {
    const pending = activeExportItems.value.filter((item) => ['queued', 'zipping'].includes(itemState.value[item.key]?.stage));
    if (pending.length === 0) {
        stopStatusPolling();
        return;
    }

    for (const item of pending) {
        const exportId = itemState.value[item.key]?.exportId;
        if (!exportId) continue;

        try {
            const response = await fetch(panelRoute('websites.quick-export.status', { id: props.website.id, exportId }), {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok || !data.ok) continue;

            if (data.stage === 'ready') {
                itemState.value[item.key].downloadUrl = data.download_url;
                itemState.value[item.key].stage = 'ready';
                if (autoDownload.value) {
                    triggerDownload(item);
                }
            } else if (data.stage === 'failed') {
                itemState.value[item.key].stage = 'error';
                itemState.value[item.key].message = data.message || `Failed to export ${exportItemLabel(item)}.`;
                pushToast(itemState.value[item.key].message, 'error');
            } else if (data.stage) {
                itemState.value[item.key].stage = data.stage;
            }
        } catch {
            // Network hiccup — leave the item's stage as-is, next tick retries.
        }
    }

    const stillRunning = activeExportItems.value.some((item) => ['queued', 'zipping'].includes(itemState.value[item.key]?.stage));
    if (!stillRunning) {
        stopStatusPolling();
        exportLoading.value = false;
        const anyFailed = activeExportItems.value.some((item) => itemState.value[item.key]?.stage === 'error');
        exportPhase.value = anyFailed ? 'error' : 'complete';
        if (!anyFailed) {
            pushToast(autoDownload.value ? 'All downloads started.' : 'All links are ready — click Download on each step.', 'success');
        }
    }
};

// ---- Share (this website → another server) ----
const shareRunning = ref(false);
const shareStage = ref(null);
const shareMessage = ref('');
const shareUrl = ref('');
const shareExpiresAt = ref('');
let sharePollTimer = null;

const shareStageText = (stage) => ({
    queued: 'Queued…',
    archiving: 'Archiving website files…',
    exporting_database: 'Exporting database…',
    packaging: 'Building package…',
    ready: 'Done',
    failed: 'Failed',
}[stage] || 'Working…');

const stopSharePoll = () => {
    window.clearInterval(sharePollTimer);
    sharePollTimer = null;
};

const pollShareStatus = async (shareId) => {
    try {
        const response = await fetch(panelRoute('websites.clone-share.status', { id: props.website.id, jobId: shareId }), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.ok) return;
        shareStage.value = data.stage;
        if (data.stage === 'ready') {
            stopSharePoll();
            shareRunning.value = false;
            shareUrl.value = data.download_url || '';
            shareExpiresAt.value = data.expires_at || '';
            pushToast('Share link is ready.');
        } else if (data.stage === 'failed') {
            stopSharePoll();
            shareRunning.value = false;
            shareMessage.value = data.message || 'Share failed.';
            pushToast(shareMessage.value, 'error');
        }
    } catch {
        // Network hiccup — next tick retries.
    }
};

const startShare = async () => {
    if (shareRunning.value) return;
    shareRunning.value = true;
    shareStage.value = 'queued';
    shareMessage.value = '';
    shareUrl.value = '';
    try {
        const response = await fetch(panelRoute('websites.clone-share.share', { id: props.website.id }), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken.value,
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({}),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.ok) throw new Error(data.message || 'Failed to start share.');
        stopSharePoll();
        sharePollTimer = window.setInterval(() => pollShareStatus(data.share_id), 5000);
        pollShareStatus(data.share_id);
    } catch (error) {
        shareRunning.value = false;
        shareStage.value = 'failed';
        shareMessage.value = error?.message || 'Failed to start share.';
        pushToast(shareMessage.value, 'error');
    }
};

const copyShareUrl = async () => {
    if (!shareUrl.value) return;
    try {
        await navigator.clipboard.writeText(shareUrl.value);
        pushToast('Link copied to clipboard.');
    } catch {
        pushToast('Could not copy the link — copy it manually.', 'error');
    }
};

const quickExport = async () => {
    if (exportLoading.value || (!exportFiles.value && selectedDatabaseIds.value.length === 0)) return;

    const queue = [];
    if (exportFiles.value) queue.push({ type: 'files', key: 'files' });
    selectedDatabaseIds.value.forEach((databaseId) => {
        const database = databases.value.find((entry) => entry.id === databaseId);
        queue.push({ type: 'database', databaseId, databaseName: database?.database_name || 'database', key: `database:${databaseId}` });
    });

    activeExportItems.value = queue;
    itemState.value = Object.fromEntries(queue.map((item) => [item.key, { stage: 'queued', exportId: null, downloadUrl: null, message: '' }]));
    exportLoading.value = true;
    exportPhase.value = 'running';

    // Fire off every selected item's job immediately — each one just enqueues
    // and returns, so there's no reason to wait for one before starting the next.
    await Promise.all(queue.map(async (item) => {
        try {
            const response = await fetch(panelRoute('websites.quick-export', { id: props.website.id }), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken.value,
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(item.type === 'database' ? { type: 'database', database_id: item.databaseId } : { type: 'files' }),
            });

            const data = await response.json().catch(() => ({}));
            if (!response.ok || !data.ok || !data.export_id) {
                throw new Error(data.message || `Failed to queue export for ${exportItemLabel(item)}.`);
            }

            itemState.value[item.key].exportId = data.export_id;
        } catch (error) {
            itemState.value[item.key].stage = 'error';
            itemState.value[item.key].message = error?.message || `Failed to queue export for ${exportItemLabel(item)}.`;
            pushToast(itemState.value[item.key].message, 'error');
        }
    }));

    stopStatusPolling();
    statusPollTimer = window.setInterval(pollExportStatuses, 5000);
    pollExportStatuses();
};
</script>

<template>
    <Head :title="`Export & Share - ${website.domain}`" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h1 class="text-lg font-semibold">Export & Share</h1>
                    <p class="text-sm text-slate-500">{{ website.domain }} — each selected item downloads separately</p>
                    <p class="mt-0.5 text-xs text-slate-400">Runs in the background — stay on this page to watch live progress, or check the notification bell later.</p>
                </div>
                <Link :href="panelRoute('websites.manage', { id: website.id })" class="rounded-lg border px-3 py-2 text-sm dark:border-slate-700">
                    <i class="bi bi-arrow-left mr-1"></i> Website dashboard
                </Link>
            </div>
        </template>

        <div class="mx-auto grid gap-6  lg:grid-cols-2">
            <section class="rounded-xl border bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                        <input v-model="exportFiles" :disabled="exportLoading" type="checkbox" class="mt-0.5 rounded border-slate-300 text-violet-600 focus:ring-violet-500" />
                        <span>
                            <span class="block text-sm font-semibold text-slate-800 dark:text-slate-100">Website files</span>
                            <span class="mt-1 block text-xs text-slate-500">Downloads as its own ZIP</span>
                        </span>
                    </label>

                    <label v-if="!hasMultipleDatabases" class="flex items-start gap-3 rounded-xl border border-slate-200 p-4 dark:border-slate-700" :class="databaseConnection.available ? 'cursor-pointer' : 'cursor-not-allowed opacity-55'">
                        <input v-model="selectedDatabaseIds" :value="databases[0]?.id" type="checkbox" :disabled="exportLoading || !databaseConnection.available" class="mt-0.5 rounded border-slate-300 text-violet-600 focus:ring-violet-500" />
                        <span>
                            <span class="block text-sm font-semibold text-slate-800 dark:text-slate-100">SQL database</span>
                            <span class="mt-1 block text-xs text-slate-500">{{ databaseConnection.available ? `Downloads as its own .sql file (${databaseConnection.database_name})` : 'No active database linked' }}</span>
                        </span>
                    </label>

                    <div v-else class="rounded-xl border border-slate-200 p-4 dark:border-slate-700 sm:col-span-2">
                        <span class="block text-sm font-semibold text-slate-800 dark:text-slate-100">SQL databases</span>
                        <span class="mt-1 block text-xs text-slate-500">Multiple databases are linked to this domain — pick which ones to export (each downloads as its own .sql file)</span>
                        <div class="mt-3 space-y-2">
                            <label v-for="database in databases" :key="database.id" class="flex cursor-pointer items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700">
                                <input v-model="selectedDatabaseIds" :value="database.id" type="checkbox" :disabled="exportLoading" class="rounded border-slate-300 text-violet-600 focus:ring-violet-500" />
                                <span class="text-sm text-slate-700 dark:text-slate-200">{{ database.database_name }}</span>
                            </label>
                        </div>
                    </div>
                </div>

                <label class="mt-4 flex cursor-pointer items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                    <input v-model="autoDownload" type="checkbox" class="rounded border-slate-300 text-violet-600 focus:ring-violet-500" />
                    Auto download when a link is ready
                </label>

                <div v-if="exportPhase === 'running' || exportPhase === 'complete' || exportPhase === 'error'" class="mt-5 space-y-3">
                    <div v-for="(item, index) in activeExportItems" :key="item.key" class="rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                        <div class="flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
                                    <i v-if="itemState[item.key]?.stage === 'downloaded'" class="bi bi-check-circle-fill text-emerald-600"></i>
                                    <i v-else-if="itemState[item.key]?.stage === 'error'" class="bi bi-x-circle-fill text-red-600"></i>
                                    <i v-else-if="['queued', 'zipping'].includes(itemState[item.key]?.stage)" class="bi bi-arrow-repeat animate-spin text-violet-600"></i>
                                    <i v-else class="bi bi-circle text-slate-400"></i>
                                    <span>Step {{ index + 1 }} — {{ exportItemLabel(item) }}</span>
                                </div>
                                <p class="mt-1 text-xs" :class="itemState[item.key]?.stage === 'error' ? 'text-red-600' : 'text-slate-500'">
                                    {{ stepStatusText(item) }}
                                </p>
                            </div>
                            <div v-if="itemState[item.key]?.downloadUrl" class="flex shrink-0 items-center gap-2">
                                <button
                                    type="button"
                                    class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
                                    @click="copyDownloadLink(item)"
                                >
                                    <i class="bi bi-clipboard mr-1"></i>Copy link
                                </button>
                                <button
                                    type="button"
                                    class="rounded-lg bg-violet-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-violet-700"
                                    @click="triggerDownload(item)"
                                >
                                    {{ itemState[item.key]?.stage === 'downloaded' ? 'Download again' : 'Download' }}
                                </button>
                            </div>
                        </div>
                        <a v-if="itemState[item.key]?.downloadUrl" :href="itemState[item.key].downloadUrl" class="mt-2 block break-all text-xs text-blue-600 hover:underline dark:text-blue-400">
                            {{ itemState[item.key].downloadUrl }}
                        </a>
                        <p v-if="itemState[item.key]?.downloadUrl" class="mt-1 text-[11px] text-slate-400">
                            Link stays valid for 3 hours — no panel login needed, so it's safe to share with anyone.
                        </p>
                    </div>
                </div>

                <p v-if="!exportFiles && selectedDatabaseIds.length === 0" class="mt-4 text-sm text-red-600">Select at least one item to export.</p>

                <div class="mt-5 flex justify-end gap-3">
                    <button v-if="exportPhase === 'complete' || exportPhase === 'error'" type="button" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 dark:border-slate-600 dark:text-slate-200" @click="resetExport">
                        Export again
                    </button>
                    <button v-else type="button" :disabled="exportLoading || (!exportFiles && selectedDatabaseIds.length === 0)" class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-50" @click="quickExport">
                        {{ exportLoading ? 'Preparing…' : 'Prepare & Download Selected' }}
                    </button>
                </div>
            </section>

            <section class="rounded-xl border bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-100"><i class="bi bi-share mr-1.5 text-cyan-600"></i>Share</h2>
                <p class="mt-1 text-xs text-slate-500">Generate a one-time link for this website's files + database, to clone it on a different server.</p>

                <div v-if="shareStage" class="mt-4 rounded-lg border border-slate-200 p-3 text-xs dark:border-slate-700">
                    <div class="flex items-center gap-2">
                        <i v-if="shareStage === 'ready'" class="bi bi-check-circle-fill text-emerald-600"></i>
                        <i v-else-if="shareStage === 'failed'" class="bi bi-x-circle-fill text-red-600"></i>
                        <i v-else class="bi bi-arrow-repeat animate-spin text-cyan-600"></i>
                        <span>{{ shareStageText(shareStage) }}</span>
                    </div>
                    <p v-if="shareStage === 'failed' && shareMessage" class="mt-1 text-red-600">{{ shareMessage }}</p>
                </div>

                <div v-if="shareUrl" class="mt-4 space-y-2 rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-xs dark:border-emerald-900 dark:bg-emerald-950/30">
                    <p class="break-all font-mono text-emerald-800 dark:text-emerald-300">{{ shareUrl }}</p>
                    <p class="text-[11px] text-slate-500">Paste this into the destination server's Import & Clone → "Import from another server" field. Valid for 24 hours — no login needed on either side.</p>
                    <button type="button" class="rounded-lg border border-emerald-300 px-3 py-1.5 text-xs font-medium text-emerald-700 hover:bg-emerald-100 dark:border-emerald-700 dark:text-emerald-300 dark:hover:bg-emerald-900/40" @click="copyShareUrl">
                        <i class="bi bi-clipboard mr-1"></i>Copy link
                    </button>
                </div>

                <div class="mt-5 flex justify-end">
                    <button type="button" :disabled="shareRunning" class="rounded-lg bg-cyan-600 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-700 disabled:cursor-not-allowed disabled:opacity-50" @click="startShare">
                        {{ shareRunning ? 'Preparing…' : 'Generate Share Link' }}
                    </button>
                </div>
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
