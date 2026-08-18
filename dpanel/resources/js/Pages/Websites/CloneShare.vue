<script setup>
import { computed, onUnmounted, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    website: { type: Object, required: true },
    otherWebsites: { type: Array, default: () => [] },
});

const page = usePage();
const panelToken = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => (
    panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params)
);
const csrfToken = computed(() => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');

const toasts = ref([]);
let toastSeq = 0;
const pushToast = (message, type = 'success') => {
    const id = ++toastSeq;
    toasts.value.push({ id, message, type });
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

const stageText = (stage) => ({
    queued: 'Queued…',
    archiving: 'Archiving website files…',
    exporting_database: 'Exporting database…',
    restoring: 'Restoring into target…',
    packaging: 'Building package…',
    downloading: 'Downloading package…',
    ready: 'Done',
    failed: 'Failed',
}[stage] || 'Working…');

// ---- Clone (this server) ----
const targetWebsiteId = ref('');
const cloneRunning = ref(false);
const cloneStage = ref(null);
const cloneMessage = ref('');
let clonePollTimer = null;

const stopClonePoll = () => {
    window.clearInterval(clonePollTimer);
    clonePollTimer = null;
};

const pollCloneStatus = async (cloneId) => {
    try {
        const response = await fetch(panelRoute('websites.clone-share.status', { id: props.website.id, jobId: cloneId }), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.ok) return;
        cloneStage.value = data.stage;
        if (data.stage === 'ready') {
            stopClonePoll();
            cloneRunning.value = false;
            pushToast('Clone completed.');
        } else if (data.stage === 'failed') {
            stopClonePoll();
            cloneRunning.value = false;
            cloneMessage.value = data.message || 'Clone failed.';
            pushToast(cloneMessage.value, 'error');
        }
    } catch {
        // Network hiccup — next tick retries.
    }
};

const startClone = async () => {
    if (cloneRunning.value || !targetWebsiteId.value) return;
    cloneRunning.value = true;
    cloneStage.value = 'queued';
    cloneMessage.value = '';
    try {
        const data = await postJson(panelRoute('websites.clone-share.clone', { id: props.website.id }), { target_website_id: targetWebsiteId.value });
        stopClonePoll();
        clonePollTimer = window.setInterval(() => pollCloneStatus(data.clone_id), 5000);
        pollCloneStatus(data.clone_id);
    } catch (error) {
        cloneRunning.value = false;
        cloneStage.value = 'failed';
        cloneMessage.value = error?.message || 'Failed to start clone.';
        pushToast(cloneMessage.value, 'error');
    }
};

// ---- Share (this website → another server) ----
const shareRunning = ref(false);
const shareStage = ref(null);
const shareMessage = ref('');
const shareUrl = ref('');
const shareExpiresAt = ref('');
let sharePollTimer = null;

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
        const data = await postJson(panelRoute('websites.clone-share.share', { id: props.website.id }), {});
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

// ---- Import from another server's share link ----
const importUrl = ref('');
const importRunning = ref(false);
const importStage = ref(null);
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

const startImport = async () => {
    if (importRunning.value || !importUrl.value) return;
    importRunning.value = true;
    importStage.value = 'queued';
    importMessage.value = '';
    try {
        const data = await postJson(panelRoute('websites.clone-share.import', { id: props.website.id }), { source_url: importUrl.value });
        stopImportPoll();
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
    stopClonePoll();
    stopSharePoll();
    stopImportPoll();
});
</script>

<template>
    <Head :title="`Clone & Share - ${website.domain}`" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h1 class="text-lg font-semibold">Clone & Share</h1>
                    <p class="text-sm text-slate-500">{{ website.domain }} — files and database only</p>
                    <p class="mt-0.5 text-xs text-slate-400">Runs in the background — stay on this page to watch live progress, or check the notification bell later.</p>
                </div>
                <Link :href="panelRoute('websites.manage', { id: website.id })" class="rounded-lg border px-3 py-2 text-sm dark:border-slate-700">
                    <i class="bi bi-arrow-left mr-1"></i> Website dashboard
                </Link>
            </div>
        </template>

        <div class="mx-auto grid gap-6 p-4 sm:p-6 lg:grid-cols-2">
            <!-- Clone: within this server -->
            <section class="rounded-xl border bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-100"><i class="bi bi-copy mr-1.5 text-cyan-600"></i>Clone</h2>
                <p class="mt-1 text-xs text-slate-500">Copy this website's files and database into another website already on this server.</p>

                <label class="mt-4 block text-xs font-medium text-slate-600 dark:text-slate-300">Target website</label>
                <select v-model="targetWebsiteId" :disabled="cloneRunning" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-800">
                    <option value="" disabled>Select a website you own…</option>
                    <option v-for="site in otherWebsites" :key="site.id" :value="site.id">{{ site.domain }}</option>
                </select>
                <p v-if="otherWebsites.length === 0" class="mt-1 text-xs text-amber-600">You need at least one other website on this server to clone into.</p>

                <div v-if="cloneStage" class="mt-4 rounded-lg border border-slate-200 p-3 text-xs dark:border-slate-700">
                    <div class="flex items-center gap-2">
                        <i v-if="cloneStage === 'ready'" class="bi bi-check-circle-fill text-emerald-600"></i>
                        <i v-else-if="cloneStage === 'failed'" class="bi bi-x-circle-fill text-red-600"></i>
                        <i v-else class="bi bi-arrow-repeat animate-spin text-cyan-600"></i>
                        <span>{{ stageText(cloneStage) }}</span>
                    </div>
                    <p v-if="cloneStage === 'failed' && cloneMessage" class="mt-1 text-red-600">{{ cloneMessage }}</p>
                </div>

                <div class="mt-5 flex justify-end">
                    <button type="button" :disabled="cloneRunning || !targetWebsiteId" class="rounded-lg bg-cyan-600 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-700 disabled:cursor-not-allowed disabled:opacity-50" @click="startClone">
                        {{ cloneRunning ? 'Cloning…' : 'Clone Now' }}
                    </button>
                </div>

                <hr class="my-5 border-slate-200 dark:border-slate-700" />

                <h3 class="text-xs font-semibold text-slate-800 dark:text-slate-100">Import from another server</h3>
                <p class="mt-1 text-xs text-slate-500">Paste a share link generated on another server's Clone & Share page to clone it into this website.</p>
                <input v-model="importUrl" :disabled="importRunning" type="url" placeholder="https://other-server/clone-share/download/…" class="mt-2 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-800" />

                <div v-if="importStage" class="mt-3 rounded-lg border border-slate-200 p-3 text-xs dark:border-slate-700">
                    <div class="flex items-center gap-2">
                        <i v-if="importStage === 'ready'" class="bi bi-check-circle-fill text-emerald-600"></i>
                        <i v-else-if="importStage === 'failed'" class="bi bi-x-circle-fill text-red-600"></i>
                        <i v-else class="bi bi-arrow-repeat animate-spin text-cyan-600"></i>
                        <span>{{ stageText(importStage) }}</span>
                    </div>
                    <p v-if="importStage === 'failed' && importMessage" class="mt-1 text-red-600">{{ importMessage }}</p>
                </div>

                <div class="mt-3 flex justify-end">
                    <button type="button" :disabled="importRunning || !importUrl" class="rounded-lg border border-cyan-600 px-4 py-2 text-sm font-semibold text-cyan-700 hover:bg-cyan-50 disabled:cursor-not-allowed disabled:opacity-50 dark:text-cyan-400 dark:hover:bg-cyan-500/10" @click="startImport">
                        {{ importRunning ? 'Importing…' : 'Import Into This Website' }}
                    </button>
                </div>
            </section>

            <!-- Share: to another server -->
            <section class="rounded-xl border bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-100"><i class="bi bi-share mr-1.5 text-violet-600"></i>Share</h2>
                <p class="mt-1 text-xs text-slate-500">Generate a one-time link for this website's files + database, to clone it on a different server.</p>

                <div class="mt-5 flex justify-end">
                    <button type="button" :disabled="shareRunning" class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-50" @click="startShare">
                        {{ shareRunning ? 'Preparing…' : 'Generate Share Link' }}
                    </button>
                </div>

                <div v-if="shareStage" class="mt-4 rounded-lg border border-slate-200 p-3 text-xs dark:border-slate-700">
                    <div class="flex items-center gap-2">
                        <i v-if="shareStage === 'ready'" class="bi bi-check-circle-fill text-emerald-600"></i>
                        <i v-else-if="shareStage === 'failed'" class="bi bi-x-circle-fill text-red-600"></i>
                        <i v-else class="bi bi-arrow-repeat animate-spin text-violet-600"></i>
                        <span>{{ stageText(shareStage) }}</span>
                    </div>
                    <p v-if="shareStage === 'failed' && shareMessage" class="mt-1 text-red-600">{{ shareMessage }}</p>
                </div>

                <div v-if="shareUrl" class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 p-3 dark:border-emerald-800 dark:bg-emerald-500/10">
                    <p class="break-all text-xs text-emerald-800 dark:text-emerald-300">{{ shareUrl }}</p>
                    <p class="mt-1 text-[11px] text-slate-500">Paste this into the target server's Clone & Share → "Import from another server" field. Valid for 24 hours — no login needed on either side.</p>
                    <div class="mt-2 flex justify-end">
                        <button type="button" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800" @click="copyShareUrl">
                            <i class="bi bi-clipboard mr-1"></i>Copy link
                        </button>
                    </div>
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
