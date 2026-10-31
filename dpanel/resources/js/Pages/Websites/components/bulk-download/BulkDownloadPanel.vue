<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    websites: { type: Array, default: () => [] },
    panelRoute: { type: Function, required: true },
});
const emit = defineEmits(['close', 'toast']);

const includeFiles = ref(true);
const includeDatabases = ref(false);
const autoDownload = ref(true);
const starting = ref(false);
const error = ref('');
// Each queued export: { key, website_id, domain, label, export_id, stage, downloadUrl, message }
const items = ref([]);
let pollTimer = null;

const running = computed(() => items.value.some((item) => ['queued', 'zipping'].includes(item.stage)));
const readyCount = computed(() => items.value.filter((item) => ['ready', 'downloaded'].includes(item.stage)).length);

const stageLabel = (stage) => ({
    queued: 'Queued',
    zipping: 'Preparing archive…',
    ready: 'Ready',
    downloaded: 'Download started',
    error: 'Failed',
}[stage] || stage);

const stopPolling = () => {
    window.clearInterval(pollTimer);
    pollTimer = null;
};

const triggerDownload = (item) => {
    if (!item.downloadUrl) return;
    // Native same-origin navigation so large archives aren't buffered as JS blobs.
    const link = document.createElement('a');
    link.href = item.downloadUrl;
    document.body.appendChild(link);
    link.click();
    link.remove();
    item.stage = 'downloaded';
};

const poll = async () => {
    for (const item of items.value.filter((entry) => ['queued', 'zipping'].includes(entry.stage))) {
        try {
            const response = await fetch(props.panelRoute('websites.quick-export.status', { id: item.website_id, exportId: item.export_id }), {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok || !data.ok) continue;
            if (data.stage === 'ready') {
                item.downloadUrl = data.download_url;
                item.stage = 'ready';
                item.message = data.message || '';
                if (autoDownload.value) triggerDownload(item);
            } else if (data.stage === 'failed') {
                item.stage = 'error';
                item.message = data.message || 'Export failed.';
            } else if (data.stage) {
                item.stage = data.stage;
            }
        } catch {
            // Network hiccup — the next tick retries.
        }
    }
    if (!running.value) {
        stopPolling();
        const failed = items.value.filter((item) => item.stage === 'error').length;
        emit('toast', failed ? `${failed} export(s) failed.` : 'All bulk downloads are ready.', failed ? 'error' : 'success');
    }
};

const start = async () => {
    starting.value = true; error.value = '';
    try {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const response = await fetch(props.panelRoute('websites.bulk-export'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({
                website_ids: props.websites.map((site) => String(site.id)),
                files: includeFiles.value,
                databases: includeDatabases.value,
            }),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.ok) {
            throw new Error(data.message || Object.values(data.errors || {}).flat().join(' ') || 'Bulk export failed to start.');
        }
        items.value = data.items.map((item) => ({ ...item, key: item.export_id, stage: 'queued', downloadUrl: null, message: '' }));
        stopPolling();
        pollTimer = window.setInterval(poll, 5000);
    } catch (e) {
        error.value = e.message;
    } finally {
        starting.value = false;
    }
};

const close = () => {
    // Jobs keep running on the queue; the notification bell reports them.
    stopPolling();
    emit('close');
};

watch(() => props.open, (open) => {
    if (open && !running.value) { items.value = []; error.value = ''; }
});
onBeforeUnmount(stopPolling);
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-50">
            <button type="button" aria-label="Close bulk download" class="absolute inset-0 bg-slate-950/50" @click="close" />
            <aside class="absolute inset-y-0 right-0 flex w-full flex-col bg-white shadow-2xl sm:w-[34rem] dark:bg-slate-900">
                <header class="flex items-center justify-between border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                    <div>
                        <h2 class="font-semibold text-slate-900 dark:text-white">Bulk Download</h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ websites.length }} website(s) selected — each item downloads separately</p>
                    </div>
                    <button type="button" class="rounded-lg px-3 py-2 text-xl text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800" @click="close">×</button>
                </header>

                <div class="flex-1 space-y-5 overflow-y-auto p-5">
                    <div v-if="!items.length" class="space-y-3">
                        <div class="flex flex-wrap gap-2">
                            <span v-for="site in websites" :key="site.id" class="rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ site.domain }}</span>
                        </div>
                        <label class="flex items-start gap-3 rounded-xl border border-slate-200 p-3 text-sm dark:border-slate-700">
                            <input v-model="includeFiles" type="checkbox" class="mt-0.5 rounded" />
                            <span><span class="font-medium text-slate-800 dark:text-slate-200">Website files</span><span class="block text-xs text-slate-500">One .zip per website</span></span>
                        </label>
                        <label class="flex items-start gap-3 rounded-xl border border-slate-200 p-3 text-sm dark:border-slate-700">
                            <input v-model="includeDatabases" type="checkbox" class="mt-0.5 rounded" />
                            <span><span class="font-medium text-slate-800 dark:text-slate-200">Databases</span><span class="block text-xs text-slate-500">Every active database of each website, one .sql each</span></span>
                        </label>
                    </div>

                    <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <input v-model="autoDownload" type="checkbox" class="rounded" />
                        Auto download when a link is ready
                    </label>
                    <p v-if="autoDownload && items.length > 1" class="text-[11px] text-slate-400">If your browser asks to allow multiple downloads, choose Allow — or use the Download buttons below.</p>

                    <div v-if="error" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">{{ error }}</div>

                    <ul v-if="items.length" class="space-y-2">
                        <li v-for="item in items" :key="item.key" class="flex items-center justify-between gap-3 rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-200">{{ item.domain }} <span class="font-normal text-slate-500">— {{ item.label }}</span></p>
                                <p class="text-xs" :class="item.stage === 'error' ? 'text-red-600' : 'text-slate-500'">
                                    <i v-if="['queued', 'zipping'].includes(item.stage)" class="bi bi-arrow-repeat mr-1 inline-block animate-spin"></i>
                                    {{ item.stage === 'error' ? item.message : stageLabel(item.stage) }}
                                </p>
                                <p v-if="item.message && item.stage !== 'error'" class="text-[11px] text-amber-600">{{ item.message }}</p>
                            </div>
                            <button v-if="item.downloadUrl" type="button" class="shrink-0 rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700" @click="triggerDownload(item)">
                                {{ item.stage === 'downloaded' ? 'Download again' : 'Download' }}
                            </button>
                        </li>
                    </ul>
                </div>

                <footer class="flex items-center justify-between gap-3 border-t border-slate-200 px-5 py-4 dark:border-slate-800">
                    <span v-if="items.length" class="text-xs text-slate-500">{{ readyCount }} / {{ items.length }} ready</span>
                    <span v-else></span>
                    <div class="flex gap-3">
                        <button type="button" class="rounded-lg border border-slate-300 px-4 py-2 text-sm dark:border-slate-700" @click="close">Close</button>
                        <button v-if="!items.length" type="button" :disabled="starting || !websites.length || (!includeFiles && !includeDatabases)" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50" @click="start">
                            <i class="bi bi-download mr-1"></i>{{ starting ? 'Starting…' : 'Start download' }}
                        </button>
                    </div>
                </footer>
            </aside>
        </div>
    </Teleport>
</template>
