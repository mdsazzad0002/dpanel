<script setup>
import { computed, ref } from 'vue';
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

const exportFiles = ref(true);
const exportDatabase = ref(Boolean(props.databaseConnection?.available));
const exportProgress = ref(0);
const exportPhase = ref('select');
const exportLoading = ref(false);
const activeExportItems = ref([]);
const exportItemStatus = ref({});
const toasts = ref([]);
let toastSeq = 0;

const pushToast = (message, type = 'success') => {
    const id = ++toastSeq;
    toasts.value.push({ id, message, type });
    window.setTimeout(() => {
        toasts.value = toasts.value.filter((toast) => toast.id !== id);
    }, 4000);
};

const exportItemLabel = (type) => (type === 'database' ? 'SQL database' : 'Website files');
const exportStatusLabel = (type, status) => {
    const label = exportItemLabel(type);
    switch (status) {
        case 'preparing': return `Preparing ${label}…`;
        case 'zipping': return `Creating ZIP for ${label}…`;
        case 'downloading': return `Downloading ${label}…`;
        case 'done': return `${label} downloaded`;
        case 'error': return `${label} failed`;
        default: return `${label} queued`;
    }
};
const currentExportStatusText = computed(() => {
    if (exportPhase.value === 'complete') return 'All selected items downloaded';
    if (exportPhase.value === 'error') {
        const failed = activeExportItems.value.find((type) => exportItemStatus.value[type] === 'error');
        return failed ? exportStatusLabel(failed, 'error') : 'Export failed';
    }
    const inProgress = activeExportItems.value.find((type) => !['done', 'error'].includes(exportItemStatus.value[type]));
    return inProgress ? exportStatusLabel(inProgress, exportItemStatus.value[inProgress]) : 'Preparing…';
});

const resetExport = () => {
    exportProgress.value = 0;
    exportPhase.value = 'select';
    activeExportItems.value = [];
    exportItemStatus.value = {};
};

const quickExport = async () => {
    if (exportLoading.value || (!exportFiles.value && !exportDatabase.value)) return;

    const queue = [];
    if (exportDatabase.value) queue.push('database');
    if (exportFiles.value) queue.push('files');

    activeExportItems.value = queue;
    exportItemStatus.value = Object.fromEntries(queue.map((type) => [type, 'pending']));
    exportLoading.value = true;
    exportPhase.value = 'running';
    exportProgress.value = 0;

    const segment = 100 / queue.length;
    let downloadedCount = 0;

    for (let index = 0; index < queue.length; index += 1) {
        const type = queue[index];
        const base = index * segment;
        exportItemStatus.value[type] = 'preparing';
        exportProgress.value = Math.round(base + segment * 0.15);

        let zipTimer = null;
        if (type === 'files') {
            zipTimer = window.setTimeout(() => {
                exportItemStatus.value[type] = 'zipping';
                exportProgress.value = Math.round(base + segment * 0.55);
            }, 500);
        }

        try {
            const response = await fetch(panelRoute('websites.quick-export', { id: props.website.id }), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/zip, application/sql, application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken.value,
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ type }),
            });

            if (!response.ok) {
                const data = await response.json().catch(() => ({}));
                throw new Error(data.message || `Failed to export ${exportItemLabel(type)}.`);
            }

            const disposition = response.headers.get('Content-Disposition') || '';
            const encodedName = disposition.match(/filename\*=UTF-8''([^;]+)/i)?.[1];
            const plainName = disposition.match(/filename="?([^";]+)"?/i)?.[1];
            const fallbackExt = type === 'database' ? 'sql' : 'zip';
            const fileName = encodedName ? decodeURIComponent(encodedName) : (plainName || `${props.website.domain}-${type}-export.${fallbackExt}`);
            const blob = await response.blob();

            window.clearTimeout(zipTimer);
            exportItemStatus.value[type] = 'downloading';
            exportProgress.value = Math.round(base + segment * 0.9);

            const downloadUrl = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = downloadUrl;
            link.download = fileName;
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(downloadUrl);

            exportItemStatus.value[type] = 'done';
            downloadedCount += 1;
            exportProgress.value = Math.round((index + 1) * segment);
        } catch (error) {
            window.clearTimeout(zipTimer);
            exportItemStatus.value[type] = 'error';
            exportPhase.value = 'error';
            pushToast(error?.message || `Failed to export ${exportItemLabel(type)}.`, 'error');
            exportLoading.value = false;
            return;
        }
    }

    exportProgress.value = 100;
    exportPhase.value = 'complete';
    exportLoading.value = false;
    pushToast(`${downloadedCount} file${downloadedCount === 1 ? '' : 's'} downloaded separately.`, 'success');
};
</script>

