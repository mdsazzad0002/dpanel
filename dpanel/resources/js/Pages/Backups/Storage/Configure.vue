<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';

const props = defineProps({
    driver: { type: String, required: true },
    active: { type: Boolean, default: false },
    config: { type: Object, default: () => ({}) },
    accountDelaySeconds: { type: Number, default: 5 },
});
const page = usePage();
const names = { local: 'Local', google_drive: 'Google Drive', s3: 'Amazon S3', s3_compatible: 'S3 Compatible', sftp: 'SFTP', custom: 'Custom Storage' };
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
    <Head :title="`Configure ${names[driver]}`" />
    <AuthenticatedLayout>
        <template #header><div><h1 class="text-lg font-semibold">Configure {{ names[driver] }}</h1><p class="text-sm text-slate-500">Save this destination and optionally make it active.</p></div></template>
        <div class="mx-auto max-w-3xl space-y-4">
            <Link :href="route('backups.storage.index')" class="text-sm text-blue-600 hover:underline">← All Storage Services</Link>
            <div v-if="page.props.flash?.success" class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700">{{ page.props.flash.success }}</div>
            <form class="space-y-5 rounded-xl border bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900" @submit.prevent="save">
                <label class="flex items-center justify-between rounded-lg border p-4"><span><span class="block text-sm font-semibold">Use {{ names[driver] }}</span><span class="text-xs text-slate-500">Set as the destination for new backups.</span></span><input v-model="form.activate" type="checkbox" class="rounded" /></label>

                <div v-if="driver === 'local'"><label class="mb-1 block text-sm font-medium">Backup Directory</label><input v-model="form.path" class="w-full rounded-lg border px-3 py-2 dark:bg-slate-800" placeholder="/var/www/dpanel/storage/app/backups" /></div>
                <template v-if="driver === 'google_drive' || driver === 'custom'">
                    <div><label class="mb-1 block text-sm font-medium">Configured Remote Name</label><input v-model="form.remote_name" class="w-full rounded-lg border px-3 py-2 dark:bg-slate-800" placeholder="dpanel-drive" /><p class="mt-1 text-xs text-slate-500">Name of the remote configured on the server.</p></div>
                    <div><label class="mb-1 block text-sm font-medium">Remote Folder</label><input v-model="form.path" class="w-full rounded-lg border px-3 py-2 dark:bg-slate-800" placeholder="dpanel-backups" /></div>
                </template>
                <template v-if="driver === 's3' || driver === 's3_compatible'">
                    <div v-if="driver === 's3_compatible'"><label class="mb-1 block text-sm font-medium">Endpoint URL</label><input v-model="form.endpoint" class="w-full rounded-lg border px-3 py-2 dark:bg-slate-800" placeholder="https://storage.example.com" /></div>
                    <div class="grid gap-4 sm:grid-cols-2"><div><label class="mb-1 block text-sm font-medium">Bucket</label><input v-model="form.bucket" class="w-full rounded-lg border px-3 py-2 dark:bg-slate-800" /></div><div><label class="mb-1 block text-sm font-medium">Region</label><input v-model="form.region" class="w-full rounded-lg border px-3 py-2 dark:bg-slate-800" placeholder="us-east-1" /></div></div>
                    <div><label class="mb-1 block text-sm font-medium">Folder / Prefix</label><input v-model="form.path" class="w-full rounded-lg border px-3 py-2 dark:bg-slate-800" placeholder="dpanel-backups" /></div>
                </template>
                <template v-if="driver === 'sftp'">
                    <div class="grid gap-4 sm:grid-cols-2"><div><label class="mb-1 block text-sm font-medium">Host</label><input v-model="form.host" class="w-full rounded-lg border px-3 py-2 dark:bg-slate-800" /></div><div><label class="mb-1 block text-sm font-medium">Port</label><input v-model.number="form.port" type="number" min="1" max="65535" class="w-full rounded-lg border px-3 py-2 dark:bg-slate-800" /></div></div>
                    <div><label class="mb-1 block text-sm font-medium">Username</label><input v-model="form.username" class="w-full rounded-lg border px-3 py-2 dark:bg-slate-800" /></div>
                    <div><label class="mb-1 block text-sm font-medium">SSH Private Key Path</label><input v-model="form.key_path" class="w-full rounded-lg border px-3 py-2 dark:bg-slate-800" placeholder="/root/.ssh/id_ed25519" /></div>
                    <div><label class="mb-1 block text-sm font-medium">Remote Directory</label><input v-model="form.path" class="w-full rounded-lg border px-3 py-2 dark:bg-slate-800" placeholder="/backups/dpanel" /></div>
                </template>

                <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950/20"><label class="mb-1 block text-sm font-semibold">Delay Between Accounts</label><div class="flex items-center gap-2"><input v-model.number="form.account_delay_seconds" type="number" min="0" max="3600" class="w-32 rounded-lg border px-3 py-2 dark:bg-slate-800" /><span class="text-sm">seconds</span></div><p class="mt-2 text-xs text-slate-600 dark:text-slate-400">Accounts always run one at a time. This pause can further reduce disk and CPU load.</p></div>

                <div class="flex justify-end"><button :disabled="form.processing" class="rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50">{{ form.processing ? 'Applying...' : 'Apply Configuration' }}</button></div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
