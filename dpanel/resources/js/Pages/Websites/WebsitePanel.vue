<script setup>
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';

const { t } = useI18n();
</script>

<template>
    <Head :title="t('websites.panel.title')" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">{{ t('websites.panel.title') }}</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ t('websites.panel.subtitle') }}</p>
            </div>
        </template>

        <div class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
            <p class="text-sm">
                <i18n-t keypath="websites.panel.roles"><template #admin><strong>admin</strong></template><template #reseller><strong>reseller</strong></template><template #users><strong>individual users</strong></template></i18n-t>
            </p>
        </div>
    </AuthenticatedLayout>
</template>
