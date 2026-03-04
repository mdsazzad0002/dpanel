<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Super Admin Panel" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Super Admin Panel</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Manage users, server services and global subscriptions.</p>
            </div>
        </template>

        <div class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
            <p class="text-sm">
                This area is restricted to <strong>super_admin</strong>.
            </p>
        </div>
    </AuthenticatedLayout>
</template>
