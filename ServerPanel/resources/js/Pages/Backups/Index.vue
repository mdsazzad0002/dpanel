<script setup>
import { computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';

const props = defineProps({
    backupRoot: { type: String, default: '' },
    retentionDays: { type: Number, default: 7 },
    backupSchedule: { type: Object, default: () => ({ enabled: true, time: '02:30' }) },
    remoteUpload: { type: Object, default: () => ({ enabled: false, host: '', path: '', user: '', port: '22' }) },
    runs: { type: Array, default: () => [] },
});

const page = usePage();
const runForm = useForm({
    only: 'all',
});
const settingsForm = useForm({
    schedule_enabled: Boolean(props.backupSchedule?.enabled),
    schedule_time: props.backupSchedule?.time || '02:30',
    retention_days: Number(props.retentionDays || 7),
    remote_upload_enabled: Boolean(props.remoteUpload?.enabled),
    remote_host: props.remoteUpload?.host || '',
    remote_port: Number(props.remoteUpload?.port || 22),
    remote_user: props.remoteUpload?.user || '',
    remote_path: props.remoteUpload?.path || '',
    remote_ssh_key_path: props.remoteUpload?.ssh_key_path || '',
    remote_strict_host_checking: Boolean(props.remoteUpload?.strict_host_checking ?? true),
    remote_ssh_path: props.remoteUpload?.ssh_path || 'ssh',
    remote_scp_path: props.remoteUpload?.scp_path || 'scp',
});
const deleteForm = useForm({});

const totals = computed(() => ({
    runs: props.runs.length,
    files: props.runs.reduce((carry, run) => carry + Number(run.file_count ?? 0), 0),
    size: props.runs.reduce((carry, run) => carry + Number(run.total_size_bytes ?? 0), 0),
}));

const bytesToLabel = (bytes) => {
    const value = Number(bytes || 0);
    if (value < 1024) return `${value} B`;
    if (value < 1024 ** 2) return `${(value / 1024).toFixed(2)} KB`;
    if (value < 1024 ** 3) return `${(value / 1024 ** 2).toFixed(2)} MB`;

    return `${(value / 1024 ** 3).toFixed(2)} GB`;
};

const runBackup = () => {
    runForm.post(route('backups.run'));
};

const deleteRun = (runName) => {
    if (!confirm(`Delete backup run "${runName}"?`)) return;
    deleteForm.delete(route('backups.destroy', runName));
};

const saveSettings = () => {
    settingsForm.patch(route('backups.settings.update'));
};
</script>

<template>
    <Head title="Backups" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Backups</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Create and download panel backups.</p>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ page.props.flash.success }}
            </div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 whitespace-pre-line">
                {{ page.props.flash.error }}
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

            <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Backup Settings</h2>
                <form class="mt-4 grid gap-4 md:grid-cols-2" @submit.prevent="saveSettings">
                    <label class="flex items-center gap-2 text-sm md:col-span-2">
                        <input v-model="settingsForm.schedule_enabled" type="checkbox" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500" />
                        Enable daily backup schedule
                    </label>
                    <div>
                        <label class="mb-1 block text-sm font-medium">Daily Time (HH:MM)</label>
                        <input v-model="settingsForm.schedule_time" type="time" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                        <p v-if="settingsForm.errors.schedule_time" class="mt-1 text-xs text-red-600">{{ settingsForm.errors.schedule_time }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">Retention Days</label>
                        <input v-model.number="settingsForm.retention_days" type="number" min="1" max="3650" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                        <p v-if="settingsForm.errors.retention_days" class="mt-1 text-xs text-red-600">{{ settingsForm.errors.retention_days }}</p>
                    </div>

                    <label class="flex items-center gap-2 text-sm md:col-span-2">
                        <input v-model="settingsForm.remote_upload_enabled" type="checkbox" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500" />
                        Enable auto-upload to remote server after backup
                    </label>

                    <div>
                        <label class="mb-1 block text-sm font-medium">Remote Host</label>
                        <input v-model="settingsForm.remote_host" type="text" placeholder="192.168.1.20 or backup.example.com" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">Remote Port</label>
                        <input v-model.number="settingsForm.remote_port" type="number" min="1" max="65535" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">Remote User</label>
                        <input v-model="settingsForm.remote_user" type="text" placeholder="ubuntu" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">Remote Path</label>
                        <input v-model="settingsForm.remote_path" type="text" placeholder="/home/ubuntu/backups/serverpanel" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">SSH Key Path (optional)</label>
                        <input v-model="settingsForm.remote_ssh_key_path" type="text" placeholder="/home/user/.ssh/id_rsa" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">SSH Binary</label>
                        <input v-model="settingsForm.remote_ssh_path" type="text" placeholder="ssh" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">SCP Binary</label>
                        <input v-model="settingsForm.remote_scp_path" type="text" placeholder="scp" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    </div>
                    <label class="flex items-center gap-2 text-sm md:col-span-2">
                        <input v-model="settingsForm.remote_strict_host_checking" type="checkbox" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500" />
                        Strict host key checking
                    </label>

                    <div class="md:col-span-2">
                        <button type="submit" :disabled="settingsForm.processing" class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-60">
                            {{ settingsForm.processing ? 'Saving...' : 'Save Backup Settings' }}
                        </button>
                    </div>
                </form>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <form class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between" @submit.prevent="runBackup">
                    <div class="grid gap-1">
                        <label class="text-sm font-medium">Backup Type</label>
                        <select v-model="runForm.only" class="rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                            <option value="all">All (DB + Files)</option>
                            <option value="db">Database Only</option>
                            <option value="files">Files Only</option>
                        </select>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="submit" :disabled="runForm.processing" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60">
                            {{ runForm.processing ? 'Running...' : 'Run Backup Now' }}
                        </button>
                        <Link :href="route('monitoring.index')" class="rounded-md border border-slate-300 px-4 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                            Monitoring
                        </Link>
                    </div>
                </form>
                <p class="mt-3 text-xs text-slate-500">Backup folder: {{ backupRoot }}</p>
                <p class="mt-1 text-xs text-slate-500">
                    Schedule: {{ backupSchedule?.enabled ? `Enabled at ${backupSchedule?.time || '02:30'}` : 'Disabled' }}
                </p>
                <p class="mt-1 text-xs text-slate-500">
                    Remote upload:
                    <span v-if="remoteUpload?.enabled">
                        Enabled ({{ remoteUpload?.user }}@{{ remoteUpload?.host }}:{{ remoteUpload?.path }} | port {{ remoteUpload?.port }})
                    </span>
                    <span v-else>Disabled</span>
                </p>
            </section>

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
                                        <span class="font-mono">{{ file.name }}</span>
                                        <span class="text-slate-500">({{ bytesToLabel(file.size_bytes) }})</span>
                                        <a :href="route('backups.download', { run: run.name, file: file.name })" class="rounded border border-slate-300 px-2 py-0.5 hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                                            Download
                                        </a>
                                    </li>
                                </ul>
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-500">{{ run.created_at || '-' }}</td>
                            <td class="px-4 py-3">{{ run.file_count || 0 }}</td>
                            <td class="px-4 py-3">{{ bytesToLabel(run.total_size_bytes || 0) }}</td>
                            <td class="px-4 py-3">
                                <button type="button" class="rounded-md border border-red-300 px-3 py-1.5 text-xs text-red-700 hover:bg-red-50 dark:border-red-700 dark:text-red-400" @click="deleteRun(run.name)">
                                    Delete Run
                                </button>
                            </td>
                        </tr>
                        <tr v-if="runs.length === 0">
                            <td colspan="5" class="px-4 py-8 text-center text-slate-500">No backup runs found.</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
