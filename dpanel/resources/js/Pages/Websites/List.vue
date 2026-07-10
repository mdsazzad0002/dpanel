<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const page = usePage();
const deleteForm = useForm({});
const statusForm = useForm({ status: '' });
const search = ref('');
const statusFilter = ref('all');
const installerFilter = ref('all');
const currentPage = ref(1);
const perPage = 30;

const props = defineProps({
    websiteRequests: {
        type: Array,
        default: () => [],
    },
});

const filteredWebsites = computed(() => {
    const needle = search.value.trim().toLowerCase();
    return props.websiteRequests.filter((item) => {
        const status = String(item.status ?? '').toLowerCase();
        const installer = String(item.app_installer ?? 'none').toLowerCase();

        if (statusFilter.value !== 'all' && status !== statusFilter.value) {
            return false;
        }
        if (installerFilter.value !== 'all' && installer !== installerFilter.value) {
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
            item.app_installer,
            item.created_by_label,
            item.assigned_reseller_name,
            item.assigned_user_name,
            item.enable_ssl ? 'yes' : 'no',
        ]
            .map((value) => String(value ?? '').toLowerCase())
            .join(' ');

        return haystack.includes(needle);
    });
});

const totalPages = computed(() => Math.max(1, Math.ceil(filteredWebsites.value.length / perPage)));
const paginatedWebsites = computed(() => {
    const start = (currentPage.value - 1) * perPage;
    return filteredWebsites.value.slice(start, start + perPage);
});
const pageStart = computed(() => {
    if (filteredWebsites.value.length === 0) return 0;
    return (currentPage.value - 1) * perPage + 1;
});
const pageEnd = computed(() => Math.min(currentPage.value * perPage, filteredWebsites.value.length));

watch([search, statusFilter, installerFilter], () => {
    currentPage.value = 1;
});

watch(totalPages, (value) => {
    if (currentPage.value > value) {
        currentPage.value = value;
    }
});

const formatDate = (value) => {
    if (!value) return '-';
    return new Date(value).toLocaleString();
};

const deleteRequest = (id) => {
    if (!confirm('Delete this website request?')) return;

    deleteForm.delete(route('websites.destroy', id));
};

const toggleStatus = (item) => {
    const current = String(item.status || '').toLowerCase();
    const next = current === 'disabled' ? 'enabled' : 'disabled';
    statusForm.status = next;
    statusForm.patch(route('websites.status.update', item.id), {
        preserveScroll: true,
    });
};

const statusClass = (status) => {
    const value = String(status || '').toLowerCase();
    if (value === 'disabled') return 'bg-red-100 text-red-700 dark:bg-red-900/20 dark:text-red-300';
    if (value === 'live') return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-300';
    if (value === 'partial') return 'bg-amber-100 text-amber-700 dark:bg-amber-900/20 dark:text-amber-300';
    return 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200';
};

const installerLabel = (installer) => {
    const value = String(installer || 'none').toLowerCase();
    if (value === 'wordpress') return 'WordPress';
    return 'Starter';
};

const installerClass = (installer) => {
    const value = String(installer || 'none').toLowerCase();
    if (value === 'wordpress') return 'bg-blue-100 text-blue-700 dark:bg-blue-900/20 dark:text-blue-300';
    return 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200';
};

const createdByLabel = (item) => String(
    item?.created_by_label || item?.assigned_reseller_name || item?.assigned_user_name || 'Admin',
);
</script>

