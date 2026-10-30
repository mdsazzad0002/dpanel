<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onUnmounted, ref } from 'vue';

const page = usePage();
const deleteForm = useForm({});
const panelToken = computed(() => String(page.props.panel?.token || ''));
const currentUserId = computed(() => Number(page.props.auth?.user?.id || 0));
const canOpenAllDatabases = computed(() => currentUserId.value === 1);
const panelRoute = (name, params = {}) => (
    panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params)
);

const props = defineProps({
    databaseRequests: {
        type: Array,
        default: () => [],
    },
    websiteOptions: {
        type: Array,
        default: () => [],
    },
    userOptions: {
        type: Array,
        default: () => [],
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
});

const searchedName = ref(String(props.filters?.name || ''));
const selectedWebsite = ref(String(props.filters?.website || ''));
const selectedUser = ref(String(props.filters?.user || ''));

const websiteFilterOptions = computed(() => [
    { value: '', label: 'All websites' },
    ...props.websiteOptions.map((website) => ({ value: website, label: website })),
]);
const userFilterOptions = computed(() => [
    { value: '', label: 'All users' },
    ...props.userOptions.map((user) => ({ value: user.value, label: user.label })),
]);

const formatDate = (value) => {
    if (!value) return '-';
    return new Date(value).toLocaleString();
};

const applyFilters = () => {
    const query = {};

    if (searchedName.value.trim() !== '') {
        query.name = searchedName.value.trim();
    }

    if (selectedWebsite.value.trim() !== '') {
        query.website = selectedWebsite.value.trim();
    }

    if (selectedUser.value.trim() !== '') {
        query.user = selectedUser.value.trim();
    }

    router.get(panelRoute('databases.list'), query, {
        preserveScroll: true,
        preserveState: false,
        replace: true,
    });
};

const clearFilters = () => {
    searchedName.value = '';
    selectedWebsite.value = '';
    selectedUser.value = '';

    router.get(panelRoute('databases.list'), {}, {
        preserveScroll: true,
        preserveState: false,
        replace: true,
    });
};



const deleteRequest = (id) => {
    if (!confirm('Delete this database request?')) return;

    deleteForm.delete(panelRoute('databases.destroy', { id }));
};

const isPostgresql = (item) => item.engine === 'postgresql';
const isMongodb = (item) => item.engine === 'mongodb';

const engines = {
    mariadb: { label: 'MariaDB', tool: 'phpMyAdmin', badge: 'bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300' },
    postgresql: { label: 'PostgreSQL', tool: 'pgAdmin', badge: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300' },
    mongodb: { label: 'MongoDB', tool: 'Mongo Admin', badge: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' },
};
const engineOf = (item) => engines[item.engine] || engines.mariadb;

// phpMyAdmin for MariaDB, pgAdmin for PostgreSQL, Mongo Admin for MongoDB;
// each signs in automatically as that database's own user.
const databaseLoginUrl = (item) => {
    if (isPostgresql(item)) return panelRoute('databases.pgadmin.autologin', { id: item.id });
    if (isMongodb(item)) return panelRoute('databases.mongodb.admin', { scope: item.id });
    return panelRoute('databases.phpmyadmin.autologin', { id: item.id });
};

const openRootPhpMyAdmin = () => {
    window.location.href = panelRoute('phpmyadmin.root-autologin');
};

const openRootPgAdmin = () => {
    window.location.href = panelRoute('databases.postgresql.pgadmin');
};

const openRootMongoAdmin = () => {
    window.open(panelRoute('databases.mongodb.admin'), '_blank', 'noopener');
};

// Export: one queued dump per database. Bulk export starts one per selected
// row, and each file downloads the moment its own dump is ready, without
// waiting for the others to be zipped together.
const selectedIds = ref([]);
const exportableRows = computed(() => props.databaseRequests.filter((item) => item.status === 'active'));
const allSelected = computed(() => exportableRows.value.length > 0 && exportableRows.value.every((item) => selectedIds.value.includes(item.id)));
const toggleSelect = (id) => {
    selectedIds.value = selectedIds.value.includes(id)
        ? selectedIds.value.filter((value) => value !== id)
        : [...selectedIds.value, id];
};
const toggleAll = () => {
    selectedIds.value = allSelected.value ? [] : exportableRows.value.map((item) => item.id);
};

const exportsList = ref([]);
const autoDownload = ref(true);
const running = (entry) => ['starting', 'queued', 'zipping'].includes(entry.stage);
const exportFor = (id) => exportsList.value.find((entry) => entry.databaseId === id && running(entry));
let pollTimer = null;

const startExport = async (item) => {
    if (exportFor(item.id)) return;
    exportsList.value.unshift({
        key: `${item.id}-${Date.now()}`,
        databaseId: item.id,
        name: item.database_name,
        engine: item.engine,
        stage: 'starting',
        exportId: '',
        downloadUrl: '',
        fileName: '',
        size: 0,
        message: '',
    });
    const entry = exportsList.value[0];
    try {
        const { data } = await window.axios.post(panelRoute('databases.export', { id: item.id }), {}, { headers: { Accept: 'application/json' } });
        entry.exportId = data.export_id;
        entry.stage = 'queued';
        if (!pollTimer) pollTimer = window.setInterval(pollExports, 3000);
    }
    catch (error) {
        entry.stage = 'failed';
        entry.message = error?.response?.data?.message || error?.message || 'Export could not be started.';
    }
};

const exportSelected = async () => {
    const rows = exportableRows.value.filter((item) => selectedIds.value.includes(item.id));
    selectedIds.value = [];
    for (const item of rows) {
        // eslint-disable-next-line no-await-in-loop
        await startExport(item);
    }
};

const downloadExport = (entry) => {
    if (!entry.downloadUrl) return;
    // A plain link: the browser streams the file itself instead of buffering it in JS.
    const link = document.createElement('a');
    link.href = entry.downloadUrl;
    document.body.appendChild(link);
    link.click();
    link.remove();
    entry.stage = 'downloaded';
};

const pollExports = async () => {
    const pending = exportsList.value.filter((entry) => entry.exportId && ['queued', 'zipping'].includes(entry.stage));
    if (pending.length === 0) {
        window.clearInterval(pollTimer);
        pollTimer = null;
        return;
    }
    for (const entry of pending) {
        try {
            // eslint-disable-next-line no-await-in-loop
            const { data } = await window.axios.get(panelRoute('databases.export.status', { exportId: entry.exportId }), { headers: { Accept: 'application/json' } });
            if (data.stage === 'ready') {
                Object.assign(entry, { stage: 'ready', downloadUrl: data.download_url, fileName: data.file_name || '', size: Number(data.size || 0) });
                if (autoDownload.value) downloadExport(entry);
            }
            else if (data.stage === 'failed') {
                Object.assign(entry, { stage: 'failed', message: data.message || 'Export failed.' });
            }
            else {
                entry.stage = data.stage || entry.stage;
            }
        }
        catch {
            // The next poll tries again.
        }
    }
};

const retryExport = (entry) => {
    exportsList.value = exportsList.value.filter((other) => other !== entry);
    const item = props.databaseRequests.find((row) => row.id === entry.databaseId);
    if (item) startExport(item);
};
const clearFinished = () => {
    exportsList.value = exportsList.value.filter(running);
};

const exportStageText = (entry) => ({
    starting: 'Starting…',
    queued: 'Waiting in the export queue…',
    zipping: 'Dumping and compressing…',
    ready: 'Ready',
    downloaded: 'Downloaded',
    failed: entry.message || 'Failed',
}[entry.stage] || entry.stage);

const formatSize = (bytes) => {
    if (!bytes) return '';
    if (bytes < 1048576) return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    return `${(bytes / 1048576).toFixed(1)} MB`;
};

onUnmounted(() => window.clearInterval(pollTimer));

</script>

<template>
    <Head title="List Databases" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">List Databases</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">View and manage database requests.</p>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ page.props.flash.success }}
            </div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ page.props.flash.error }}
            </div>

            <div class="flex justify-end gap-2">
                <button
                    v-if="canOpenAllDatabases"
                    type="button"
                    class="rounded-md border border-emerald-300 bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-700 hover:bg-emerald-100 dark:border-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-200 dark:hover:bg-emerald-950/50"
                    @click="openRootPhpMyAdmin"
                >
                    phpMyAdmin
                </button>
                <button
                    v-if="canOpenAllDatabases"
                    type="button"
                    class="rounded-md border border-indigo-300 bg-indigo-50 px-3 py-2 text-sm font-medium text-indigo-700 hover:bg-indigo-100 dark:border-indigo-700 dark:bg-indigo-950/30 dark:text-indigo-200 dark:hover:bg-indigo-950/50"
                    @click="openRootPgAdmin"
                >
                    pgAdmin
                </button>
                <button
                    v-if="canOpenAllDatabases"
                    type="button"
                    class="rounded-md border border-teal-300 bg-teal-50 px-3 py-2 text-sm font-medium text-teal-700 hover:bg-teal-100 dark:border-teal-700 dark:bg-teal-950/30 dark:text-teal-200 dark:hover:bg-teal-950/50"
                    @click="openRootMongoAdmin"
                >
                    Mongo Admin
                </button>
                <button
                    type="button"
                    :disabled="selectedIds.length === 0"
                    class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 px-3 py-2 text-sm font-medium hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:hover:bg-slate-800"
                    :title="selectedIds.length ? 'Each database downloads as soon as its own export is ready' : 'Select databases in the list first'"
                    @click="exportSelected"
                >
                    <i class="bi bi-download"></i>
                    Export selected{{ selectedIds.length ? ` (${selectedIds.length})` : '' }}
                </button>
                <Link :href="panelRoute('databases.create')" class="rounded-md bg-blue-600 px-3 py-2 text-sm text-white hover:bg-blue-700">
                    Create Database
                </Link>
            </div>

            <form class="grid gap-3 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900 md:grid-cols-2 md:items-end xl:grid-cols-[minmax(0,1.2fr)_minmax(0,1.2fr)_minmax(0,1fr)_auto_auto]" @submit.prevent="applyFilters">
                <div>
                    <label for="database-name-search" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Name</label>
                    <input
                        id="database-name-search"
                        v-model="searchedName"
                        type="search"
                        placeholder="Search database name"
                        autocomplete="off"
                        class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                    >
                </div>

                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Website</label>
                    <SearchableSelect v-model="selectedWebsite" :options="websiteFilterOptions" placeholder="All websites" search-placeholder="Search websites…" />
                </div>

                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">User</label>
                    <SearchableSelect v-model="selectedUser" :options="userFilterOptions" placeholder="All users" search-placeholder="Search users…" />
                </div>

                <button
                    type="button"
                    class="rounded-md border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800"
                    @click="clearFilters"
                >
                    Clear
                </button>

                <button
                    type="submit"
                    class="rounded-md bg-slate-900 px-4 py-2 text-sm text-white hover:bg-slate-800 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white"
                >
                    Apply Filters
                </button>
            </form>

            <section v-if="exportsList.length" class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900" aria-label="Database exports">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-semibold">Exports</h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Each database downloads on its own as soon as it is ready. If the browser asks, allow this site to download multiple files.</p>
                    </div>
                    <div class="flex items-center gap-3 text-xs">
                        <label class="inline-flex items-center gap-1.5">
                            <input v-model="autoDownload" type="checkbox" class="rounded border-slate-300 dark:border-slate-600">
                            Download automatically
                        </label>
                        <button type="button" class="text-slate-500 hover:underline" @click="clearFinished">Clear finished</button>
                    </div>
                </div>
                <ul class="mt-3 divide-y divide-slate-200 dark:divide-slate-800">
                    <li v-for="entry in exportsList" :key="entry.key" class="flex flex-wrap items-center gap-3 py-2 text-sm">
                        <i
                            :class="[
                                'bi',
                                running(entry) ? 'bi-arrow-repeat inline-block animate-spin text-blue-600' : '',
                                entry.stage === 'ready' || entry.stage === 'downloaded' ? 'bi-check-circle-fill text-emerald-600' : '',
                                entry.stage === 'failed' ? 'bi-x-circle-fill text-red-600' : '',
                            ]"
                            aria-hidden="true"
                        ></i>
                        <span class="font-medium">{{ entry.name }}</span>
                        <span :class="engineOf(entry).badge" class="rounded-full px-2 py-0.5 text-[11px]">{{ engineOf(entry).label }}</span>
                        <span class="min-w-0 flex-1 break-words text-xs" :class="entry.stage === 'failed' ? 'text-red-600 dark:text-red-400' : 'text-slate-500 dark:text-slate-400'">
                            {{ exportStageText(entry) }}<template v-if="entry.size"> · {{ formatSize(entry.size) }}</template>
                        </span>
                        <button
                            v-if="entry.downloadUrl"
                            type="button"
                            class="rounded-md border border-blue-300 px-2 py-1 text-xs text-blue-700 hover:bg-blue-50 dark:border-blue-700 dark:text-blue-300 dark:hover:bg-blue-900/20"
                            @click="downloadExport(entry)"
                        >
                            {{ entry.stage === 'downloaded' ? 'Download again' : 'Download' }}
                        </button>
                        <button
                            v-if="entry.stage === 'failed'"
                            type="button"
                            class="rounded-md border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800"
                            @click="retryExport(entry)"
                        >
                            Retry
                        </button>
                    </li>
                </ul>
            </section>

            <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-800">
                        <tr>
                            <th class="w-10 px-4 py-3">
                                <input
                                    type="checkbox"
                                    class="rounded border-slate-300 dark:border-slate-600"
                                    :checked="allSelected"
                                    :indeterminate="selectedIds.length > 0 && !allSelected"
                                    :disabled="exportableRows.length === 0"
                                    aria-label="Select all databases"
                                    @change="toggleAll"
                                >
                            </th>
                            <th class="px-4 py-3">Attached Website</th>
                            <th class="px-4 py-3">Owner</th>
                            <th class="px-4 py-3">Engine</th>
                            <th class="px-4 py-3">Name</th>
                            <th class="px-4 py-3">User</th>
                           <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Created</th>
                            <th class="px-4 py-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in databaseRequests" :key="item.id" class="border-t border-slate-200 dark:border-slate-800">
                            <td class="px-4 py-3">
                                <input
                                    type="checkbox"
                                    class="rounded border-slate-300 dark:border-slate-600"
                                    :checked="selectedIds.includes(item.id)"
                                    :disabled="item.status !== 'active'"
                                    :title="item.status !== 'active' ? 'Only active databases can be exported' : ''"
                                    :aria-label="`Select ${item.database_name}`"
                                    @change="toggleSelect(item.id)"
                                >
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center gap-1.5">
                                    <span class="rounded-full bg-blue-100 px-2 py-1 text-xs text-blue-700">
                                        {{ item.domain || 'Not attached' }}
                                    </span>
                                    <Link
                                        v-if="item.website_id"
                                        :href="panelRoute('websites.manage', { id: item.website_id })"
                                        class="inline-flex h-6 w-6 items-center justify-center rounded-md text-slate-400 hover:bg-slate-100 hover:text-blue-600 dark:hover:bg-slate-800 dark:hover:text-blue-300"
                                        :title="`Manage ${item.domain}`"
                                        :aria-label="`Manage ${item.domain}`"
                                    >
                                        <i class="bi bi-gear text-xs"></i>
                                    </Link>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-300">
                                {{ item.assigned_user_name || item.assigned_user_email || 'dPanel user' }}
                            </td>
                            <td class="px-4 py-3">
                                <span :class="engineOf(item).badge" class="rounded-full px-2 py-1 text-xs">
                                    {{ engineOf(item).label }}
                                </span>
                            </td>
                            <td class="px-4 py-3 font-medium">{{ item.database_name }}</td>
                            <td class="px-4 py-3">{{ item.database_user }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded-full bg-amber-100 px-2 py-1 text-xs text-amber-700">
                                    {{ item.status }}
                                </span>
                            </td>
                            <td class="px-4 py-3">{{ formatDate(item.created_at) }}</td>
                            <td class="px-4 py-3">
                                <div class="flex flex-col gap-2">
                                    <div class="flex items-center gap-2">
                                    <Link :href="panelRoute('databases.edit', { id: item.id })" class="rounded-md border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                                        Edit
                                    </Link>
                                    <a
                                        :href="databaseLoginUrl(item)"
                                        target="_blank"
                                        rel="noopener"
                                        class="rounded-md border border-blue-300 px-2 py-1 text-xs text-blue-700 hover:bg-blue-50 dark:border-blue-700 dark:text-blue-300 dark:hover:bg-blue-900/20"
                                        :title="`Open in ${engineOf(item).tool} (new tab)`"
                                    >
                                        DB Login
                                    </a>
                                    <button
                                        type="button"
                                        :disabled="item.status !== 'active' || Boolean(exportFor(item.id))"
                                        class="inline-flex items-center gap-1 rounded-md border border-emerald-300 px-2 py-1 text-xs text-emerald-700 hover:bg-emerald-50 disabled:opacity-50 dark:border-emerald-700 dark:text-emerald-300 dark:hover:bg-emerald-900/20"
                                        :title="item.status === 'active' ? `Export ${item.database_name}` : 'Only active databases can be exported'"
                                        @click="startExport(item)"
                                    >
                                        <i :class="['bi', exportFor(item.id) ? 'bi-arrow-repeat inline-block animate-spin' : 'bi-download']"></i>
                                        Export
                                    </button>
                                    <button
                                        :disabled="deleteForm.processing"
                                        class="rounded-md border border-red-300 px-2 py-1 text-xs text-red-700 hover:bg-red-50 disabled:opacity-50 dark:border-red-700 dark:text-red-400"
                                        @click="deleteRequest(item.id)"
                                    >
                                        Delete
                                    </button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="props.databaseRequests.length === 0">
                            <td colspan="9" class="px-4 py-6 text-center text-slate-500">No database requests found.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
