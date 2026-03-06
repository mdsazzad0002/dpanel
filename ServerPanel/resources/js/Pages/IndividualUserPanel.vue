<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    panelUser: {
        type: Object,
        default: () => ({}),
    },
    requestStats: {
        type: Object,
        default: () => ({}),
    },
});

const roleText = computed(() => (props.panelUser?.roles ?? []).join(', ') || 'No role');
const verifiedText = computed(() => (props.panelUser?.email_verified_at ? 'Verified' : 'Not verified'));

</script>

<template>
    <Head title="Individual User Panel" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Individual User Panel</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Complete panel for account and request management.</p>
            </div>
        </template>

        <div class="space-y-6">
            <section class="grid gap-4 md:grid-cols-3">
                <div class="rounded-xl border border-slate-200 bg-white p-5 md:col-span-2 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Account Overview</h2>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div>
                            <p class="text-xs text-slate-500 dark:text-slate-400">Name</p>
                            <p class="text-sm font-medium">{{ panelUser.name }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-slate-500 dark:text-slate-400">Email</p>
                            <p class="text-sm font-medium">{{ panelUser.email }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-slate-500 dark:text-slate-400">Role</p>
                            <p class="text-sm font-medium">{{ roleText }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-slate-500 dark:text-slate-400">Email Status</p>
                            <p class="text-sm font-medium">{{ verifiedText }}</p>
                        </div>
                    </div>
                </div>

                <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Quick Actions</h2>
                    <div class="mt-3 space-y-2">
                        <Link :href="route('profile.edit')" class="block rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                            Edit Profile
                        </Link>
                        <Link :href="route('dashboard')" class="block rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                            Open Dashboard
                        </Link>
                        <Link
                            v-if="($page.props.auth.roles || []).includes('admin') || ($page.props.auth.roles || []).includes('reseller')"
                            :href="route('websites.list')"
                            class="block rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800"
                        >
                            Website Requests
                        </Link>
                        <Link
                            v-if="($page.props.auth.roles || []).includes('admin') || ($page.props.auth.roles || []).includes('reseller')"
                            :href="route('databases.list')"
                            class="block rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800"
                        >
                            Database Requests
                        </Link>
                    </div>
                </div>
            </section>

            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <article class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Website Requests</p>
                    <p class="mt-2 text-xl font-semibold">{{ requestStats.website_requests_total ?? 0 }}</p>
                </article>
                <article class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Pending Websites</p>
                    <p class="mt-2 text-xl font-semibold">{{ requestStats.website_requests_pending ?? 0 }}</p>
                </article>
                <article class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Database Requests</p>
                    <p class="mt-2 text-xl font-semibold">{{ requestStats.database_requests_total ?? 0 }}</p>
                </article>
                <article class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Pending Databases</p>
                    <p class="mt-2 text-xl font-semibold">{{ requestStats.database_requests_pending ?? 0 }}</p>
                </article>
            </section>

        </div>
    </AuthenticatedLayout>
</template>
