<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { computed, ref, watch } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';

const props = defineProps({
    websiteRecords: {
        type: Array,
        default: () => [],
    },
    websiteScopeLabel: {
        type: String,
        default: 'Websites',
    },
});

const page = usePage();
const dashboardStats = computed(() => page.props.dashboardStats ?? {});
const canManageWebsites = computed(() => {
    const roles = page.props.auth?.roles ?? [];
    return roles.includes('admin') || roles.includes('reseller');
});
const websiteSearch = ref('');
const websiteStatusFilter = ref('all');
const websiteCurrentPage = ref(1);
const websitePerPage = 8;
const filteredWebsiteRecords = computed(() => {
    const needle = websiteSearch.value.trim().toLowerCase();

    return props.websiteRecords.filter((item) => {
        const status = String(item.status ?? '').toLowerCase();
        if (websiteStatusFilter.value !== 'all' && status !== websiteStatusFilter.value) {
            return false;
        }

        if (!needle) {
            return true;
        }

        const haystack = [
            item.domain,
            item.root_path,
            item.php_version,
            item.status,
            item.created_by_label,
            item.assigned_reseller_name,
            item.assigned_user_name,
        ]
            .map((value) => String(value ?? '').toLowerCase())
            .join(' ');

        return haystack.includes(needle);
    });
});
const websiteTotalPages = computed(() => Math.max(1, Math.ceil(filteredWebsiteRecords.value.length / websitePerPage)));
const paginatedWebsiteRecords = computed(() => {
    const start = (websiteCurrentPage.value - 1) * websitePerPage;
    return filteredWebsiteRecords.value.slice(start, start + websitePerPage);
});
const websitePageStart = computed(() => {
    if (filteredWebsiteRecords.value.length === 0) return 0;
    return (websiteCurrentPage.value - 1) * websitePerPage + 1;
});
const websitePageEnd = computed(() => Math.min(websiteCurrentPage.value * websitePerPage, filteredWebsiteRecords.value.length));

watch([websiteSearch, websiteStatusFilter], () => {
    websiteCurrentPage.value = 1;
});

watch(websiteTotalPages, (value) => {
    if (websiteCurrentPage.value > value) {
        websiteCurrentPage.value = value;
    }
});

const formatDate = (value) => {
    if (!value) return '-';
    return new Date(value).toLocaleString();
};

