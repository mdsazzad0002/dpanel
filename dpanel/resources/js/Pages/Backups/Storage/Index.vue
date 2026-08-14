<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({ activeDriver: { type: String, default: 'local' } });

const services = [
    { id: 'local', name: 'Local', icon: 'bi-hdd', description: 'Store backups on this server.' },
    { id: 'google_drive', name: 'Google Drive', icon: 'bi-google', description: 'Send backups to a Google Drive remote.', recommended: true },
    { id: 's3', name: 'Amazon S3', icon: 'bi-cloud', description: 'Use an AWS S3 bucket.' },
    { id: 's3_compatible', name: 'S3 Compatible', icon: 'bi-cloud-check', description: 'Use R2, MinIO, Wasabi or another S3 endpoint.' },
    { id: 'sftp', name: 'SFTP', icon: 'bi-server', description: 'Transfer backups securely to another server.' },
    { id: 'custom', name: 'Custom Storage', icon: 'bi-sliders', description: 'Use a preconfigured custom remote.' },
];
</script>

<template>
    <Head title="Backup Storage" />
    <AuthenticatedLayout>
        <template #header><div><h1 class="text-lg font-semibold">Backup Storage</h1><p class="text-sm text-slate-500">Choose a service, then configure it on a separate page.</p></div></template>
        <div class="space-y-4">
            <div class="flex items-center justify-between"><Link :href="route('backups.index')" class="text-sm text-blue-600 hover:underline">← Back to Backups</Link><span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold capitalize text-emerald-700">Active: {{ activeDriver.replace('_', ' ') }}</span></div>
            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <Link v-for="service in services" :key="service.id" :href="route('backups.storage.configure', { driver: service.id })" class="group rounded-xl border bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:border-blue-400 hover:shadow-md dark:border-slate-800 dark:bg-slate-900">
                    <div class="flex items-start justify-between"><span class="grid h-12 w-12 place-items-center rounded-xl bg-blue-100 text-xl text-blue-600 dark:bg-blue-950"><i class="bi" :class="service.icon"></i></span><span v-if="activeDriver === service.id" class="rounded-full bg-emerald-100 px-2 py-1 text-[11px] font-semibold text-emerald-700">Active</span><span v-else-if="service.recommended" class="rounded-full bg-blue-100 px-2 py-1 text-[11px] font-semibold text-blue-700">Recommended</span></div>
                    <h2 class="mt-4 font-semibold">{{ service.name }}</h2><p class="mt-1 text-sm text-slate-500">{{ service.description }}</p><p class="mt-4 text-xs font-semibold text-blue-600">Configure service →</p>
                </Link>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
