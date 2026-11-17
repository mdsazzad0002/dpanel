<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps({ activeDriver: { type: String, default: 'local' } });

const services = computed(() => [
    { id: 'local', name: t('backups.storage.index.local'), icon: 'bi-hdd', description: t('backups.storage.index.d.local') },
    { id: 'google_drive', name: 'Google Drive', icon: 'bi-google', description: t('backups.storage.index.d.google'), recommended: true },
    { id: 's3', name: 'Amazon S3', icon: 'bi-cloud', description: t('backups.storage.index.d.s3') },
    { id: 's3_compatible', name: t('backups.storage.index.s3c'), icon: 'bi-cloud-check', description: t('backups.storage.index.d.s3c') },
    { id: 'sftp', name: 'SFTP', icon: 'bi-server', description: t('backups.storage.index.d.sftp') },
    { id: 'custom', name: t('backups.storage.index.custom'), icon: 'bi-sliders', description: t('backups.storage.index.d.custom') },
]);
</script>

<template>
    <Head :title="t('backups.storage.index.title')" />
    <AuthenticatedLayout>
        <template #header><div><h1 class="text-lg font-semibold">{{ t('backups.storage.index.title') }}</h1><p class="text-sm text-slate-500">{{ t('backups.storage.index.subtitle') }}</p></div></template>
        <div class="space-y-4">
            <div class="flex items-center justify-between"><Link :href="route('backups.index')" class="text-sm text-blue-600 hover:underline">{{ t('backups.storage.index.back') }}</Link><span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold capitalize text-emerald-700">{{ t('backups.storage.index.activeDriver', { driver: activeDriver ? activeDriver.replace('_', ' ') : t('common.none') }) }}</span></div>
            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <Link v-for="service in services" :key="service.id" :href="route('backups.storage.configure', { driver: service.id })" class="group rounded-xl border bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:border-blue-400 hover:shadow-md dark:border-slate-800 dark:bg-slate-900">
                    <div class="flex items-start justify-between"><span class="grid h-12 w-12 place-items-center rounded-xl bg-blue-100 text-xl text-blue-600 dark:bg-blue-950"><i class="bi" :class="service.icon"></i></span><span v-if="activeDriver === service.id" class="rounded-full bg-emerald-100 px-2 py-1 text-[11px] font-semibold text-emerald-700">{{ t('backups.storage.index.active') }}</span><span v-else-if="service.recommended" class="rounded-full bg-blue-100 px-2 py-1 text-[11px] font-semibold text-blue-700">{{ t('backups.storage.index.recommended') }}</span></div>
                    <h2 class="mt-4 font-semibold">{{ service.name }}</h2><p class="mt-1 text-sm text-slate-500">{{ service.description }}</p><p class="mt-4 text-xs font-semibold text-blue-600">{{ t('backups.storage.index.configure') }}</p>
                </Link>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
