<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <Head title="DNS Zones" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">DNS Zones</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Create and manage DNS zone entries.</p>
            </div>
        </template>

        <div class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
            <p class="text-sm">DNS zone management UI will be added here.</p>
        </div>
    </AuthenticatedLayout>
</template>
