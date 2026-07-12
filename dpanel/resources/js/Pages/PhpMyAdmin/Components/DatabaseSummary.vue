<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
    server: {
        type: Object,
        default: () => ({}),
    },
    databases: {
        type: Array,
        default: () => [],
    },
    databaseSummary: {
        type: Object,
        default: null,
    },
    selectedDatabase: {
        type: String,
        default: '',
    },
    selectedTable: {
        type: String,
        default: '',
    },
    tables: {
        type: Array,
        default: () => [],
    },
    formatBytes: {
        type: Function,
        required: true,
    },
    loading: {
        type: Boolean,
        default: false,
    },
    sqlHref: {
        type: String,
        default: '',
    },
    plain: {
        type: Boolean,
        default: false,
    },
    overviewMode: {
        type: String,
        default: 'about',
    },
});

const emit = defineEmits(['select-table', 'select-database', 'reset', 'filter-change', 'action']);
const filterText = ref('');
const aboutTab = ref('about');

const filteredTables = computed(() => {
    const needle = filterText.value.trim().toLowerCase();
    if (!needle) return props.tables;

    return props.tables.filter((table) => {
        const text = `${table.name} ${table.type || ''} ${table.collation || ''}`.toLowerCase();
        return text.includes(needle);
    });
});

const filteredDatabases = computed(() => {
    const needle = filterText.value.trim().toLowerCase();
    if (!needle) return props.databases;

    return props.databases.filter((database) => {
        const text = `${database.name} ${database.comment || ''}`.toLowerCase();
        return text.includes(needle);
    });
});

const connectionCards = computed(() => ([
    { label: 'Driver', value: props.server?.driver || 'mysql' },
    { label: 'Host', value: props.server?.host ? `${props.server.host}:${props.server?.port || '3306'}` : '127.0.0.1:3306' },
    { label: 'Version', value: props.server?.version || 'n/a' },
]));

const actions = [
    { key: 'browse', label: 'Browse', icon: 'bi bi-table' },
    { key: 'structure', label: 'Structure', icon: 'bi bi-diagram-3' },
    { key: 'search', label: 'Search', icon: 'bi bi-search' },
    { key: 'insert', label: 'Insert', icon: 'bi bi-plus-circle' },
    { key: 'empty', label: 'Empty', icon: 'bi bi-dash-circle' },
    { key: 'drop', label: 'Drop', icon: 'bi bi-trash3' },
];
</script>

