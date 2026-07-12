<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
    databases: {
        type: Array,
        default: () => [],
    },
    selectedDatabase: {
        type: String,
        default: '',
    },
    selectedTable: {
        type: String,
        default: '',
    },
    tablesByDatabase: {
        type: Object,
        default: () => ({}),
    },
    loading: {
        type: Boolean,
        default: false,
    },
    filterText: {
        type: String,
        default: '',
    },
    expandedDatabases: {
        type: Array,
        default: () => [],
    },
});

const emit = defineEmits(['select-database', 'select-table', 'filter-change', 'toggle-database']);

const databaseName = (database) => (typeof database === 'string' ? database : String(database?.name || ''));

const databaseEntry = (database) => props.tablesByDatabase[databaseName(database)] || null;

const databaseTables = (database) => {
    const entry = databaseEntry(database);
    return Array.isArray(entry?.tables) ? entry.tables : [];
};

const tableCount = (database) => databaseTables(database).length;

const filteredDatabases = computed(() => {
    const needle = props.filterText.trim().toLowerCase();
    if (!needle) return props.databases;

    return props.databases.filter((database) => databaseName(database).toLowerCase().includes(needle));
});

const expandedDatabases = computed(() => new Set(props.expandedDatabases));
const tableFilters = ref({});

const isDatabaseExpanded = (database) => expandedDatabases.value.has(databaseName(database));

const tableFilterText = (database) => tableFilters.value[databaseName(database)] || '';

const setTableFilterText = (database, value) => {
    const name = databaseName(database);
    tableFilters.value = {
        ...tableFilters.value,
        [name]: value,
    };
};

const filteredTables = (database) => {
    const needle = tableFilterText(database).trim().toLowerCase();
    const tables = databaseTables(database);

    if (!needle) return tables;

    return tables.filter((table) => String(table?.name || '').toLowerCase().includes(needle));
};

const toggleDatabase = (database) => {
    emit('toggle-database', databaseName(database));
};

const handleDatabaseToggle = (database) => {
    toggleDatabase(database);
};
</script>

<template>
    <aside class="flex h-full min-h-0 flex-col overflow-hidden rounded border border-slate-300 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">
        <div class="border-b border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-900">
            <div class="flex items-center gap-2.5">
                <div class="grid h-8 w-8 place-items-center rounded bg-blue-500/10 ring-1 ring-blue-400/20 dark:bg-blue-500/15 dark:ring-blue-400/30">
                    <i class="bi bi-database-fill text-sm text-blue-700 dark:text-blue-300"></i>
                </div>
                <div class="min-w-0">
                    <p class="text-[10px] font-semibold uppercase tracking-[0.22em] text-blue-700/80 dark:text-blue-300/80">
                        First-party database module
                    </p>
                    <h1 class="text-base font-semibold leading-tight">Database Studio</h1>
                </div>
            </div>
        </div>

        <div class="border-b border-slate-300 bg-slate-50 p-2 dark:border-slate-700 dark:bg-slate-950">
            <div class="relative">
                <input
                    :value="filterText"
                    type="text"
                    class="w-full rounded border border-slate-300 bg-white py-1.5 pl-3 pr-8 text-sm outline-none focus:border-blue-400 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                    placeholder="Type to filter databases, Enter to search"
                    @input="emit('filter-change', $event.target.value)"
                >
                <span class="pointer-events-none absolute right-2 top-1.5 text-xs text-slate-400">?</span>
            </div>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain p-2 pr-1" style="scrollbar-gutter: stable;">
            <div v-if="loading" class="rounded border border-dashed border-slate-300 p-3 text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">
                Loading databases...
            </div>

            <div v-else class="space-y-2">
                <div
                    v-for="database in filteredDatabases"
                    :key="databaseName(database)"
                    class="rounded border border-slate-300 bg-white dark:border-slate-700 dark:bg-slate-950/40"
                >
                    <div class="flex items-stretch gap-1 px-1 py-1">
                        <button
                            type="button"
                            class="flex min-w-0 flex-1 items-center justify-between gap-3 rounded px-2 py-1.5 text-left text-sm font-semibold transition"
                            :class="databaseName(database) === selectedDatabase ? 'bg-blue-50 text-blue-800 dark:bg-blue-950/30 dark:text-blue-200' : 'text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-900'"
                            @click="emit('select-database', databaseName(database))"
                        >
                            <span class="truncate">{{ databaseName(database) }}</span>
                            <span class="text-xs font-medium text-slate-400 dark:text-slate-500">{{ tableCount(database) }}</span>
                        </button>

                        <button
                            type="button"
                            class="grid h-8 w-8 shrink-0 place-items-center rounded border border-slate-300 bg-white text-slate-600 transition hover:border-blue-300 hover:bg-blue-50 hover:text-blue-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:border-blue-900/60 dark:hover:bg-blue-950/20 dark:hover:text-blue-300"
                            :aria-expanded="isDatabaseExpanded(database)"
                            :aria-label="`${isDatabaseExpanded(database) ? 'Collapse' : 'Expand'} ${databaseName(database)}`"
                            @click="handleDatabaseToggle(database)"
                        >
                            <i :class="isDatabaseExpanded(database) ? 'bi bi-dash-lg' : 'bi bi-plus-lg'"></i>
                        </button>
                    </div>

                    <div
                        v-if="isDatabaseExpanded(database)"
                        class="border-t border-slate-300 bg-slate-50 p-2 dark:border-slate-700 dark:bg-slate-900"
                    >
                        <div class="mb-2 flex items-center justify-between gap-2">
                            <div class="text-xs text-slate-500 dark:text-slate-400">
                                {{ tableCount(database) }} tables
                            </div>
                            <div class="w-full max-w-[220px]">
                                <input
                                    :value="tableFilterText(database)"
                                    type="text"
                                    class="w-full rounded border border-slate-300 bg-white px-2 py-1 text-xs outline-none focus:border-cyan-400 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                                    placeholder="Search tables"
                                    @input="setTableFilterText(database, $event.target.value)"
                                >
                            </div>
                        </div>
                        <div class="max-h-[600px] overflow-y-auto pr-1">
                            <div v-if="databaseTables(database).length > 0" class="space-y-1">
                                <button
                                    v-for="table in filteredTables(database)"
                                    :key="table.name"
                                    type="button"
                                    class="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-sm hover:bg-cyan-50 dark:hover:bg-cyan-950/20"
                                    :class="table.name === selectedTable ? 'bg-cyan-50 text-cyan-800 dark:bg-cyan-950/20 dark:text-cyan-200' : 'text-slate-700 dark:text-slate-300'"
                                    @click="emit('select-table', { database: databaseName(database), table: table.name })"
                                >
                                    <span class="text-slate-400">&gt;</span>
                                    <span class="truncate">{{ table.name }}</span>
                                </button>
                            </div>
                            <div
                                v-else
                                class="rounded border border-dashed border-slate-300 px-2 py-2 text-xs text-slate-500 dark:border-slate-700 dark:text-slate-400"
                            >
                                Tables will appear here once loaded.
                            </div>
                            <div
                                v-if="databaseTables(database).length > 0 && filteredTables(database).length === 0"
                                class="rounded border border-dashed border-slate-300 px-2 py-2 text-xs text-slate-500 dark:border-slate-700 dark:text-slate-400"
                            >
                                No tables match your search.
                            </div>
                        </div>
                    </div>
                </div>

                <div
                    v-if="filteredDatabases.length === 0"
                    class="rounded border border-dashed border-slate-300 p-3 text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400"
                >
                    No databases available.
                </div>
            </div>
        </div>
    </aside>
</template>
