<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
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
    sshFailurePanel: {
        type: Object,
        default: () => ({
            has_failures: false,
            recent_failures: [],
            suggestions: [],
        }),
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

const page = usePage();
const panelToken = computed(() => page.props.panel?.token ?? '');
const panelRoute = (name, params = {}) => (
    panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params)
);
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
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Pending Website Requests</p>
                    <p class="mt-2 text-2xl font-semibold">{{ stats.website_requests_pending }}</p>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Generated from website create flow</p>
                </article>

                <article class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">SSH Failures (24h)</p>
                    <p class="mt-2 text-2xl font-semibold">{{ stats.ssh_failures_24h ?? 0 }}</p>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Failed connection tests from Servers panel</p>
                </article>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-base font-semibold">SSH Failure Troubleshooting</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    If server connection tests fail, use this setup checklist.
                </p>

                <ul class="mt-4 space-y-2 text-sm">
                    <li
                        v-for="(suggestion, index) in sshFailurePanel.suggestions ?? []"
                        :key="`ssh-suggestion-${index}`"
                        class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 dark:border-slate-700 dark:bg-slate-800/60"
                    >
                        {{ suggestion }}
                    </li>
                </ul>

                <div class="mt-5">
                    <h3 class="text-sm font-semibold">Recent SSH Failures</h3>
                    <div v-if="!(sshFailurePanel.recent_failures ?? []).length" class="mt-2 text-sm text-emerald-600 dark:text-emerald-400">
                        No recent SSH failures detected.
                    </div>
                    <div v-else class="mt-3 space-y-2">
                        <article
                            v-for="failure in sshFailurePanel.recent_failures"
                            :key="failure.id"
                            class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm dark:border-red-900/60 dark:bg-red-950/20"
                        >
                            <p class="font-medium text-red-700 dark:text-red-300">
                                {{ failure.server?.name }} ({{ failure.server?.host }}:{{ failure.server?.port }})
                            </p>
                            <p class="mt-1 text-xs text-red-600 dark:text-red-400">
                                User: {{ failure.server?.username }} | Tested: {{ failure.tested_at }}
                            </p>
                            <p class="mt-2 break-words text-red-800 dark:text-red-200">
                                {{ failure.error_output }}
                            </p>
                        </article>
                    </div>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-base font-semibold">Quick Actions</h2>
                <div class="mt-4 flex flex-wrap gap-2">
                    <Link :href="panelRoute('websites.list')" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">
                        Website Requests
                    </Link>
                    <Link :href="panelRoute('databases.list')" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">
                        Databases
                    </Link>
                    <Link :href="panelRoute('users.manage')" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">
                        Manage Users
                    </Link>
                    <Link :href="panelRoute('reseller.panel')" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">
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