<template>
    <section
        class="flex h-full min-h-0 flex-col"
        :class="plain ? 'bg-transparent shadow-none' : 'rounded-2xl border border-slate-300 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900'"
    >
        <div class="flex min-h-0 flex-1 flex-col px-4 py-3">
            <div v-if="!selectedDatabase" class="mb-4 rounded-xl border border-slate-200 bg-slate-50 p-3 dark:border-slate-800 dark:bg-slate-950/40">
                <div class="mb-3 flex items-center gap-2">
                    <button
                        type="button"
                        class="rounded-full border px-3 py-1 text-xs font-semibold uppercase tracking-[0.16em]"
                        :class="overviewMode === 'databases'
                            ? 'border-cyan-300 bg-cyan-50 text-cyan-800 dark:border-cyan-800 dark:bg-cyan-950/30 dark:text-cyan-200'
                            : 'border-slate-300 bg-white text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300'"
                    >
                        {{ overviewMode === 'databases' ? 'Databases' : 'About' }}
                    </button>
                    <span class="text-xs text-slate-500 dark:text-slate-400">Module overview and connection details</span>
                </div>

                <div v-if="overviewMode === 'databases'" class="flex min-h-0 flex-1 flex-col gap-4">
                    <div class="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-cyan-600/80 dark:text-cyan-300/80">Databases</p>
                                <h2 class="mt-1 text-xl font-semibold">Available databases</h2>
                                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                                    Click a database to open it on the right side.
                                </p>
                            </div>
                            <div class="rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-300">
                                {{ filteredDatabases.length }} total
                            </div>
                        </div>
                    </div>

                    <div class="min-h-0 flex-1 overflow-y-auto pr-1" style="scrollbar-gutter: stable;">
                        <div class="space-y-2">
                            <button
                                v-for="database in filteredDatabases"
                                :key="database.name"
                                type="button"
                                class="flex w-full items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 text-left transition hover:border-cyan-300 hover:bg-cyan-50/40 dark:border-slate-800 dark:bg-slate-900 dark:hover:border-cyan-900/60 dark:hover:bg-cyan-950/20"
                                @click="emit('select-database', database.name)"
                            >
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold text-slate-900 dark:text-slate-100">{{ database.name }}</p>
                                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                        {{ database.tables_count ?? 0 }} tables
                                    </p>
                                </div>
                                <div class="flex shrink-0 items-center gap-2">
                                    <span
                                        v-if="database.is_current"
                                        class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300"
                                    >
                                        current
                                    </span>
                                    <span class="text-slate-400">›</span>
                                </div>
                            </button>
                        </div>

                        <div v-if="filteredDatabases.length === 0" class="rounded-2xl border border-dashed border-slate-300 p-4 text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">
                            No databases available.
                        </div>
                    </div>
                </div>

                <div v-else-if="aboutTab === 'about'" class="space-y-4">
                    <div class="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-cyan-600/80 dark:text-cyan-300/80">First-party database module</p>
                                <h2 class="mt-1 text-xl font-semibold">Database Studio</h2>
                                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                                    Select a database from the left tree to open tables and browse rows.
                                </p>
                            </div>
                            <div class="rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-300">
                                Connected
                            </div>
                        </div>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <div
                            v-for="card in connectionCards"
                            :key="card.label"
                            class="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900"
                        >
                            <p class="text-xs uppercase tracking-[0.16em] text-slate-500 dark:text-slate-400">{{ card.label }}</p>
                            <p class="mt-2 break-words text-base font-semibold text-slate-900 dark:text-slate-100">{{ card.value }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <template v-else>
                <div class="mb-3 flex flex-wrap items-center justify-between gap-3 text-sm">
                    <div class="flex items-center gap-3 text-slate-700 dark:text-slate-200">
                        <span class="rounded border border-slate-300 bg-slate-50 px-3 py-1 dark:border-slate-700 dark:bg-slate-800">
                            Database: <strong>{{ selectedDatabase || 'none' }}</strong>
                        </span>
                        <span class="rounded border border-slate-300 bg-slate-50 px-3 py-1 dark:border-slate-700 dark:bg-slate-800">
                            Tables: <strong>{{ databaseSummary?.tables_count ?? tables.length ?? 0 }}</strong>
                        </span>
                        <span class="rounded border border-slate-300 bg-slate-50 px-3 py-1 dark:border-slate-700 dark:bg-slate-800">
                            Size: <strong>{{ formatBytes(databaseSummary?.size_bytes ?? 0) }}</strong>
                        </span>
                    </div>
                    <div class="flex items-center gap-2">
                        <a
                            v-if="sqlHref"
                            :href="sqlHref"
                            class="rounded border border-cyan-300 bg-cyan-50 px-3 py-1.5 text-cyan-800 hover:bg-cyan-100 dark:border-cyan-800 dark:bg-cyan-950/30 dark:text-cyan-200 dark:hover:bg-cyan-950/50"
                        >
                            SQL Console
                        </a>
                        <button
                            type="button"
                            class="rounded border border-slate-300 bg-white px-3 py-1.5 text-slate-700 hover:bg-slate-100 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800"
                            @click="emit('reset')"
                        >
                            Reset
                        </button>
                    </div>
                </div>

                <div class="rounded border border-slate-300 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-800/60">
                    <div class="grid gap-2 md:grid-cols-[auto_1fr] md:items-center">
                        <div class="rounded border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200">
                            Filters
                        </div>
                        <div class="flex items-center gap-2">
                            <label class="text-sm text-slate-600 dark:text-slate-300">Containing the word:</label>
                            <input
                                v-model="filterText"
                                type="text"
                                class="w-full max-w-md rounded border border-slate-300 bg-white px-3 py-1.5 text-sm outline-none focus:border-cyan-400 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"
                                placeholder="Filter tables"
                                @input="emit('filter-change', filterText)"
                            >
                        </div>
                    </div>
                </div>

                <div v-if="loading" class="mt-4 rounded border border-dashed border-slate-300 p-4 text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">
                    Loading database details...
                </div>

                <div v-else class="mt-4 min-h-0 flex-1 overflow-hidden rounded border border-slate-300 dark:border-slate-700">
                    <div class="h-full overflow-auto">
                        <table class="min-w-full table-fixed border-collapse text-left text-sm">
                            <colgroup>
                                <col class="w-8">
                                <col class="w-[400px]">
                                <col class="w-[260px]">
                                <col class="w-[90px]">
                                <col class="w-[120px]">
                                <col class="w-[160px]">
                                <col class="w-[100px]">
                                <col class="w-[100px]">
                            </colgroup>
                            <thead class="bg-slate-100 text-xs font-semibold uppercase tracking-wide text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                <tr>
                                    <th class="w-8 px-2 py-2"></th>
                                    <th class="px-3 py-2">Table</th>
                                    <th class="px-3 py-2">Action</th>
                                    <th class="px-3 py-2 text-right">Rows</th>
                                    <th class="px-3 py-2">Type</th>
                                    <th class="px-3 py-2">Collation</th>
                                    <th class="px-3 py-2 text-right">Size</th>
                                    <th class="px-3 py-2 text-right">Overhead</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="table in filteredTables"
                                    :key="table.name"
                                    class="border-t border-slate-200 odd:bg-white even:bg-slate-50 hover:bg-cyan-50/60 dark:border-slate-700 dark:odd:bg-slate-900 dark:even:bg-slate-900/80 dark:hover:bg-cyan-950/20"
                                >
                                    <td class="px-2 py-2 align-middle">
                                        <input type="checkbox" class="h-4 w-4 rounded border-slate-300 text-cyan-600" :checked="table.name === selectedTable">
                                    </td>
                                    <td class="px-3 py-2 align-middle">
                                        <button
                                            type="button"
                                            class="block w-full truncate text-left font-semibold text-sky-700 hover:underline dark:text-sky-300"
                                            @click="emit('select-table', table.name)"
                                        >
                                            {{ table.name }}
                                        </button>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2">
                                        <div class="flex flex-nowrap items-center gap-3 overflow-x-auto text-xs">
                                            <button
                                                v-for="action in actions"
                                                :key="action.key"
                                                type="button"
                                                class="inline-flex shrink-0 items-center gap-1 whitespace-nowrap text-sky-700 hover:underline dark:text-sky-300"
                                                @click="emit('action', { action: action.key, table: table.name })"
                                            >
                                                <i :class="action.icon"></i>
                                                <span>{{ action.label }}</span>
                                            </button>
                                        </div>
                                    </td>
                                    <td class="px-3 py-2 text-right text-slate-700 dark:text-slate-300">{{ table.estimated_rows ?? 0 }}</td>
                                    <td class="px-3 py-2 text-slate-700 dark:text-slate-300">{{ table.type || 'BASE TABLE' }}</td>
                                    <td class="px-3 py-2 text-slate-700 dark:text-slate-300">{{ table.collation || '-' }}</td>
                                    <td class="px-3 py-2 text-right text-slate-700 dark:text-slate-300">{{ formatBytes(table.size_bytes ?? 0) }}</td>
                                    <td class="px-3 py-2 text-right text-slate-700 dark:text-slate-300">0 B</td>
                                </tr>
                                <tr v-if="filteredTables.length === 0">
                                    <td colspan="8" class="px-4 py-6 text-center text-slate-500 dark:text-slate-400">
                                        No tables match the current filter.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </template>
        </div>
    </section>
</template>
