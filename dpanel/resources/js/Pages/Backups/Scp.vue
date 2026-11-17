<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

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
    <Head :title="t('backups.scp.title')" />
    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">{{ t('backups.scp.title') }}</h1>
                <p class="text-sm text-slate-500">{{ t('backups.scp.subtitle') }}</p>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ page.props.flash.success }}</div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ page.props.flash.error }}</div>

            <section class="grid gap-4 md:grid-cols-3">
                <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500">{{ t('backups.scp.service') }}</p>
                    <p class="mt-2 text-xl font-semibold" :class="remoteUpload?.enabled ? 'text-emerald-600' : 'text-slate-500'">{{ remoteUpload?.enabled ? t('backups.scp.enabled') : t('backups.scp.disabled') }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500">{{ t('backups.scp.lastStatus') }}</p>
                    <p class="mt-2 text-xl font-semibold capitalize" :class="scpStatus?.status === 'success' ? 'text-emerald-600' : scpStatus?.status === 'failed' ? 'text-red-600' : 'text-slate-500'">{{ ['success', 'failed', 'never'].includes(scpStatus?.status || 'never') ? t(`backups.scp.status.${scpStatus?.status || 'never'}`) : scpStatus.status }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500">{{ t('backups.scp.lastAttempt') }}</p>
                    <p class="mt-2 text-sm font-semibold">{{ scpStatus?.updated_at || t('backups.scp.noUpload') }}</p>
                    <p v-if="scpStatus?.run" class="mt-1 font-mono text-xs text-slate-500">{{ t('backups.scp.run', { run: scpStatus.run }) }}</p>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <div class="mb-4">
                    <h2 class="font-semibold">{{ t('backups.scp.schedule') }}</h2>
                    <p class="text-xs text-slate-500">{{ t('backups.scp.scheduleHelp') }}</p>
                </div>
                <form class="grid gap-4 md:grid-cols-2" @submit.prevent="saveSchedule">
                    <label class="flex items-center gap-2 text-sm md:col-span-2"><input v-model="scheduleForm.schedule_enabled" type="checkbox" class="rounded" /> {{ t('backups.scp.enableDaily') }}</label>
                    <div><label class="mb-1 block text-sm font-medium">{{ t('backups.scp.time') }}</label><input v-model="scheduleForm.schedule_time" type="time" class="w-full rounded-md border px-3 py-2 dark:bg-slate-800" /></div>
                    <div><label class="mb-1 block text-sm font-medium">{{ t('backups.scp.retention') }}</label><div class="relative"><input v-model.number="scheduleForm.retention_days" type="number" min="1" max="3650" class="w-full rounded-md border px-3 py-2 pr-14 dark:bg-slate-800" /><span class="absolute right-3 top-2.5 text-xs text-slate-400">{{ t('backups.scp.days') }}</span></div></div>
                    <div class="md:col-span-2"><button :disabled="scheduleForm.processing" class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-60">{{ scheduleForm.processing ? t('backups.scp.saving') : t('backups.scp.saveSchedule') }}</button></div>
                </form>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <div class="mb-4"><h2 class="font-semibold">{{ t('backups.scp.connection') }}</h2><p class="text-xs text-slate-500">{{ t('backups.scp.connectionHelp') }}</p></div>
                <p v-if="scpStatus?.message" class="mb-4 rounded-md bg-slate-50 p-3 text-sm dark:bg-slate-800">{{ scpStatus.message }}</p>
                <form class="grid gap-4 md:grid-cols-2" @submit.prevent="save">
                    <label class="flex items-center gap-2 text-sm md:col-span-2"><input v-model="form.remote_upload_enabled" type="checkbox" class="rounded" /> {{ t('backups.scp.enableUpload') }}</label>
                    <div><label class="mb-1 block text-sm font-medium">{{ t('backups.scp.host') }}</label><input v-model="form.remote_host" class="w-full rounded-md border px-3 py-2 dark:bg-slate-800" placeholder="backup.example.com" /></div>
                    <div><label class="mb-1 block text-sm font-medium">{{ t('backups.scp.port') }}</label><input v-model.number="form.remote_port" type="number" min="1" max="65535" class="w-full rounded-md border px-3 py-2 dark:bg-slate-800" /></div>
                    <div><label class="mb-1 block text-sm font-medium">{{ t('backups.scp.user') }}</label><input v-model="form.remote_user" class="w-full rounded-md border px-3 py-2 dark:bg-slate-800" placeholder="backup" /></div>
                    <div><label class="mb-1 block text-sm font-medium">{{ t('backups.scp.path') }}</label><input v-model="form.remote_path" class="w-full rounded-md border px-3 py-2 dark:bg-slate-800" placeholder="/home/backup/dpanel" /></div>
                    <div class="md:col-span-2"><label class="mb-1 block text-sm font-medium">{{ t('backups.scp.keyPath') }}</label><input v-model="form.remote_ssh_key_path" class="w-full rounded-md border px-3 py-2 dark:bg-slate-800" placeholder="/root/.ssh/id_ed25519" /></div>
                    <div><label class="mb-1 block text-sm font-medium">{{ t('backups.scp.ssh') }}</label><input v-model="form.remote_ssh_path" class="w-full rounded-md border px-3 py-2 dark:bg-slate-800" /></div>
                    <div><label class="mb-1 block text-sm font-medium">{{ t('backups.scp.scp') }}</label><input v-model="form.remote_scp_path" class="w-full rounded-md border px-3 py-2 dark:bg-slate-800" /></div>
                    <label class="flex items-center gap-2 text-sm md:col-span-2"><input v-model="form.remote_strict_host_checking" type="checkbox" class="rounded" /> {{ t('backups.scp.strict') }}</label>
                    <div class="flex gap-2 md:col-span-2">
                        <button :disabled="form.processing" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-60">{{ form.processing ? t('backups.scp.saving') : t('backups.scp.saveSettings') }}</button>
                        <Link :href="route('backups.index')" class="rounded-md border px-4 py-2 text-sm">{{ t('backups.scp.back') }}</Link>
                    </div>
                </form>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
