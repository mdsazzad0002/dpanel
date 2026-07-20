<script setup>
import { computed, ref, onMounted, watch } from 'vue';

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
    plain: {
        type: Boolean,
        default: false,
    },
    overviewMode: {
        type: String,
        default: 'about',
    },
    canDrop: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['select-table', 'select-database', 'reset', 'filter-change', 'action', 'bulk-action']);
const filterText = ref('');
const aboutTab = ref('about');
const animatedCounters = ref({});
const selectedTableNames = ref(new Set());
const bulkAction = ref('browse');

const databaseName = (database) => (typeof database === 'string' ? database : String(database?.name || ''));

const filteredTables = computed(() => {
    const needle = filterText.value.trim().toLowerCase();
    if (!needle) return props.tables;

    return props.tables.filter((table) => {
        const text = `${table.name} ${table.type || ''} ${table.collation || ''}`.toLowerCase();
        return text.includes(needle);
    });
});

const tableNameFor = (table) => String(table?.name || '').trim();

const visibleTableNames = computed(() => filteredTables.value.map((table) => tableNameFor(table)).filter(Boolean));
const selectedTableCount = computed(() => selectedTableNames.value.size);
const allVisibleSelected = computed(() => visibleTableNames.value.length > 0 && visibleTableNames.value.every((name) => selectedTableNames.value.has(name)));

const rowCountLabel = (table) => {
    const count = Number(table?.estimated_rows ?? 0);
    if (!Number.isFinite(count) || count <= 0) {
        return '0';
    }

    return new Intl.NumberFormat().format(count);
};

const toggleTableSelected = (table, checked) => {
    const name = tableNameFor(table);
    if (!name) return;

    const next = new Set(selectedTableNames.value);
    if (checked) {
        next.add(name);
    } else {
        next.delete(name);
    }

    selectedTableNames.value = next;
};

const toggleSelectAllVisible = (checked) => {
    if (!checked) {
        selectedTableNames.value = new Set();
        return;
    }

    selectedTableNames.value = new Set(visibleTableNames.value);
};

const runBulkAction = () => {
    if (selectedTableNames.value.size === 0) return;

    emit('bulk-action', {
        action: bulkAction.value,
        tables: filteredTables.value.filter((table) => selectedTableNames.value.has(tableNameFor(table))),
    });
};

const filteredDatabases = computed(() => {
    const needle = filterText.value.trim().toLowerCase();
    if (!needle) return props.databases;

    return props.databases.filter((database) => databaseName(database).toLowerCase().includes(needle));
});

const connectionCards = computed(() => ([
    { label: 'Driver', value: props.server?.driver || 'mysql', icon: 'bi-plug', color: 'blue' },
    { label: 'Host', value: props.server?.host ? `${props.server.host}:${props.server?.port || '3306'}` : '127.0.0.1:3306', icon: 'bi-hdd-rack', color: 'purple' },
    { label: 'Version', value: props.server?.version || 'n/a', icon: 'bi-info-circle', color: 'green' },
]));

const statCards = computed(() => [
    { label: 'Databases', value: props.databases.length, icon: 'bi-database', color: 'blue' },
    { label: 'Tables', value: props.tables.length, icon: 'bi-table', color: 'cyan' },
    { label: 'Data Size', value: props.formatBytes(props.databaseSummary?.data_length || 0), icon: 'bi-hdd', color: 'purple' },
    { label: 'Index Size', value: props.formatBytes(props.databaseSummary?.index_length || 0), icon: 'bi bi-diagram-3', color: 'amber' },
]);

const animateCounter = (key, target) => {
    const numTarget = Number(target);
    if (!Number.isFinite(numTarget)) {
        animatedCounters.value[key] = target;
        return;
    }

    let current = 0;
    const duration = 600;
    const step = Math.ceil(numTarget / (duration / 16));
    const interval = setInterval(() => {
        current += step;
        if (current >= numTarget) {
            current = numTarget;
            clearInterval(interval);
        }
        animatedCounters.value[key] = current;
    }, 16);
};

const actions = computed(() => [
    { key: 'browse', label: 'Browse', icon: 'bi-table' },
    { key: 'structure', label: 'Structure', icon: 'bi-diagram-3' },
    { key: 'search', label: 'Search', icon: 'bi-search' },
    { key: 'insert', label: 'Insert', icon: 'bi-plus-circle' },
    { key: 'empty', label: 'Empty', icon: 'bi-dash-circle' },
    ...(props.canDrop ? [{ key: 'drop', label: 'Drop', icon: 'bi-trash3' }] : []),
]);

watch(() => props.selectedDatabase, () => {
    animatedCounters.value = {};
    selectedTableNames.value = new Set();
    if (props.selectedDatabase) {
        animateCounter('tables', props.tables.length);
    }
}, { immediate: true });

