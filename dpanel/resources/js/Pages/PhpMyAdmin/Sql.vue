<script setup>
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DatabaseStudioLayout from '@/Layouts/DatabaseStudioLayout.vue';
import DatabaseSidebar from './Components/DatabaseSidebar.vue';
import SqlConsole from './Components/SqlConsole.vue';
import Sqlhistory from './Components/Sqlhistory.vue';
import PhpMyAdminToastStack from './Components/PhpMyAdminToastStack.vue';
import { usePhpMyAdminTransport } from './composables/usePhpMyAdminTransport.js';
import { usePhpMyAdminReadState } from './composables/usePhpMyAdminReadState.js';

const props = defineProps({
    panelToken: {
        type: String,
        default: '',
    },
    server: {
        type: Object,
        default: () => ({}),
    },
    initialSelection: {
        type: Object,
        default: () => ({}),
    },
    queryDefaults: {
        type: Object,
        default: () => ({ sql: '' }),
    },
});

const transport = usePhpMyAdminTransport(props);
const readState = usePhpMyAdminReadState(props, transport);

const {
    panelRoute,
    toasts,
    pushToast,
    removeToast,
} = transport;

const {
    databases,
    selectedDatabase,
    selectedTable,
    expandedDatabases,
    databaseCache,
    loadingDatabases,
    loadingDatabase,
    databaseError,
    sidebarFilter,
    currentDatabaseTables,
    currentDatabase,
    loadDatabase,
    handleExportDatabase,
    handleImportDatabase,
} = readState;

const querySql = ref(String(props.queryDefaults?.sql || ''));
const sqlHistoryEntries = ref([]);

const mainHref = computed(() => panelRoute('phpmyadmin.index'));
const tablesByDatabase = computed(() => databaseCache.value);

const toggleDatabase = async (database, forceOpen = false) => {
    if (!database) return;

    const isOpen = expandedDatabases.value.has(database);

    if (isOpen && !forceOpen) {
        const next = new Set(expandedDatabases.value);
        next.delete(database);
        expandedDatabases.value = next;
        if (selectedDatabase.value === database) {
            selectedDatabase.value = '';
            selectedTable.value = '';
        }
        return;
    }

    if (!isOpen) {
        const next = new Set(expandedDatabases.value);
        next.add(database);
        expandedDatabases.value = next;
    }

    await loadDatabase(database);
    if (!selectedTable.value) {
        querySql.value = `SHOW TABLES FROM \`${database}\`;`;
    }
};

const handleSelectDatabase = async (database) => {
    await toggleDatabase(database);
};

const handleSelectTable = async ({ database, table }) => {
    if (!database || !table) return;

    if (!expandedDatabases.value.has(database)) {
        await toggleDatabase(database, true);
    }

    selectedDatabase.value = database;
    selectedTable.value = table;
    querySql.value = `SELECT * FROM \`${database}\`.\`${table}\` LIMIT 25;`;
};

const handleSidebarFilterChange = (value) => {
    sidebarFilter.value = value;
};

const handleHistoryUpdated = (entries) => {
    sqlHistoryEntries.value = Array.isArray(entries) ? entries : [];
};

const handleUseHistoryEntry = (entry) => {
    querySql.value = String(entry?.sql || '');
};

const handleToolbarAction = (action) => {
    if (action === 'sql') {
        return;
    }

    if (action === 'export') {
        void handleExportDatabase();
        return;
    }

    if (action === 'import') {
        void handleImportDatabase();
        return;
    }

    if (action === 'structure') {
        const table = selectedTable.value || currentDatabaseTables.value[0]?.name || '';
        if (table && selectedDatabase.value) {
            querySql.value = `DESCRIBE \`${selectedDatabase.value}\`.\`${table}\`;`;
        }
        return;
    }

    if (action === 'browse') {
        const table = selectedTable.value || currentDatabaseTables.value[0]?.name || '';
        if (table && selectedDatabase.value) {
            querySql.value = `SELECT * FROM \`${selectedDatabase.value}\`.\`${table}\` LIMIT 25;`;
        }
    }
};

const handleDatabaseChange = async (value) => {
    selectedDatabase.value = value;
    selectedTable.value = '';

    if (value) {
        await loadDatabase(value);
    }
};
</script>

<template>
    <Head title="SQL" />

    <DatabaseStudioLayout :server="server" @toolbar-action="handleToolbarAction">
        <div
            class="flex h-full min-h-0 flex-col gap-4 overflow-hidden"
            :style="{ paddingBottom: 'calc(var(--phpmyadmin-sql-history-height, 0px) + 1rem)' }"
        >
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white px-4 py-3 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-cyan-600/80 dark:text-cyan-300/80">SQL</p>
                    <h1 class="text-lg font-semibold">Database-scoped queries</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        Active database: <strong>{{ currentDatabase || 'none' }}</strong>
                    </p>
                </div>

                <a
                    :href="mainHref"
                    class="rounded-full border border-slate-300 bg-slate-50 px-3 py-2 text-sm text-slate-700 hover:bg-slate-100 dark:border-slate-700 dark:bg-slate-950/40 dark:text-slate-200 dark:hover:bg-slate-800"
                >
                    Back to Database Module
                </a>
            </div>

            <div v-if="databaseError" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-300">
                {{ databaseError }}
            </div>

            <div class="grid min-h-0 gap-4 lg:grid-cols-12">
                <div class="min-h-0 lg:col-span-4">
                    <DatabaseSidebar
                        :databases="databases"
                        :expanded-databases="Array.from(expandedDatabases)"
                        :selected-database="selectedDatabase"
                        :selected-table="selectedTable"
                        :tables-by-database="tablesByDatabase"
                        :filter-text="sidebarFilter"
                        :loading="loadingDatabases"
                        @toggle-database="handleSelectDatabase"
                        @select-table="handleSelectTable"
                        @filter-change="handleSidebarFilterChange"
                    />
                </div>

                <div class="min-h-0 lg:col-span-8">
                    <SqlConsole
                        :panel-route="panelRoute"
                        :selected-database="selectedDatabase"
                        :selected-table="selectedTable"
                        :initial-sql="querySql"
                        :schema-tables="currentDatabaseTables"
                        :plain="true"
                        :auto-focus="true"
                        :notify="pushToast"
                        @history-updated="handleHistoryUpdated"
                    />
                </div>
            </div>
        </div>

        <Sqlhistory
            :history-entries="sqlHistoryEntries"
            @use-entry="handleUseHistoryEntry"
        />
        <PhpMyAdminToastStack :toasts="toasts" @dismiss="removeToast" />
    </DatabaseStudioLayout>
</template>
