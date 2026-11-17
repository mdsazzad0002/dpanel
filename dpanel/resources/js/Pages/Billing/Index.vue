<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps({ whmcsReady: Boolean });

const page = usePage();
const panelRoute = (name, params = {}) => route(name, { token: page.props.panel?.token, ...params });
</script>

<template>
    <Head :title="t('billing.index.title')" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">{{ t('billing.index.title') }}</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ t('billing.index.subtitle') }}</p>
            </div>
        </template>

        <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
            <article class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-blue-100 text-xl text-blue-700 dark:bg-blue-950 dark:text-blue-300">
                        <i class="bi bi-arrow-left-right"></i>
                    </div>
                    <span :class="whmcsReady ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300'" class="rounded-full px-2.5 py-1 text-xs font-medium">
                        {{ whmcsReady ? t('billing.index.configured') : t('billing.index.setupRequired') }}
                    </span>
                </div>
                <h2 class="mt-5 text-lg font-semibold">{{ t('billing.whmcs.title') }}</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-400">
                    {{ t('billing.index.whmcsHelp') }}
                </p>
                <Link :href="panelRoute('billing.whmcs')" class="mt-5 inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                    {{ t('billing.index.openGuide') }} <i class="bi bi-arrow-right"></i>
                </Link>
            </article>
        </div>
    </AuthenticatedLayout>
</template>