<template>
    <Head :title="`Quick Export - ${website.domain}`" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h1 class="text-lg font-semibold">Quick Export</h1>
                    <p class="text-sm text-slate-500">{{ website.domain }} — each selected item downloads separately</p>
                </div>
                <Link :href="panelRoute('websites.manage', { id: website.id })" class="rounded-lg border px-3 py-2 text-sm dark:border-slate-700">
                    <i class="bi bi-arrow-left mr-1"></i> Website dashboard
                </Link>
            </div>
        </template>

        <div class="mx-auto max-w-2xl space-y-6 p-4 sm:p-6">
            <section class="rounded-xl border bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                        <input v-model="exportFiles" :disabled="exportLoading" type="checkbox" class="mt-0.5 rounded border-slate-300 text-violet-600 focus:ring-violet-500" />
                        <span>
                            <span class="block text-sm font-semibold text-slate-800 dark:text-slate-100">Website files</span>
                            <span class="mt-1 block text-xs text-slate-500">Downloads as its own ZIP</span>
                        </span>
                    </label>
                    <label class="flex items-start gap-3 rounded-xl border border-slate-200 p-4 dark:border-slate-700" :class="databaseConnection.available ? 'cursor-pointer' : 'cursor-not-allowed opacity-55'">
                        <input v-model="exportDatabase" type="checkbox" :disabled="exportLoading || !databaseConnection.available" class="mt-0.5 rounded border-slate-300 text-violet-600 focus:ring-violet-500" />
                        <span>
                            <span class="block text-sm font-semibold text-slate-800 dark:text-slate-100">SQL database</span>
                            <span class="mt-1 block text-xs text-slate-500">{{ databaseConnection.available ? `Downloads as its own .sql file (${databaseConnection.database_name})` : 'No active database linked' }}</span>
                        </span>
                    </label>
                </div>

                <div v-if="exportLoading || exportPhase === 'complete' || exportPhase === 'error'" class="mt-5 space-y-4">
                    <div>
                        <div class="mb-2 flex justify-between text-xs font-medium text-slate-500">
                            <span>{{ currentExportStatusText }}</span>
                            <span>{{ exportProgress }}%</span>
                        </div>
                        <div class="h-2.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                            <div class="h-full rounded-full transition-all duration-500" :class="exportPhase === 'error' ? 'bg-red-500' : 'bg-violet-600'" :style="{ width: `${exportProgress}%` }"></div>
                        </div>
                    </div>
                    <ol class="space-y-2 text-sm">
                        <li v-for="type in activeExportItems" :key="type" class="flex items-center gap-2" :class="exportItemStatus[type] === 'done' ? 'text-emerald-600' : exportItemStatus[type] === 'error' ? 'text-red-600' : 'text-slate-500'">
                            <i :class="exportItemStatus[type] === 'done' ? 'bi bi-check-circle-fill' : exportItemStatus[type] === 'error' ? 'bi bi-x-circle-fill' : 'bi bi-circle'"></i>
                            {{ exportStatusLabel(type, exportItemStatus[type]) }}
                        </li>
                    </ol>
                </div>

                <p v-if="!exportFiles && !exportDatabase" class="mt-4 text-sm text-red-600">Select at least one item to export.</p>

                <div class="mt-5 flex justify-end gap-3">
                    <button v-if="exportPhase === 'complete' || exportPhase === 'error'" type="button" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 dark:border-slate-600 dark:text-slate-200" @click="resetExport">
                        Export again
                    </button>
                    <button v-else type="button" :disabled="exportLoading || (!exportFiles && !exportDatabase)" class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-50" @click="quickExport">
                        {{ exportLoading ? 'Preparing…' : 'Prepare & Download Selected' }}
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
