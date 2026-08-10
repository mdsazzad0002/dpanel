<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    backups: { type: Array, default: () => [] },
    retention: { type: Object, default: () => ({ enabled: true, days: 15, time: '03:30' }) },
    canManageRetention: { type: Boolean, default: false },
});

const page = usePage();
const retentionForm = useForm({
    enabled: Boolean(props.retention?.enabled),
    days: Number(props.retention?.days || 15),
    time: props.retention?.time || '03:30',
});

const formatSize = (bytes) => {
    const value = Number(bytes || 0);
    if (value < 1024) return `${value} B`;
    if (value < 1024 ** 2) return `${(value / 1024).toFixed(1)} KB`;
    if (value < 1024 ** 3) return `${(value / 1024 ** 2).toFixed(1)} MB`;
    return `${(value / 1024 ** 3).toFixed(2)} GB`;
};

const downloading = ref('');
const restoring = ref('');
const downloadError = ref('');
const noticeType = ref('error');

const downloadBackup = async (backup) => {
    downloading.value = backup.id;
    downloadError.value = '';
    noticeType.value = 'error';

    try {
        const response = await fetch(route('trash-backups.download', { id: backup.id }), {
            headers: { Accept: 'application/zip, application/json' },
            credentials: 'same-origin',
        });
        if (!response.ok) {
            const payload = await response.json().catch(() => ({}));
            throw new Error(payload.message || `Download failed with HTTP ${response.status}.`);
        }

        const blob = await response.blob();
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = backup.file_name;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    } catch (error) {
        downloadError.value = error?.message || 'Trash backup download failed.';
    } finally {
        downloading.value = '';
    }
};

const restoreBackup = async (backup) => {
    if (!confirm(`Restore ${backup.domain} from Trash Backup?`)) return;
    restoring.value = backup.id;
    downloadError.value = '';
    noticeType.value = 'error';

    try {
        const response = await fetch(route('trash-backups.restore', { id: backup.id }), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            credentials: 'same-origin',
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(payload.message || `Recovery failed with HTTP ${response.status}.`);
        backup.can_restore = false;
        downloadError.value = payload.message || 'Website recovered successfully.';
        noticeType.value = 'success';
    } catch (error) {
        downloadError.value = error?.message || 'Website recovery failed.';
    } finally {
        restoring.value = '';
    }
};

const saveRetention = () => {
    retentionForm.patch(route('trash-backups.retention.update'), { preserveScroll: true });
};
</script>

<template>
    <Head title="Trash Backup" />
    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Trash Backup</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Recover deleted websites or download their automatic archives.</p>
            </div>
        </template>

        <div v-if="downloadError" class="fixed bottom-5 right-5 z-50 max-w-sm rounded-lg border px-4 py-3 text-sm font-medium shadow-lg" :class="noticeType === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-red-200 bg-red-50 text-red-700'">
            {{ downloadError }}
        </div>

        <div class="space-y-5">
            <div v-if="page.props.flash?.success" class="fixed bottom-5 right-5 z-50 max-w-sm rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700 shadow-lg">
                {{ page.props.flash.success }}
            </div>

            <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <h2 class="font-semibold">Automatic Cleanup</h2>
                        <p class="mt-1 text-sm text-slate-500">Configured automatically. No manual Artisan command is required.</p>
                    </div>
                    <form v-if="canManageRetention" class="grid gap-3 sm:grid-cols-[auto_140px_140px_auto] sm:items-end" @submit.prevent="saveRetention">
                        <label class="flex h-10 items-center gap-2 text-sm font-medium">
                            <input v-model="retentionForm.enabled" type="checkbox" class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500" />
                            Enabled
                        </label>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500">Keep For</label>
                            <div class="flex items-center gap-2">
                                <input v-model.number="retentionForm.days" type="number" min="1" max="3650" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                                <span class="text-sm">days</span>
                            </div>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500">Daily Time</label>
                            <input v-model="retentionForm.time" type="time" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                        </div>
                        <button type="submit" :disabled="retentionForm.processing" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-60">
                            {{ retentionForm.processing ? 'Saving...' : 'Save' }}
                        </button>
                    </form>
                    <p v-else class="text-sm font-medium text-slate-500">
                        {{ retention.enabled ? `${retention.days} days · Daily at ${retention.time}` : 'Disabled' }}
                    </p>
                </div>
            </section>

            <div class="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            <div v-if="backups.length === 0" class="px-6 py-14 text-center">
                <i class="bi bi-trash3 text-3xl text-slate-300"></i>
                <p class="mt-3 font-medium">No deleted website backups</p>
                <p class="mt-1 text-sm text-slate-500">A downloadable ZIP will appear here after a website is deleted.</p>
            </div>
            <div v-else class="divide-y divide-slate-200 dark:divide-slate-800">
                <div v-for="backup in backups" :key="backup.id" class="flex flex-col gap-4 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <i class="bi bi-file-earmark-zip text-amber-500"></i>
                            <p class="truncate font-semibold">{{ backup.domain }}</p>
                        </div>
                        <p class="mt-1 truncate text-xs text-slate-500">{{ backup.file_name }}</p>
                        <p class="mt-1 text-xs text-slate-400">{{ formatSize(backup.file_size) }} · Deleted {{ backup.created_at || '-' }}</p>
                    </div>
                    <div v-if="backup.available" class="flex shrink-0 flex-wrap gap-2">
                        <button
                            v-if="backup.can_restore"
                            type="button"
                            :disabled="Boolean(restoring)"
                            class="inline-flex items-center justify-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-60"
                            @click="restoreBackup(backup)"
                        >
                            <i :class="restoring === backup.id ? 'bi bi-arrow-repeat animate-spin' : 'bi bi-arrow-counterclockwise'"></i>
                            {{ restoring === backup.id ? 'Recovering...' : 'Restore Website' }}
                        </button>
                        <span v-else class="inline-flex items-center px-2 text-xs font-medium text-slate-500">Website already exists</span>
                        <button
                            type="button"
                            :disabled="downloading === backup.id"
                            class="inline-flex items-center justify-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700 dark:bg-white dark:text-slate-900"
                            @click="downloadBackup(backup)"
                        >
                            <i :class="downloading === backup.id ? 'bi bi-arrow-repeat animate-spin' : 'bi bi-download'"></i>
                            {{ downloading === backup.id ? 'Downloading...' : 'Download ZIP' }}
                        </button>
                    </div>
                    <span v-else class="text-sm font-medium text-red-500">Archive missing</span>
                </div>
            </div>
        </div>
        </div>
    </AuthenticatedLayout>
</template>
