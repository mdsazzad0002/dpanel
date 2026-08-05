<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';

const props = defineProps({
    remoteUpload: { type: Object, default: () => ({}) },
    scpStatus: { type: Object, default: () => ({ status: 'never' }) },
    backupSchedule: { type: Object, default: () => ({ enabled: true, time: '02:30', retention_days: 7 }) },
});
const page = usePage();
const scheduleForm = useForm({
    schedule_enabled: Boolean(props.backupSchedule?.enabled),
    schedule_time: props.backupSchedule?.time || '02:30',
    retention_days: Number(props.backupSchedule?.retention_days || 7),
});
const form = useForm({
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
const save = () => form.patch(route('backups.scp.settings.update'));
const saveSchedule = () => scheduleForm.patch(route('backups.settings.update'));
</script>

<template>
    <Head title="SCP Backup" />
    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">SCP Backup</h1>
                <p class="text-sm text-slate-500">Remote backup transfer settings and latest status.</p>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ page.props.flash.success }}</div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ page.props.flash.error }}</div>

            <section class="grid gap-4 md:grid-cols-3">
                <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500">SCP Service</p>
                    <p class="mt-2 text-xl font-semibold" :class="remoteUpload?.enabled ? 'text-emerald-600' : 'text-slate-500'">{{ remoteUpload?.enabled ? 'Enabled' : 'Disabled' }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500">Last Status</p>
                    <p class="mt-2 text-xl font-semibold capitalize" :class="scpStatus?.status === 'success' ? 'text-emerald-600' : scpStatus?.status === 'failed' ? 'text-red-600' : 'text-slate-500'">{{ scpStatus?.status || 'never' }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500">Last Attempt Time</p>
                    <p class="mt-2 text-sm font-semibold">{{ scpStatus?.updated_at || 'No upload yet' }}</p>
                    <p v-if="scpStatus?.run" class="mt-1 font-mono text-xs text-slate-500">Run: {{ scpStatus.run }}</p>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <div class="mb-4">
                    <h2 class="font-semibold">Backup Schedule</h2>
                    <p class="text-xs text-slate-500">Configure automatic backup time and local retention period.</p>
                </div>
                <form class="grid gap-4 md:grid-cols-2" @submit.prevent="saveSchedule">
                    <label class="flex items-center gap-2 text-sm md:col-span-2"><input v-model="scheduleForm.schedule_enabled" type="checkbox" class="rounded" /> Enable daily backup schedule</label>
                    <div><label class="mb-1 block text-sm font-medium">Backup Time</label><input v-model="scheduleForm.schedule_time" type="time" class="w-full rounded-md border px-3 py-2 dark:bg-slate-800" /></div>
                    <div><label class="mb-1 block text-sm font-medium">Retention Period</label><div class="relative"><input v-model.number="scheduleForm.retention_days" type="number" min="1" max="3650" class="w-full rounded-md border px-3 py-2 pr-14 dark:bg-slate-800" /><span class="absolute right-3 top-2.5 text-xs text-slate-400">days</span></div></div>
                    <div class="md:col-span-2"><button :disabled="scheduleForm.processing" class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-60">{{ scheduleForm.processing ? 'Saving...' : 'Save Schedule' }}</button></div>
                </form>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <div class="mb-4"><h2 class="font-semibold">SCP Connection</h2><p class="text-xs text-slate-500">Remote server credentials and transfer options.</p></div>
                <p v-if="scpStatus?.message" class="mb-4 rounded-md bg-slate-50 p-3 text-sm dark:bg-slate-800">{{ scpStatus.message }}</p>
                <form class="grid gap-4 md:grid-cols-2" @submit.prevent="save">
                    <label class="flex items-center gap-2 text-sm md:col-span-2"><input v-model="form.remote_upload_enabled" type="checkbox" class="rounded" /> Enable SCP upload after every successful backup</label>
                    <div><label class="mb-1 block text-sm font-medium">Remote Host</label><input v-model="form.remote_host" class="w-full rounded-md border px-3 py-2 dark:bg-slate-800" placeholder="backup.example.com" /></div>
                    <div><label class="mb-1 block text-sm font-medium">Port</label><input v-model.number="form.remote_port" type="number" min="1" max="65535" class="w-full rounded-md border px-3 py-2 dark:bg-slate-800" /></div>
                    <div><label class="mb-1 block text-sm font-medium">Remote User</label><input v-model="form.remote_user" class="w-full rounded-md border px-3 py-2 dark:bg-slate-800" placeholder="backup" /></div>
                    <div><label class="mb-1 block text-sm font-medium">Remote Path</label><input v-model="form.remote_path" class="w-full rounded-md border px-3 py-2 dark:bg-slate-800" placeholder="/home/backup/dpanel" /></div>
                    <div class="md:col-span-2"><label class="mb-1 block text-sm font-medium">SSH Private Key Path</label><input v-model="form.remote_ssh_key_path" class="w-full rounded-md border px-3 py-2 dark:bg-slate-800" placeholder="/root/.ssh/id_ed25519" /></div>
                    <div><label class="mb-1 block text-sm font-medium">SSH Binary</label><input v-model="form.remote_ssh_path" class="w-full rounded-md border px-3 py-2 dark:bg-slate-800" /></div>
                    <div><label class="mb-1 block text-sm font-medium">SCP Binary</label><input v-model="form.remote_scp_path" class="w-full rounded-md border px-3 py-2 dark:bg-slate-800" /></div>
                    <label class="flex items-center gap-2 text-sm md:col-span-2"><input v-model="form.remote_strict_host_checking" type="checkbox" class="rounded" /> Strict host key checking</label>
                    <div class="flex gap-2 md:col-span-2">
                        <button :disabled="form.processing" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-60">{{ form.processing ? 'Saving...' : 'Save SCP Settings' }}</button>
                        <Link :href="route('backups.index')" class="rounded-md border px-4 py-2 text-sm">Back to Backups</Link>
                    </div>
                </form>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
