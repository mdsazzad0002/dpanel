<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <Head title="phpMyAdmin" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">phpMyAdmin</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Open phpMyAdmin access and credentials panel.</p>
            </div>
        </template>

        <div class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
            <p class="text-sm">phpMyAdmin access link and login details will be shown here.</p>
        </div>
    </AuthenticatedLayout>
</template>
