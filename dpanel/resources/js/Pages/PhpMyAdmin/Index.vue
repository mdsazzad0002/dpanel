<script setup>
import { Head, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import DatabaseStudioLayout from '@/Layouts/DatabaseStudioLayout.vue';
import DatabaseSidebar from './Components/DatabaseSidebar.vue';
import DatabaseSummary from './Components/DatabaseSummary.vue';
import TableBrowser from './Components/TableBrowser.vue';
import SqlConsole from './Components/SqlConsole.vue';
import PhpMyAdminToastStack from './Components/PhpMyAdminToastStack.vue';

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

const page = usePage();
const panelToken = computed(() => String(page.props.panel?.token || props.panelToken || ''));
const panelRoute = (name, params = {}) => (
    panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params)
);

const formatBytes = (bytes) => {
    const value = Number(bytes || 0);
    if (!Number.isFinite(value) || value <= 0) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const index = Math.min(units.length - 1, Math.floor(Math.log(value) / Math.log(1024)));
    return `${(value / (1024 ** index)).toFixed(index === 0 ? 0 : 2)} ${units[index]}`;
};

const databases = ref([]);
const selectedDatabase = ref('');
const selectedTable = ref('');
const databaseSummary = ref(null);
const tables = ref([]);
const tableDetails = ref(null);
const activeTableAction = ref('browse');
const loadingDatabases = ref(false);
const loadingDatabase = ref(false);
const loadingTable = ref(false);
const databaseError = ref('');
const tableError = ref('');
const pageNumber = ref(Number(props.initialSelection?.page || 1));
const perPage = ref(Number(props.initialSelection?.perPage || 25));
const selectedTablePage = ref(Number(props.initialSelection?.page || 1));
const expandedDatabases = ref([]);
const databaseCache = ref({});
const sidebarFilter = ref('');
const toasts = ref([]);
const splitRow = ref(null);
const splitWidth = ref(34);
const isResizing = ref(false);
const overviewMode = ref('about');
const overviewSqlFullscreen = ref(false);
let toastSeq = 0;
const SPLIT_KEY = 'serverpanel-phpmyadmin-split-width';
const SPLIT_MIN = 24;
const SPLIT_MAX = 58;
const tablesByDatabase = computed(() => databaseCache.value);
const currentDatabaseTables = computed(() => tables.value);
const headerMode = computed(() => (selectedDatabase.value ? 'compact' : 'overview'));
const sqlHref = computed(() => panelRoute('phpmyadmin.sql', {
    database: selectedDatabase.value || props.server?.current_database || '',
    table: selectedTable.value || '',
}));

const safeJson = async (response) => response.json().catch(() => ({}));

const clampSplitWidth = (value) => Math.min(SPLIT_MAX, Math.max(SPLIT_MIN, value));

const loadSplitWidth = () => {
    if (typeof window === 'undefined') return;

    try {
        const saved = Number(window.localStorage.getItem(SPLIT_KEY));
        if (Number.isFinite(saved)) {
            splitWidth.value = clampSplitWidth(saved);
        }
    } catch {
        // Ignore storage failures.
    }
};

const saveSplitWidth = () => {
    if (typeof window === 'undefined') return;

    try {
        window.localStorage.setItem(SPLIT_KEY, String(splitWidth.value));
    } catch {
        // Ignore storage failures.
    }
};

const updateSplitWidth = (clientX) => {
    if (!splitRow.value) return;

    const rect = splitRow.value.getBoundingClientRect();
    if (!rect.width) return;

    const nextWidth = ((clientX - rect.left) / rect.width) * 100;
    splitWidth.value = clampSplitWidth(nextWidth);
    saveSplitWidth();
};

const stopResize = () => {
    if (!isResizing.value) return;

    isResizing.value = false;
    window.removeEventListener('pointermove', handleResizeMove);
    window.removeEventListener('pointerup', stopResize);
    window.removeEventListener('pointercancel', stopResize);
};

function handleResizeMove(event) {
    updateSplitWidth(event.clientX);
}

const startResize = (event) => {
    event.preventDefault();
    isResizing.value = true;
    updateSplitWidth(event.clientX);
    window.addEventListener('pointermove', handleResizeMove);
    window.addEventListener('pointerup', stopResize);
    window.addEventListener('pointercancel', stopResize);
};

