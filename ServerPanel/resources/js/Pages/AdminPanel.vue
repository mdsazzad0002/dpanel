<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    stats: {
        type: Object,
        required: true,
    },
    recentUsers: {
        type: Array,
        default: () => [],
    },
});

const roleSummary = computed(() => {
    const roles = props.stats?.users_roles ?? [];
    if (!roles.length) {
        return 'No roles';
    }

    return roles
        .map((item) => `${item.name}: ${item.count}`)
        .join(', ');
});
</script>

<template>
    <Head title="Super Admin Panel" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Super Admin Panel</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Manage users, server services and platform operations.</p>
            </div>
        </template>

        <div class="space-y-6">
            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <article class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Total Users</p>
                    <p class="mt-2 text-2xl font-semibold">{{ stats.users_total }}</p>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        {{ roleSummary }}
                    </p>
                </article>

                <article class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Packages</p>
                    <p class="mt-2 text-2xl font-semibold">{{ stats.packages_total }}</p>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        Active: {{ stats.packages_active }}
                    </p>
                </article>

                <article class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Pending Website Requests</p>
                    <p class="mt-2 text-2xl font-semibold">{{ stats.website_requests_pending }}</p>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Generated from website create flow</p>
                </article>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-base font-semibold">Quick Actions</h2>
                <div class="mt-4 flex flex-wrap gap-2">
                    <Link :href="route('websites.list')" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">
                        Website Requests
                    </Link>
                    <Link :href="route('databases.list')" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">
                        Databases
                    </Link>
                    <Link :href="route('packages.list')" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">
                        Packages
                    </Link>
                    <Link :href="route('users.manage')" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">
                        Manage Users
                    </Link>
                    <Link :href="route('reseller.panel')" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">
                        Reseller Panel
                    </Link>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-base font-semibold">Recent Users</h2>
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-700">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                <th class="py-2 pr-4">Name</th>
                                <th class="py-2 pr-4">Email</th>
                                <th class="py-2 pr-4">Created</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr v-for="user in recentUsers" :key="user.id">
                                <td class="py-2 pr-4">{{ user.name }}</td>
                                <td class="py-2 pr-4">{{ user.email }}</td>
                                <td class="py-2 pr-4 text-slate-500 dark:text-slate-400">{{ user.created_at }}</td>
                            </tr>
                            <tr v-if="recentUsers.length === 0">
                                <td colspan="3" class="py-3 text-slate-500 dark:text-slate-400">No users found.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