watch(() => props.tables, () => {
    const visible = new Set(props.tables.map((table) => tableNameFor(table)).filter(Boolean));
    selectedTableNames.value = new Set(Array.from(selectedTableNames.value).filter((name) => visible.has(name)));
}, { deep: true });
</script>

<template>
    <section class="min-h-0 flex-1">
        <!-- No Database Selected -->
        <div v-if="!selectedDatabase" class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-700 dark:bg-slate-900">
            <div class="mb-6 flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-100 text-blue-600 dark:bg-blue-900/40 dark:text-blue-400">
                    <i class="bi bi-database text-lg"></i>
                </div>
                <div>
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Databases</h2>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Select a database from the sidebar</p>
                </div>
            </div>

            <!-- Database List -->
            <div class="rounded-lg border border-slate-200 dark:border-slate-700">
                <div class="border-b border-slate-200 bg-slate-50 px-4 py-2 dark:border-slate-700 dark:bg-slate-800">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-slate-700 dark:text-slate-300">All Databases</span>
                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500 dark:bg-slate-800 dark:text-slate-400">{{ filteredDatabases.length }}</span>
                    </div>
                </div>
                <div class="divide-y divide-slate-200 dark:divide-slate-700">
                    <button
                        v-for="(database, index) in filteredDatabases"
                        :key="databaseName(database)"
                        type="button"
                        class="flex w-full items-center justify-between px-4 py-3 text-left transition-all duration-200 hover:bg-slate-50 hover:pl-6 dark:hover:bg-slate-800"
                        :style="{ animationDelay: `${index * 30}ms` }"
                        @click="emit('select-database', databaseName(database))"
                    >
                        <div class="flex items-center gap-3">
                            <div class="flex h-7 w-7 items-center justify-center rounded-md bg-blue-50 text-blue-500 dark:bg-blue-900/30 dark:text-blue-400">
                                <i class="bi bi-database text-xs"></i>
                            </div>
                            <span class="text-sm font-medium text-slate-700 dark:text-slate-300">{{ databaseName(database) }}</span>
                        </div>
                        <i class="bi bi-chevron-right text-xs text-slate-400 transition-transform group-hover:translate-x-0.5"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- Database Selected -->
        <div v-else class="space-y-4">
            <!-- Database Header -->
            <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-100 text-blue-600 dark:bg-blue-900/40 dark:text-blue-400">
                            <i class="bi bi-database text-lg"></i>
                        </div>
                        <div>
                            <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ selectedDatabase }}</h2>
                            <p class="text-sm text-slate-500 dark:text-slate-400">{{ animatedCounters.tables ?? tables.length }} tables</p>
                        </div>
                    </div>
                    <button
                        type="button"
                        class="rounded-lg border border-slate-200 px-3 py-1.5 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
                        @click="emit('reset')"
                    >
                        <i class="bi bi-arrow-left mr-1.5"></i>
                        Back to Databases
                    </button>
                </div>

                <!-- Stat Cards -->
                <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div
                        v-for="stat in statCards"
                        :key="stat.label"
                        class="rounded-lg border border-slate-200 p-3 transition-all duration-200 hover:shadow-sm dark:border-slate-700"
                    >
                        <div class="flex items-center gap-2">
                            <div :class="[
                                'flex h-6 w-6 items-center justify-center rounded text-xs',
                                stat.color === 'blue' ? 'bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400' : '',
                                stat.color === 'cyan' ? 'bg-cyan-100 text-cyan-600 dark:bg-cyan-900/30 dark:text-cyan-400' : '',
                                stat.color === 'purple' ? 'bg-purple-100 text-purple-600 dark:bg-purple-900/30 dark:text-purple-400' : '',
                                stat.color === 'amber' ? 'bg-amber-100 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400' : '',
                            ]">
                                <i :class="['bi', stat.icon]"></i>
                            </div>
                            <span class="text-xs text-slate-500 dark:text-slate-400">{{ stat.label }}</span>
                        </div>
                        <p class="mt-1.5 text-lg font-semibold text-slate-700 dark:text-slate-300">{{ stat.value }}</p>
                    </div>
                </div>
            </div>

            <!-- Table List -->
            <div class="rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900">
                <div class="border-b border-slate-200 px-4 py-3 dark:border-slate-700">
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-300">Tables</h3>
                        <div class="flex items-center gap-2">
                            <span v-if="selectedTableCount > 0" class="rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">
                                {{ selectedTableCount }} selected
                            </span>
                            <div class="relative">
                                <i class="bi bi-search pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-xs text-slate-400"></i>
                                <input
                                    v-model="filterText"
                                    type="text"
                                    class="w-48 rounded-lg border border-slate-200 bg-white py-1.5 pl-8 pr-3 text-sm outline-none focus:border-blue-400 focus:ring-1 focus:ring-blue-400/20 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-300"
                                    placeholder="Filter tables..."
                                >
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="loading" class="flex items-center justify-center py-12">
                    <div class="flex items-center gap-2 text-sm text-slate-500 dark:text-slate-400">
                        <i class="bi bi-arrow-repeat animate-spin"></i>
                        Loading tables...
                    </div>
                </div>

                <div v-else-if="filteredTables.length === 0" class="py-12 text-center">
                    <i class="bi bi-inbox text-3xl text-slate-300 dark:text-slate-600"></i>
                    <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">No tables found</p>
                </div>

                <!-- phpMyAdmin-style Table -->
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold tracking-wider text-slate-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-400">
                                <th class="w-10 px-1 py-1 text-center">
                                    <input
                                        type="checkbox"
                                        :checked="allVisibleSelected"
                                        class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500 dark:border-slate-600"
                                        @change="toggleSelectAllVisible($event.target.checked)"
                                    >
                                </th>
                                <th class="px-1 py-1">Table</th>
                                <th class="px-1 py-1 text-center">Rows</th>
                                <th class="px-1 py-1 text-center">Actions</th>
                                <th class="px-1 py-1 text-center">Type</th>
                                <th class="px-1 py-1 text-center">Collation</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr
                                v-for="(table, index) in filteredTables"
                                :key="table.name"
                                class="group transition-colors hover:bg-slate-50 dark:hover:bg-slate-800/50"
                            >
                                <td class="px-1 py-1 text-center align-middle">
                                    <input
                                        type="checkbox"
                                        :checked="selectedTableNames.has(table.name)"
                                        class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500 dark:border-slate-600"
                                        @change="toggleTableSelected(table, $event.target.checked)"
                                    >
                                </td>
                                <td class="px-1 py-1">
                                    <div class="flex items-center gap-2">
                                        <div class="flex h-5 w-5 items-center justify-center rounded bg-slate-100 dark:bg-slate-800">
                                            <i class="bi bi-table text-[10px] text-slate-400"></i>
                                        </div>
                                        <button
                                            type="button"
                                            class="font-medium text-blue-600 transition-colors hover:text-blue-700 hover:underline dark:text-blue-400 dark:hover:text-blue-300"
                                            @click="emit('action', { action: 'browse', table: table.name })"
                                        >
                                            {{ table.name }}
                                        </button>
                                    </div>
                                </td>
                                <td class="px-1 py-1 text-center text-slate-600 dark:text-slate-400">
                                    ~{{ rowCountLabel(table) }}
                                </td>
                                <td class="px-1 py-1">
                                    <div class="flex items-center justify-center gap-1 opacity-60 transition-opacity group-hover:opacity-100">
                                        <button
                                            v-for="action in actions"
                                            :key="action.key"
                                            type="button"
                                            class="rounded px-2 py-1 text-xs font-medium transition-colors hover:bg-slate-100 dark:hover:bg-slate-700"
                                            :class="action.key === 'drop' ? 'text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20' : 'text-slate-600 dark:text-slate-400'"
                                            :title="action.label"
                                            @click="emit('action', { action: action.key, table: table.name })"
                                        >
                                            <i :class="['bi', action.icon]"></i>
                                        </button>
                                    </div>
                                </td>
                                <td class="px-1 py-1 text-center">
                                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-400">
                                        {{ table.type || 'InnoDB' }}
                                    </span>
                                </td>
                                <td class="px-1 py-1 text-center text-slate-600 dark:text-slate-400">{{ table.collation || 'utf8mb4' }}</td>

                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-4 py-3 text-sm dark:border-slate-700">
                    <div class="text-slate-500 dark:text-slate-400">
                        {{ filteredTables.length }} table{{ filteredTables.length === 1 ? '' : 's' }} shown
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <label class="flex items-center gap-2 text-slate-600 dark:text-slate-300">
                            <span class="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">With selected</span>
                            <select
                                v-model="bulkAction"
                                class="rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-sm outline-none focus:border-blue-400 focus:ring-1 focus:ring-blue-400/20 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-300"
                            >
                                <option value="browse">Browse first</option>
                                <option value="structure">Structure first</option>
                                <option value="empty">Empty</option>
                                <option v-if="canDrop" value="drop">Drop</option>
                            </select>
                        </label>
                        <button
                            type="button"
                            class="rounded-lg bg-slate-900 px-3 py-1.5 text-sm font-medium text-white transition-colors hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white"
                            :disabled="selectedTableCount === 0"
                            @click="runBulkAction"
                        >
                            Apply
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>
