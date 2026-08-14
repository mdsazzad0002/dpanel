<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Website Panel" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Website Panel</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Manage websites, domains, SSL and hosting settings.</p>
            </div>
        </template>

        <div class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
            <p class="text-sm">
                This area is available for <strong>admin</strong>, <strong>reseller</strong>, and <strong>individual users</strong>.
            </p>
        </div>
    </AuthenticatedLayout>
</template>
