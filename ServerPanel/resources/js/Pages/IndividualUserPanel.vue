<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    panelUser: {
        type: Object,
        default: () => ({}),
    },
    activeSubscription: {
        type: Object,
        default: null,
    },
    subscriptionQuotas: {
        type: Object,
        default: () => ({}),
    },
    subscriptionHistory: {
        type: Array,
        default: () => [],
    },
    requestStats: {
        type: Object,
        default: () => ({}),
    },
});

const quotaMeta = [
    { key: 'mail_accounts', label: 'Mail Accounts', unit: '' },
    { key: 'disk_space_mb', label: 'Disk Space', unit: 'MB' },
    { key: 'databases', label: 'Databases', unit: '' },
    { key: 'files', label: 'Files', unit: '' },
];

const roleText = computed(() => (props.panelUser?.roles ?? []).join(', ') || 'No role');
const verifiedText = computed(() => (props.panelUser?.email_verified_at ? 'Verified' : 'Not verified'));

const usagePercent = (quota) => {
    if (!quota || quota.limit === null || quota.limit === 0) return 0;
    return Math.min(100, Math.round((quota.used / quota.limit) * 100));
};

const quotaDisplay = (quota, unit = '') => {
    if (!quota) return 'Not configured';

    const used = `${quota.used}${unit ? ` ${unit}` : ''}`;
    if (quota.limit === null) return `${used} / Unlimited`;

    const limit = `${quota.limit}${unit ? ` ${unit}` : ''}`;
    return `${used} / ${limit}`;
};

const formatDate = (value) => {
    if (!value) return '-';

    return new Date(value).toLocaleDateString();
};
</script>

<template>
    <Head title="Individual User Panel" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Individual User Panel</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Complete panel for account, subscription and quota management.</p>
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
                            v-if="($page.props.auth.roles || []).includes('super_admin') || ($page.props.auth.roles || []).includes('reseller')"
                            :href="route('websites.list')"
                            class="block rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800"
                        >
                            Website Requests
                        </Link>
                        <Link
                            v-if="($page.props.auth.roles || []).includes('super_admin') || ($page.props.auth.roles || []).includes('reseller')"
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

            <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Current Subscription</h2>

                <div v-if="activeSubscription" class="mt-3 space-y-4">
                    <div class="grid gap-3 md:grid-cols-3">
                        <div>
                            <p class="text-xs text-slate-500 dark:text-slate-400">Plan</p>
                            <p class="text-sm font-medium">{{ activeSubscription.package?.name || activeSubscription.plan_name }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-slate-500 dark:text-slate-400">Price</p>
                            <p class="text-sm font-medium">${{ activeSubscription.price }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-slate-500 dark:text-slate-400">Ends At</p>
                            <p class="text-sm font-medium">{{ formatDate(activeSubscription.ends_at) }}</p>
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div v-for="meta in quotaMeta" :key="meta.key" class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                            <div class="mb-2 flex items-center justify-between">
                                <p class="text-sm font-medium">{{ meta.label }}</p>
                                <p class="text-xs text-slate-500 dark:text-slate-400">{{ quotaDisplay(subscriptionQuotas[meta.key], meta.unit) }}</p>
                            </div>
                            <div class="h-2 rounded bg-slate-200 dark:bg-slate-700">
                                <div
                                    class="h-2 rounded bg-blue-600"
                                    :style="{ width: `${usagePercent(subscriptionQuotas[meta.key])}%` }"
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <p v-else class="mt-3 text-sm text-amber-600">No active subscription assigned to this user.</p>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Subscription History</h2>

                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 dark:border-slate-700">
                                <th class="px-3 py-2">Plan</th>
                                <th class="px-3 py-2">Status</th>
                                <th class="px-3 py-2">Price</th>
                                <th class="px-3 py-2">Start</th>
                                <th class="px-3 py-2">End</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="sub in subscriptionHistory" :key="sub.id" class="border-b border-slate-100 dark:border-slate-800">
                                <td class="px-3 py-2">{{ sub.package?.name || sub.plan_name }}</td>
                                <td class="px-3 py-2">{{ sub.status }}</td>
                                <td class="px-3 py-2">${{ sub.price }}</td>
                                <td class="px-3 py-2">{{ formatDate(sub.started_at) }}</td>
                                <td class="px-3 py-2">{{ formatDate(sub.ends_at) }}</td>
                            </tr>
                            <tr v-if="subscriptionHistory.length === 0">
                                <td colspan="5" class="px-3 py-4 text-center text-slate-500">No subscription history found.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
