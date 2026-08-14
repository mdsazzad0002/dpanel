<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps({
    stats: {
        type: Object,
        required: true,
    },
    recentWebsiteRequests: {
        type: Array,
        default: () => [],
    },
    recentDatabaseRequests: {
        type: Array,
        default: () => [],
    },
});

const formatDate = (value) => {
    if (!value) return '-';
    return new Date(value).toLocaleString();
};

const page = usePage();
const panelToken = computed(() => page.props.panel?.token ?? '');
const panelRoute = (name, params = {}) => (
    panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params)
);
</script>

<template>
    <Head title="Reseller Panel" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Reseller Panel</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Manage customer websites, databases and requests.</p>
            </div>
        </template>

        <div class="space-y-6">
            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <article class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Website Requests</p>
                    <p class="mt-2 text-2xl font-semibold">{{ stats.website_requests_total }}</p>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Pending: {{ stats.website_requests_pending }}</p>
                </article>

                <article class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Database Requests</p>
                    <p class="mt-2 text-2xl font-semibold">{{ stats.database_requests_total }}</p>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Pending: {{ stats.database_requests_pending }}</p>
                </article>

            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-base font-semibold">Quick Actions</h2>
                <div class="mt-4 flex flex-wrap gap-2">
                    <Link :href="panelRoute('websites.create')" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">
                        Create Website
                    </Link>
                    <Link :href="panelRoute('websites.list')" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">
                        Website List
                    </Link>
                    <Link :href="panelRoute('databases.create')" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">
                        Create Database
                    </Link>
                    <Link :href="panelRoute('databases.list')" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">
                        Database List
                    </Link>
                    <Link :href="panelRoute('user.panel')" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">
                        User Panel
                    </Link>
                </div>
            </section>

            <section class="grid gap-6 xl:grid-cols-2">
                <div class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="text-base font-semibold">Recent Website Requests</h2>
                    <div class="mt-4 overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-700">
                            <thead>
                                <tr class="text-left text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                    <th class="py-2 pr-3">Domain</th>
                                    <th class="py-2 pr-3">PHP</th>
                                    <th class="py-2 pr-3">Status</th>
                                    <th class="py-2 pr-3">Created</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                <tr v-for="row in recentWebsiteRequests" :key="row.id">
                                    <td class="py-2 pr-3">{{ row.domain }}</td>
                                    <td class="py-2 pr-3">{{ row.php_version }}</td>
                                    <td class="py-2 pr-3">{{ row.status }}</td>
                                    <td class="py-2 pr-3 text-slate-500 dark:text-slate-400">{{ formatDate(row.created_at) }}</td>
                                </tr>
                                <tr v-if="recentWebsiteRequests.length === 0">
                                    <td colspan="4" class="py-3 text-slate-500 dark:text-slate-400">No website requests found.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="text-base font-semibold">Recent Database Requests</h2>
                    <div class="mt-4 overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-700">
                            <thead>
                                <tr class="text-left text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                    <th class="py-2 pr-3">Domain</th>
                                    <th class="py-2 pr-3">Database</th>
                                    <th class="py-2 pr-3">Status</th>
                                    <th class="py-2 pr-3">Created</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                <tr v-for="row in recentDatabaseRequests" :key="row.id">
                                    <td class="py-2 pr-3">{{ row.domain }}</td>
                                    <td class="py-2 pr-3">{{ row.database_name }}</td>
                                    <td class="py-2 pr-3">{{ row.status }}</td>
                                    <td class="py-2 pr-3 text-slate-500 dark:text-slate-400">{{ formatDate(row.created_at) }}</td>
                                </tr>
                                <tr v-if="recentDatabaseRequests.length === 0">
                                    <td colspan="4" class="py-3 text-slate-500 dark:text-slate-400">No database requests found.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