const statusClass = (status) => {
    const value = String(status || '').toLowerCase();
    if (value === 'disabled') return 'bg-red-100 text-red-700 dark:bg-red-900/20 dark:text-red-300';
    if (value === 'live') return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-300';
    if (value === 'partial') return 'bg-amber-100 text-amber-700 dark:bg-amber-900/20 dark:text-amber-300';
    return 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200';
};
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Server Dashboard</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Basic structure for hosting and server management.</p>
            </div>
        </template>

        <div class="space-y-6">
            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">CPU Load</p>
                    <p class="mt-2 text-2xl font-semibold">{{ dashboardStats.cpu_load_percent ?? 0 }}%</p>
                    <p class="mt-1 text-xs text-emerald-600">{{ (dashboardStats.cpu_load_percent ?? 0) > 80 ? 'High' : 'Normal' }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Memory</p>
                    <p class="mt-2 text-2xl font-semibold">{{ dashboardStats.memory_used_mb ?? 0 }} MB</p>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">of {{ dashboardStats.memory_total_mb ?? 0 }} MB</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Websites</p>
                    <p class="mt-2 text-2xl font-semibold">{{ dashboardStats.websites_total ?? 0 }}</p>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ dashboardStats.websites_pending ?? 0 }} pending requests</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Mail Queue</p>
                    <p class="mt-2 text-2xl font-semibold">{{ dashboardStats.mail_queue ?? 0 }}</p>
                    <p class="mt-1 text-xs text-amber-600">{{ (dashboardStats.mail_queue ?? 0) > 0 ? 'Needs review' : 'Clean' }}</p>
                </div>
            </section>

            <section class="grid gap-6 xl:grid-cols-3">
                <div class="rounded-xl border border-slate-200 bg-white p-5 xl:col-span-2 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Services</h2>
                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                            <p class="text-sm font-medium">Apache</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">Status: {{ dashboardStats.services?.apache || 'unknown' }}</p>
                        </div>
                        <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                            <p class="text-sm font-medium">Mail Server</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">Postfix: {{ dashboardStats.services?.mail || 'unknown' }}, Dovecot: {{ dashboardStats.services?.dovecot || 'unknown' }}</p>
                        </div>
                        <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                            <p class="text-sm font-medium">MySQL/MariaDB</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">Driver/Service: {{ dashboardStats.services?.database || 'unknown' }}</p>
                        </div>
                        <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                            <p class="text-sm font-medium">Redis</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">Status: {{ dashboardStats.services?.redis || 'unknown' }}</p>
                        </div>
                    </div>
                </div>

                <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Quick Actions</h2>
                    <div class="mt-4 space-y-2">
                        <a :href="route('websites.create')" class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-left text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">Create Website</a>
                        <a :href="route('emails.create')" class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-left text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">Add Mailbox</a>
                        <a :href="route('terminal.index')" class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-left text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">Open Terminal</a>
                        <a :href="route('websites.list')" class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-left text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">Manage Websites</a>
                        <a href="/" class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-left text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">Installer Setup Guide</a>
                    </div>
                </div>
            </section>

            <section v-if="canManageWebsites" class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Website List</h2>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                            {{ websiteScopeLabel }} with direct access to manage and file manager.
                        </p>
                    </div>
                    <Link :href="route('websites.list')" class="inline-flex rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                        Open Full List
                    </Link>
                </div>

                <div class="mt-4 grid gap-3 md:grid-cols-[minmax(0,1fr)_220px]">
                    <input
                        v-model.trim="websiteSearch"
                        type="text"
                        placeholder="Search domain, path, owner..."
                        class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                    />
                    <select v-model="websiteStatusFilter" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="all">All Status</option>
                        <option value="live">Live</option>
                        <option value="partial">Partial</option>
                        <option value="disabled">Disabled</option>
                        <option value="pending">Pending</option>
                    </select>
                </div>

                <div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 dark:border-slate-800">
                    <table class="min-w-full text-left text-sm">
                        <thead class="bg-slate-50 dark:bg-slate-800">
                            <tr>
                                <th class="px-4 py-3">Domain</th>
                                <th class="px-4 py-3">Root Path</th>
                                <th class="px-4 py-3">PHP</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3">Created By</th>
                                <th class="px-4 py-3">Created</th>
                                <th class="px-4 py-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="website in paginatedWebsiteRecords" :key="website.id" class="border-t border-slate-200 dark:border-slate-800">
                                <td class="px-4 py-3 font-medium">
                                    <Link :href="route('websites.manage', website.id)" class="text-blue-700 underline hover:text-blue-800 dark:text-blue-400">
                                        {{ website.domain || '-' }}
                                    </Link>
                                </td>
                                <td class="px-4 py-3">
                                    <Link :href="route('websites.filemanager', website.id)" class="text-emerald-700 underline hover:text-emerald-800 dark:text-emerald-400">
                                        {{ website.root_path || '-' }}
                                    </Link>
                                </td>
                                <td class="px-4 py-3">{{ website.php_version || '-' }}</td>
                                <td class="px-4 py-3">
                                    <span class="rounded-full px-2 py-1 text-xs" :class="statusClass(website.status)">
                                        {{ website.status }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">{{ website.created_by_label || 'Admin' }}</td>
                                <td class="px-4 py-3 text-slate-500 dark:text-slate-400">{{ formatDate(website.created_at) }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <Link :href="route('websites.manage', website.id)" class="rounded-md border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                                            Manage
                                        </Link>
                                        <Link :href="route('websites.filemanager', website.id)" class="rounded-md border border-emerald-300 px-2 py-1 text-xs text-emerald-700 hover:bg-emerald-50 dark:border-emerald-700 dark:text-emerald-300 dark:hover:bg-emerald-900/20">
                                            File Manager
                                        </Link>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="paginatedWebsiteRecords.length === 0">
                                <td colspan="7" class="px-4 py-8 text-center text-slate-500 dark:text-slate-400">
                                    No websites available in your scope.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Showing {{ websitePageStart }}-{{ websitePageEnd }} of {{ filteredWebsiteRecords.length }}
                    </p>
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            class="rounded-md border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-100 disabled:opacity-50 dark:border-slate-700 dark:hover:bg-slate-800"
                            :disabled="websiteCurrentPage <= 1"
                            @click="websiteCurrentPage = Math.max(1, websiteCurrentPage - 1)"
                        >
                            Previous
                        </button>
                        <span class="text-xs text-slate-500 dark:text-slate-400">Page {{ websiteCurrentPage }} / {{ websiteTotalPages }}</span>
                        <button
                            type="button"
                            class="rounded-md border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-100 disabled:opacity-50 dark:border-slate-700 dark:hover:bg-slate-800"
                            :disabled="websiteCurrentPage >= websiteTotalPages"
                            @click="websiteCurrentPage = Math.min(websiteTotalPages, websiteCurrentPage + 1)"
                        >
                            Next
                        </button>
                    </div>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
