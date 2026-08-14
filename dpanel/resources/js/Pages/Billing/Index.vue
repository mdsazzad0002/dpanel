<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';

defineProps({ whmcsReady: Boolean });

const page = usePage();
const panelRoute = (name, params = {}) => route(name, { token: page.props.panel?.token, ...params });
</script>

<template>
    <Head title="Billing System" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Billing System</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Connect an external billing platform to automate customer accounts.</p>
            </div>
        </template>

        <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
            <article class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-blue-100 text-xl text-blue-700 dark:bg-blue-950 dark:text-blue-300">
                        <i class="bi bi-arrow-left-right"></i>
                    </div>
                    <span :class="whmcsReady ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300'" class="rounded-full px-2.5 py-1 text-xs font-medium">
                        {{ whmcsReady ? 'Configured' : 'Setup required' }}
                    </span>
                </div>
                <h2 class="mt-5 text-lg font-semibold">Billing with WHMCS</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-400">
                    Securely provision plans, change packages, suspend accounts and create one-time panel login links from WHMCS.
                </p>
                <Link :href="panelRoute('billing.whmcs')" class="mt-5 inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                    Open setup guide <i class="bi bi-arrow-right"></i>
                </Link>
            </article>
        </div>
    </AuthenticatedLayout>
</template>
