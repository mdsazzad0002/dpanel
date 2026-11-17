<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    driver: { type: String, required: true },
    active: { type: Boolean, default: false },
    config: { type: Object, default: () => ({}) },
    accountDelaySeconds: { type: Number, default: 5 },
});
const page = usePage();
const names = computed(() => ({ local: t('backups.storage.index.local'), google_drive: 'Google Drive', s3: 'Amazon S3', s3_compatible: t('backups.storage.index.s3c'), sftp: 'SFTP', custom: t('backups.storage.index.custom') }));
const form = useForm({
    activate: props.active,
    path: props.config.path || '',
    endpoint: props.config.endpoint || '',
    bucket: props.config.bucket || '',
    region: props.config.region || '',
    remote_name: props.config.remote_name || '',
    host: props.config.host || '',
    port: Number(props.config.port || 22),
    username: props.config.username || '',
    key_path: props.config.key_path || '',
    account_delay_seconds: props.accountDelaySeconds,
});
const save = () => form.patch(route('backups.storage.update', { driver: props.driver }), { preserveScroll: true });
</script>

<template>
    <Head :title="t('backups.storage.configure.title', { name: names[driver] })" />
    <AuthenticatedLayout>
        <template #header><div><h1 class="text-lg font-semibold">{{ t('backups.storage.configure.title', { name: names[driver] }) }}</h1><p class="text-sm text-slate-500">{{ t('backups.storage.configure.subtitle') }}</p></div></template>
        <div class="mx-auto max-w-3xl space-y-4">
            <Link :href="route('backups.storage.index')" class="text-sm text-blue-600 hover:underline">{{ t('backups.storage.configure.back') }}</Link>
            <div v-if="page.props.flash?.success" class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700">{{ page.props.flash.success }}</div>
            <form class="space-y-5 rounded-xl border bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900" @submit.prevent="save">
                <label class="flex items-center justify-between rounded-lg border p-4"><span><span class="block text-sm font-semibold">{{ t('backups.storage.configure.use', { name: names[driver] }) }}</span><span class="text-xs text-slate-500">{{ t('backups.storage.configure.useHelp') }}</span></span><input v-model="form.activate" type="checkbox" class="rounded" /></label>

                <div v-if="driver === 'local'"><label class="mb-1 block text-sm font-medium">{{ t('backups.storage.configure.dir') }}</label><input v-model="form.path" class="w-full rounded-lg border px-3 py-2 dark:bg-slate-800" placeholder="/var/www/dpanel/storage/app/backups" /></div>
                <template v-if="driver === 'google_drive' || driver === 'custom'">
                    <div><label class="mb-1 block text-sm font-medium">{{ t('backups.storage.configure.remoteName') }}</label><input v-model="form.remote_name" class="w-full rounded-lg border px-3 py-2 dark:bg-slate-800" placeholder="dpanel-drive" /><p class="mt-1 text-xs text-slate-500">{{ t('backups.storage.configure.remoteNameHelp') }}</p></div>
                    <div><label class="mb-1 block text-sm font-medium">{{ t('backups.storage.configure.remoteFolder') }}</label><input v-model="form.path" class="w-full rounded-lg border px-3 py-2 dark:bg-slate-800" placeholder="dpanel-backups" /></div>
                </template>
                <template v-if="driver === 's3' || driver === 's3_compatible'">
                    <div v-if="driver === 's3_compatible'"><label class="mb-1 block text-sm font-medium">{{ t('backups.storage.configure.endpoint') }}</label><input v-model="form.endpoint" class="w-full rounded-lg border px-3 py-2 dark:bg-slate-800" placeholder="https://storage.example.com" /></div>
                    <div class="grid gap-4 sm:grid-cols-2"><div><label class="mb-1 block text-sm font-medium">{{ t('backups.storage.configure.bucket') }}</label><input v-model="form.bucket" class="w-full rounded-lg border px-3 py-2 dark:bg-slate-800" /></div><div><label class="mb-1 block text-sm font-medium">{{ t('backups.storage.configure.region') }}</label><input v-model="form.region" class="w-full rounded-lg border px-3 py-2 dark:bg-slate-800" placeholder="us-east-1" /></div></div>
                    <div><label class="mb-1 block text-sm font-medium">{{ t('backups.storage.configure.prefix') }}</label><input v-model="form.path" class="w-full rounded-lg border px-3 py-2 dark:bg-slate-800" placeholder="dpanel-backups" /></div>
                </template>
                <template v-if="driver === 'sftp'">
                    <div class="grid gap-4 sm:grid-cols-2"><div><label class="mb-1 block text-sm font-medium">{{ t('backups.storage.configure.host') }}</label><input v-model="form.host" class="w-full rounded-lg border px-3 py-2 dark:bg-slate-800" /></div><div><label class="mb-1 block text-sm font-medium">{{ t('backups.storage.configure.port') }}</label><input v-model.number="form.port" type="number" min="1" max="65535" class="w-full rounded-lg border px-3 py-2 dark:bg-slate-800" /></div></div>
                    <div><label class="mb-1 block text-sm font-medium">{{ t('backups.storage.configure.username') }}</label><input v-model="form.username" class="w-full rounded-lg border px-3 py-2 dark:bg-slate-800" /></div>
                    <div><label class="mb-1 block text-sm font-medium">{{ t('backups.storage.configure.keyPath') }}</label><input v-model="form.key_path" class="w-full rounded-lg border px-3 py-2 dark:bg-slate-800" placeholder="/root/.ssh/id_ed25519" /></div>
                    <div><label class="mb-1 block text-sm font-medium">{{ t('backups.storage.configure.remoteDir') }}</label><input v-model="form.path" class="w-full rounded-lg border px-3 py-2 dark:bg-slate-800" placeholder="/backups/dpanel" /></div>
                </template>

                <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950/20"><label class="mb-1 block text-sm font-semibold">{{ t('backups.storage.configure.delay') }}</label><div class="flex items-center gap-2"><input v-model.number="form.account_delay_seconds" type="number" min="0" max="3600" class="w-32 rounded-lg border px-3 py-2 dark:bg-slate-800" /><span class="text-sm">{{ t('backups.storage.configure.seconds') }}</span></div><p class="mt-2 text-xs text-slate-600 dark:text-slate-400">{{ t('backups.storage.configure.delayHelp') }}</p></div>

                <div class="flex justify-end"><button :disabled="form.processing" class="rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50">{{ form.processing ? t('backups.storage.configure.applying') : t('backups.storage.configure.apply') }}</button></div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