const removeToast = (id) => {
    toasts.value = toasts.value.filter((toast) => toast.id !== id);
};

const pushToast = (message) => {
    if (!message) return;

    const id = `${Date.now()}-${toastSeq += 1}`;
    toasts.value.push({ id, message: String(message), type: 'error' });

    window.setTimeout(() => {
        removeToast(id);
    }, 4500);
};

const loadDatabases = async () => {
    loadingDatabases.value = true;
    databaseError.value = '';

    try {
        const response = await fetch(panelRoute('phpmyadmin.databases'), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        const data = await safeJson(response);
        if (!response.ok || !data?.ok) {
            databaseError.value = data?.message || 'Failed to load databases.';
            pushToast(databaseError.value);
            databases.value = [];
            return;
        }

        databases.value = Array.isArray(data.databases) ? data.databases : [];
    } catch (error) {
        databaseError.value = error?.message || 'Failed to load databases.';
        pushToast(databaseError.value);
    } finally {
        loadingDatabases.value = false;
    }
};

const loadDatabase = async (database, options = {}) => {
    if (!database) return;

    const loadRows = options.loadRows ?? false;
    loadingDatabase.value = true;
    databaseError.value = '';
    tableError.value = '';
    if (options.selectDatabase !== false) {
        selectedDatabase.value = database;
    }
    if (!loadRows) {
        selectedTable.value = '';
        tableDetails.value = null;
        activeTableAction.value = 'browse';
    }
    if (loadRows) {
        loadingTable.value = true;
        selectedTable.value = String(options.table || '');
        tableDetails.value = null;
        activeTableAction.value = options.action || 'browse';
    }

    const query = {
        perPage: options.perPage || perPage.value || 25,
        page: options.page || pageNumber.value || 1,
    };

    if (loadRows && options.table) {
        query.table = options.table;
    }

    try {
        const response = await fetch(panelRoute('phpmyadmin.database', { database, ...query }), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        const data = await safeJson(response);
        if (!response.ok || !data?.ok) {
            databaseError.value = data?.message || 'Failed to load database details.';
            pushToast(databaseError.value);
            tables.value = [];
            databaseSummary.value = null;
            return;
        }

        databaseCache.value = {
            ...databaseCache.value,
            [database]: {
                summary: data.summary || null,
                tables: Array.isArray(data.tables) ? data.tables : [],
            },
        };
        databaseSummary.value = data.summary || null;
        tables.value = Array.isArray(data.tables) ? data.tables : [];

        if (loadRows && !selectedTable.value) {
            selectedTable.value = String(data.selected_table || tables.value[0]?.name || '');
        }

        if (loadRows && selectedTable.value) {
            await loadTable(selectedDatabase.value, selectedTable.value, {
                page: Number(data?.table_details?.pagination?.current_page || query.page || 1),
                perPage: Number(data?.table_details?.pagination?.per_page || query.perPage || 25),
                reusePayload: data.table_details || null,
                action: 'browse',
            });
        } else if (!loadRows) {
            tableDetails.value = null;
        } else {
            tableDetails.value = null;
        }
    } catch (error) {
        databaseError.value = error?.message || 'Failed to load database details.';
        pushToast(databaseError.value);
        tables.value = [];
        databaseSummary.value = null;
    } finally {
        loadingDatabase.value = false;
        loadingTable.value = false;
    }
};

const loadTable = async (database, table, options = {}) => {
    if (!database || !table) return;

    selectedDatabase.value = database;
    selectedTable.value = table;
    loadingTable.value = true;
    tableError.value = '';
    if (options.action) {
        activeTableAction.value = options.action;
    }

    if (options.page) {
        selectedTablePage.value = options.page;
    }
    if (options.perPage) {
        perPage.value = options.perPage;
    }

    try {
        if (options.reusePayload) {
            tableDetails.value = options.reusePayload;
            return;
        }

        const response = await fetch(panelRoute('phpmyadmin.table', {
            database,
            table,
            page: options.page || selectedTablePage.value || 1,
            perPage: options.perPage || perPage.value || 25,
        }), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        const data = await safeJson(response);
        if (!response.ok || !data?.ok) {
            tableError.value = data?.message || 'Failed to load table rows.';
            pushToast(tableError.value);
            tableDetails.value = null;
            return;
        }

        tableDetails.value = data.table_details || null;
    } catch (error) {
        tableError.value = error?.message || 'Failed to load table rows.';
        pushToast(tableError.value);
        tableDetails.value = null;
    } finally {
        loadingTable.value = false;
    }
};

const resetView = async () => {
    selectedTable.value = '';
    selectedTablePage.value = 1;
    tableDetails.value = null;
    overviewSqlFullscreen.value = false;
    if (selectedDatabase.value) {
        await loadDatabase(selectedDatabase.value, { page: 1, perPage: perPage.value, loadRows: false });
    }
};

const handleSelectDatabase = async (database) => {
    await toggleDatabase(database);
};

const handleSelectTable = async ({ database, table }) => {
    if (!database || !table) return;
    if (!expandedDatabases.value.includes(database)) {
        await toggleDatabase(database, true);
    }
    activeTableAction.value = 'browse';
    await loadTable(database, table, { page: 1, perPage: perPage.value, action: 'browse' });
};

const handlePaginate = async (page) => {
    if (!selectedDatabase.value || !selectedTable.value) return;
    selectedTablePage.value = page;
    await loadTable(selectedDatabase.value, selectedTable.value, { page, perPage: perPage.value });
};

const handleAction = async ({ action, table }) => {
    if (!table) return;
    activeTableAction.value = action;

    if (action === 'structure') {
        await loadTable(selectedDatabase.value, table, {
            page: selectedTablePage.value || 1,
            perPage: perPage.value,
            action: 'structure',
        });
        return;
    }

    if (action === 'browse') {
        await loadTable(selectedDatabase.value, table, {
            page: selectedTablePage.value || 1,
            perPage: perPage.value,
            action: 'browse',
        });
        return;
    }

    if (['search', 'insert'].includes(action)) {
        await handleSelectTable({ database: selectedDatabase.value, table });
    }
};

const handleToolbarAction = async (action) => {
    if (!selectedDatabase.value && overviewMode.value === 'sql') {
        return;
    }

    const table = selectedTable.value || tables.value[0]?.name || '';
    if (!table) {
        if (action === 'sql') {
            overviewMode.value = 'sql';
        }
        return;
    }

    if (action === 'sql') {
        overviewMode.value = 'sql';
        return;
    }

    if (action === 'structure') {
        await loadTable(selectedDatabase.value, table, {
            page: selectedTablePage.value || 1,
            perPage: perPage.value,
            action: 'structure',
        });
        return;
    }

    if (action === 'browse') {
        await loadTable(selectedDatabase.value, table, {
            page: selectedTablePage.value || 1,
            perPage: perPage.value,
            action: 'browse',
        });
        return;
    }

    if (action === 'search' || action === 'insert') {
        await loadTable(selectedDatabase.value, table, {
            page: selectedTablePage.value || 1,
            perPage: perPage.value,
            action,
        });
    }
};

const handleSidebarFilterChange = (value) => {
    sidebarFilter.value = value;
};

const handleOverviewSelect = (tab) => {
    if (tab === 'Databases') {
        overviewMode.value = 'databases';
        overviewSqlFullscreen.value = false;
        return;
    }

    if (tab === 'SQL') {
        overviewMode.value = 'sql';
        return;
    }

    overviewMode.value = 'about';
    overviewSqlFullscreen.value = false;
};

const handleSelectDatabaseFromSummary = async (database) => {
    overviewMode.value = 'about';
    overviewSqlFullscreen.value = false;
    await toggleDatabase(database, true);
};

const handleOverviewSqlExecuted = () => {
    overviewSqlFullscreen.value = true;
};

const toggleDatabase = async (database, forceOpen = false) => {
    if (!database) return;

    const index = expandedDatabases.value.indexOf(database);
    const isOpen = index !== -1;

    if (isOpen && !forceOpen) {
        expandedDatabases.value = expandedDatabases.value.filter((name) => name !== database);
        if (selectedDatabase.value === database) {
            selectedDatabase.value = '';
            selectedTable.value = '';
            tableDetails.value = null;
            databaseSummary.value = null;
            tables.value = [];
        }
        return;
    }

    if (!isOpen) {
        expandedDatabases.value = [...expandedDatabases.value, database];
    }

    selectedTable.value = '';
    tableDetails.value = null;

    if (!databaseCache.value[database]) {
        await loadDatabase(database, {
            page: 1,
            perPage: perPage.value,
            loadRows: false,
            selectDatabase: true,
        });
        return;
    }

    selectedDatabase.value = database;
    databaseSummary.value = databaseCache.value[database].summary || null;
    tables.value = databaseCache.value[database].tables || [];
    selectedTable.value = '';
    tableDetails.value = null;
};

onMounted(() => {
    loadSplitWidth();
    void loadDatabases();
});
</script>

<template>
    <Head title="Database Studio" />

    <DatabaseStudioLayout
        :server="server"
        :header-mode="headerMode"
        :overview-active-tab="selectedDatabase ? 'about' : (overviewMode === 'sql' ? 'SQL' : overviewMode === 'databases' ? 'Databases' : 'About')"
        @overview-select="handleOverviewSelect"
        @toolbar-action="handleToolbarAction"
    >
        <div class="flex h-full min-h-0 flex-col gap-0 overflow-hidden">
            <div v-if="databaseError" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-300">
                {{ databaseError }}
            </div>

            <div ref="splitRow" class="flex min-h-0 flex-1 overflow-hidden">
                <div
                    v-if="!(overviewMode === 'sql' && overviewSqlFullscreen)"
                    class="min-h-0 shrink-0 overflow-hidden"
                    :style="{ flexBasis: `${splitWidth}%` }"
                >
                    <DatabaseSidebar
                        :databases="databases"
                        :expanded-databases="expandedDatabases"
                        :selected-database="selectedDatabase"
                        :selected-table="selectedTable"
                        :tables-by-database="tablesByDatabase"
                        :filter-text="sidebarFilter"
                        :loading="loadingDatabases"
                        @toggle-database="toggleDatabase"
                        @select-table="handleSelectTable"
                        @filter-change="handleSidebarFilterChange"
                    />
                </div>

                <button
                    v-if="!(overviewMode === 'sql' && overviewSqlFullscreen)"
                    type="button"
                    class="hidden w-1 shrink-0 cursor-col-resize bg-slate-200 transition hover:bg-cyan-400 dark:bg-slate-700 dark:hover:bg-cyan-500 lg:block"
                    title="Drag to resize panels"
                    aria-label="Resize panels"
                    @pointerdown="startResize"
                ></button>

                <div class="min-h-0 flex-1 overflow-hidden">
                    <div v-if="overviewMode === 'sql' && overviewSqlFullscreen" class="mb-2 flex justify-end">
                        <button
                            type="button"
                            class="rounded-full border border-slate-300 bg-slate-50 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-100 dark:border-slate-700 dark:bg-slate-950/40 dark:text-slate-200 dark:hover:bg-slate-800"
                            @click="overviewSqlFullscreen = false"
                        >
                            Show sidebar
                        </button>
                    </div>

                    <SqlConsole
                        v-if="!selectedDatabase && overviewMode === 'sql'"
                        :panel-route="panelRoute"
                        :selected-database="server?.current_database || ''"
                        :initial-sql="queryDefaults?.sql || 'SHOW TABLES;'"
                        :schema-tables="currentDatabaseTables"
                        :plain="true"
                        :auto-focus="true"
                        :notify="pushToast"
                        @executed="handleOverviewSqlExecuted"
                    />
                    <DatabaseSummary
                        :server="server"
                        :databases="databases"
                        v-else-if="!selectedTable"
                        :database-summary="databaseSummary"
                        :selected-database="selectedDatabase"
                        :selected-table="selectedTable"
                        :tables="currentDatabaseTables"
                        :format-bytes="formatBytes"
                        :loading="loadingDatabase || loadingDatabases"
                        :sql-href="sqlHref"
                        :plain="true"
                        :overview-mode="overviewMode"
                        @select-table="handleSelectTable"
                        @select-database="handleSelectDatabaseFromSummary"
                        @reset="resetView"
                        @action="handleAction"
                    />
                    <TableBrowser
                        v-else
                        :table-details="tableDetails"
                        :selected-database="selectedDatabase"
                        :selected-table="selectedTable"
                        :loading="loadingTable"
                        :error="tableError"
                        :active-action="activeTableAction"
                        :plain="true"
                        @paginate="handlePaginate"
                    />
                </div>
            </div>
        </div>
        <PhpMyAdminToastStack :toasts="toasts" @dismiss="removeToast" />
    </DatabaseStudioLayout>
</template>
