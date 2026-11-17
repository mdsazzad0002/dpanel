<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
</script>

<template>
    <Head :title="t('php.settings.title')" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">{{ t('php.settings.title') }}</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ t('php.settings.subtitle') }}</p>
            </div>
        </template>

        <div class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
            <p class="text-sm">{{ t('php.settings.placeholder') }}</p>
        </div>
    </AuthenticatedLayout>
</template>
