<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <Head title="DNS Records" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">DNS Records</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Manage A, CNAME, MX, TXT and other DNS records.</p>
            </div>
        </template>

        <div class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
            <p class="text-sm">DNS record management UI will be added here.</p>
        </div>
    </AuthenticatedLayout>
</template>