<template>
    <Head title="List Websites" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">List Website Requests</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Website requests list with root path, PHP version and status.</p>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ page.props.flash.success }}
            </div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ page.props.flash.error }}
            </div>

            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div class="grid w-full gap-3 sm:max-w-3xl sm:grid-cols-3">
                    <input
                        v-model.trim="search"
                        type="text"
                        placeholder="Search website..."
                        class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                    />
                    <select v-model="statusFilter" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="all">All Status</option>
                        <option value="live">Live</option>
                        <option value="partial">Partial</option>
                        <option value="disabled">Disabled</option>
                        <option value="pending">Pending</option>
                    </select>
                    <select v-model="installerFilter" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="all">All Installers</option>
                        <option value="none">Starter</option>
                        <option value="wordpress">WordPress</option>
                    </select>
                </div>
                <Link :href="route('websites.create')" class="inline-flex rounded-md bg-blue-600 px-3 py-2 text-sm text-white hover:bg-blue-700">
                    Create Website
                </Link>
            </div>

            <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-800">
                        <tr>
                            <th class="px-4 py-3">Directory + Website</th>
                            <th class="px-4 py-3">Configuration</th>
                            <th class="px-4 py-3">Authors + Status</th>
                            <th class="px-4 py-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in paginatedWebsites" :key="item.id" class="border-t border-slate-200 dark:border-slate-800">
                            <td class="px-4 py-3 align-top">
                                <div class="space-y-1">
                                    <Link :href="route('websites.manage', item.id)" class="block font-medium text-blue-700 underline hover:text-blue-800 dark:text-blue-400">
                                        {{ item.domain }}
                                    </Link>
                                    <Link :href="route('websites.filemanager', item.id)" class="block break-all text-xs text-emerald-700 underline hover:text-emerald-800 dark:text-emerald-400">
                                        {{ item.root_path }}
                                    </Link>
                                </div>
                            </td>
                            <td class="px-4 py-3 align-top">
                                <div class=" text-xs">
                                    <div class="flex items-center  gap-3">
                                        <span class="text-slate-500 dark:text-slate-400">PHP</span>
                                        <span class="font-medium">{{ item.php_version }}</span>
                                    </div>
                                    <div class="flex items-center  gap-3">
                                        <span class="text-slate-500 dark:text-slate-400">Installer</span>
                                        <span class="rounded-full px-2 py-0.5 text-[11px]" :class="installerClass(item.app_installer)">
                                            {{ installerLabel(item.app_installer) }}
                                        </span>
                                    </div>
                                    <div class="flex items-center  gap-3">
                                        <span class="text-slate-500 dark:text-slate-400">SSL</span>
                                        <span class="font-medium">{{ item.enable_ssl ? 'Yes' : 'No' }}</span>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3 align-top">
                                <div class="flex items-center  gap-3">
                                    <span class="text-slate-500 dark:text-slate-400">Status</span>
                                    <span class="rounded-full px-2 py-0.5 text-[11px]" :class="statusClass(item.status)">
                                        {{ item.status }}
                                    </span>
                                </div>
                                <div class="flex items-center  gap-3">
                                    <span class="text-slate-500 dark:text-slate-400">By</span>
                                    <span class="font-medium">{{ createdByLabel(item) }}</span>
                                </div>
                                <div class="flex items-center  gap-3">
                                    <span class="text-slate-500 dark:text-slate-400" title="Created At">C</span>
                                    <span class="text-right">{{ formatDate(item.created_at) }}</span>
                                </div>
                            </td>
                            <td class="px-4 py-3 align-top">
                                <div class="flex flex-wrap gap-2">
                                    <Link :href="route('websites.manage', item.id)" class="rounded-md border border-blue-300 px-2 py-1 text-xs text-blue-700 hover:bg-blue-50 dark:border-blue-700 dark:text-blue-300 dark:hover:bg-blue-900/20">
                                        Manage
                                    </Link>
                                    <Link :href="route('websites.filemanager', item.id)" class="rounded-md border border-emerald-300 px-2 py-1 text-xs text-emerald-700 hover:bg-emerald-50 dark:border-emerald-700 dark:text-emerald-300 dark:hover:bg-emerald-900/20">
                                        Files
                                    </Link>
                                    <Link :href="route('websites.edit', item.id)" class="rounded-md border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                                        Edit
                                    </Link>
                                    <button
                                        type="button"
                                        :disabled="statusForm.processing"
                                        class="rounded-md border px-2 py-1 text-xs disabled:opacity-50"
                                        :class="String(item.status || '').toLowerCase() === 'disabled'
                                            ? 'border-emerald-300 text-emerald-700 hover:bg-emerald-50 dark:border-emerald-700 dark:text-emerald-400'
                                            : 'border-amber-300 text-amber-700 hover:bg-amber-50 dark:border-amber-700 dark:text-amber-300'"
                                        @click="toggleStatus(item)"
                                    >
                                        {{ String(item.status || '').toLowerCase() === 'disabled' ? 'Enable' : 'Disable' }}
                                    </button>
                                    <button
                                        :disabled="deleteForm.processing"
                                        class="rounded-md border border-red-300 px-2 py-1 text-xs text-red-700 hover:bg-red-50 disabled:opacity-50 dark:border-red-700 dark:text-red-400"
                                        @click="deleteRequest(item.id)"
                                    >
                                        Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="paginatedWebsites.length === 0">
                            <td colspan="3" class="px-4 py-6 text-center text-slate-500">No website requests generated yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-xs text-slate-500 dark:text-slate-400">
                    Showing {{ pageStart }}-{{ pageEnd }} of {{ filteredWebsites.length }} (max {{ perPage }} per page)
                </p>
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="rounded-md border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-100 disabled:opacity-50 dark:border-slate-700 dark:hover:bg-slate-800"
                        :disabled="currentPage <= 1"
                        @click="currentPage = Math.max(1, currentPage - 1)"
                    >
                        Previous
                    </button>
                    <span class="text-xs text-slate-500 dark:text-slate-400">Page {{ currentPage }} / {{ totalPages }}</span>
                    <button
                        type="button"
                        class="rounded-md border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-100 disabled:opacity-50 dark:border-slate-700 dark:hover:bg-slate-800"
                        :disabled="currentPage >= totalPages"
                        @click="currentPage = Math.min(totalPages, currentPage + 1)"
                    >
                        Next
                    </button>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
