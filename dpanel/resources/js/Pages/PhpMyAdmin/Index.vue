<script setup>
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DatabaseStudioLayout from '@/Layouts/DatabaseStudioLayout.vue';
import DatabaseSidebar from './Components/DatabaseSidebar.vue';
import DatabaseSummary from './Components/DatabaseSummary.vue';
import DatabaseTopbarMenu from './Components/DatabaseTopbarMenu.vue';
import ImportExportPanel from './Components/ImportExportPanel.vue';
import TableBrowser from './Components/TableBrowser.vue';
import TableSchemaDesigner from './Components/TableSchemaDesigner.vue';
import SqlConsole from './Components/SqlConsole.vue';
import Sqlhistory from './Components/Sqlhistory.vue';
import PhpMyAdminToastStack from './Components/PhpMyAdminToastStack.vue';
import Modal from '@/Components/Modal.vue';
import { formatBytes } from './helpers/phpMyAdminSql.js';
import { usePhpMyAdminTransport } from './composables/usePhpMyAdminTransport.js';
import { usePhpMyAdminReadState } from './composables/usePhpMyAdminReadState.js';
import { usePhpMyAdminWriteState } from './composables/usePhpMyAdminWriteState.js';

const props = defineProps({
    panelToken: {
        type: String,
        default: '',
    },
    server: {
        type: Object,
        default: () => ({}),
    },
    accessControl: {
        type: Object,
        default: () => ({ mode: 'scoped', databases: [] }),
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
const writeState = usePhpMyAdminWriteState(readState, transport);

const {
    panelRoute,
    toasts,
    pushToast,
    removeToast,
} = transport;
const dashboardHref = panelRoute('dashboard');
const logoutHref = panelRoute('logout');

const {
    databases,
    selectedDatabase,
    selectedTable,
    databaseSummary,
    tables,
    tableDetails,
    tableQueryMeta,
    sortColumn,
    sortDirection,
    activeTableAction,
    loadingDatabases,
    loadingDatabase,
    loadingTable,
    databaseError,
    tableError,
    perPage,
    selectedTablePage,
    expandedDatabases,
    sidebarFilter,
    overviewMode,
    historyOpenTrigger,
    theme,
    splitWidth,
    splitRow,
    currentDatabaseTables,
    tablesByDatabase,
    headerMode,
    topbarActiveAction,
    overviewActiveTab,
    toggleTheme,
    startResize,
    isDatabaseExpanded,
    toggleDatabaseExpanded,
    loadTable,
    resetView,
    handleSelectDatabase,
    handleSelectTable,
    handleSidebarFilterChange,
    handleSelectDatabaseFromSummary,
    handleSidebarToggleDatabase,
    handlePaginate,
    handlePerPageChange,
    handleSortChange,
    loadDatabase,
    handleToolbarAction: readToolbarAction,
    loadDatabases,
} = readState;

const querySql = ref(String(props.queryDefaults?.sql || 'SHOW TABLES;'));
const sqlHistoryEntries = ref([]);
const transferActiveTab = ref('export');
const schemaEditorMode = ref('');
const schemaEditorColumns = ref([]);
const tableStructureColumns = computed(() => Array.isArray(tableDetails.value?.columns) ? tableDetails.value.columns : []);
const topbarActionDisplay = computed(() => (schemaEditorMode.value === 'create' ? 'create' : topbarActiveAction.value));

const {
    dropConfirmOpen,
    confirmAction,
    dropTarget,
    dropInProgress,
    renameInProgress,
    createInProgress,
    openConfirm,
    closeDropConfirm,
    confirmTableMutation,
    handleRowSave,
    handleRowDelete,
    handleBulkDelete,
    handleBulkTableAction,
    handleInsertSubmit,
    handleTableRename,
    handleTableCreate,
    handleTableStructureSave,
} = writeState;

const handleAction = async ({ action, table }) => {
    if (!table) return;
    const shouldResetSort = String(table || '') !== String(selectedTable.value || '');

    if (action === 'drop') {
        openConfirm('drop', table);
        return;
    }

    if (action === 'empty') {
        openConfirm('empty', table);
        return;
    }

    if (action === 'search') {
        if (shouldResetSort) {
            sortColumn.value = '';
            sortDirection.value = 'asc';
        }
        await handleSelectTable({ database: selectedDatabase.value, table });
        return;
    }

    if (action === 'select' || action === 'update') {
        if (shouldResetSort) {
            sortColumn.value = '';
            sortDirection.value = 'asc';
        }
        await loadTable(selectedDatabase.value, table, {
            page: selectedTablePage.value || 1,
            perPage: perPage.value,
            action: 'browse',
        });
        return;
    }

    if (action === 'structure' || action === 'browse' || action === 'insert') {
        if (shouldResetSort) {
            sortColumn.value = '';
            sortDirection.value = 'asc';
        }
        await loadTable(selectedDatabase.value, table, {
            page: selectedTablePage.value || 1,
            perPage: perPage.value,
            action,
        });
    }
};

const handleHistoryUpdated = (entries) => {
    sqlHistoryEntries.value = Array.isArray(entries) ? entries : [];
};

const handleUseHistoryEntry = (entry) => {
    overviewMode.value = 'sql';
    querySql.value = String(entry?.sql || '');
};

const handleTransferCompleted = async ({ type, database } = {}) => {
    if (type !== 'import') {
        return;
    }

    await loadDatabases();

    if (database && selectedDatabase.value === database) {
        await loadDatabase(database, {
            page: 1,
            perPage: perPage.value,
            loadRows: Boolean(selectedTable.value),
            table: selectedTable.value,
            selectDatabase: true,
            action: selectedTable.value ? 'browse' : 'structure',
        });
    }
};

const handleToolbarAction = async (action) => {
    if (action === 'import' || action === 'export') {
        transferActiveTab.value = action;
        overviewMode.value = 'transfer';
        schemaEditorMode.value = '';
        return;
    }

    if (action === 'create') {
        if (!selectedDatabase.value) {
            pushToast('Select a database before creating a table.', 'error');
            return;
        }

        activeTableAction.value = 'create';
        schemaEditorMode.value = 'create';
        overviewMode.value = 'about';
        return;
    }

    if (action === 'sql') {
        overviewMode.value = 'sql';
        schemaEditorMode.value = '';
        return;
    }

    if (action === 'browse' || action === 'structure' || action === 'insert' || action === 'operations' || action === 'search') {
        overviewMode.value = 'about';
        schemaEditorMode.value = '';
        await readToolbarAction(action);
        return;
    }

    await readToolbarAction(action);
};

const handleOpenStructureEditor = ({ action = 'change', column } = {}) => {
    const sourceColumns = Array.isArray(tableStructureColumns.value) ? tableStructureColumns.value : [];
    schemaEditorColumns.value = sourceColumns.map((item) => ({
        ...item,
        remove: action === 'drop' && String(item?.name || '') === String(column || '') ? true : Boolean(item?.remove),
    }));
    schemaEditorMode.value = 'edit';
    overviewMode.value = 'about';
};

const handleCloseSchemaEditor = () => {
    schemaEditorMode.value = '';
    schemaEditorColumns.value = [];
    activeTableAction.value = selectedTable.value ? 'structure' : 'browse';
};

const handleSchemaSave = async (payload = {}) => {
    if (payload.mode === 'edit') {
        await handleTableStructureSave(payload);
    } else {
        await handleTableCreate(payload);
    }

    schemaEditorMode.value = '';
    schemaEditorColumns.value = [];
};

const handleOverviewSelect = async (tab) => {
    schemaEditorMode.value = '';

    if (tab === 'SQL') {
        overviewMode.value = 'sql';
        return;
    }

    if (tab === 'Transfer') {
        transferActiveTab.value = 'export';
        overviewMode.value = 'transfer';
        return;
    }

    if (tab === 'Databases') {
        overviewMode.value = 'databases';
        return;
    }

    if (tab === 'Status') {
        pushToast('Status page coming soon.', 'info');
        return;
    }

    if (tab === 'User accounts') {
        pushToast('User accounts page coming soon.', 'info');
        return;
    }

    if (tab === 'Settings') {
        pushToast('Settings page coming soon.', 'info');
        return;
    }

    if (tab === 'Replication') {
        pushToast('Replication page coming soon.', 'info');
        return;
    }

    if (tab === 'Variables') {
        pushToast('Variables page coming soon.', 'info');
        return;
    }

    if (tab === 'Charsets') {
        pushToast('Charsets page coming soon.', 'info');
        return;
    }

    overviewMode.value = 'about';
};
</script>

<template>
    <Head title="Database Studio" />

    <DatabaseStudioLayout :server="server">
        <template #sidebar>
            <DatabaseSidebar
                :databases="databases"
                :selected-database="selectedDatabase"
                :selected-table="selectedTable"
                :expanded-databases="Array.from(expandedDatabases)"
                :tables-by-database="tablesByDatabase"
                :filter-text="sidebarFilter"
                :loading="loadingDatabases"
                @select-database="handleSelectDatabase"
                @select-table="handleSelectTable"
                @filter-change="handleSidebarFilterChange"
                @toggle-database="handleSidebarToggleDatabase"
                @table-action="handleAction"
            />
        </template>

        <template #navigation>
            <DatabaseTopbarMenu
                :server="server"
                :selected-database="selectedDatabase"
                :header-mode="headerMode"
                :overview-active-tab="overviewActiveTab"
                :active-action="topbarActionDisplay"
                :theme="theme"
                :dashboard-href="dashboardHref"
                :logout-href="logoutHref"
                @toggle-theme="toggleTheme"
                @overview-select="handleOverviewSelect"
                @toolbar-action="handleToolbarAction"
            />
        </template>

        <div class="relative flex h-full min-h-0 flex-col overflow-hidden bg-[#070b16]">
            <!-- Resize Handle -->
            <button
                type="button"
                class="hidden w-1 shrink-0 cursor-col-resize bg-slate-700 transition-colors hover:bg-cyan-500 lg:block"
                title="Drag to resize panels"
                aria-label="Resize panels"
                @pointerdown="startResize"
            ></button>

            <!-- Main Content -->
            <div class="relative flex min-h-0 flex-1 flex-col overflow-hidden bg-[#070b16]">
                <!-- Error Message -->
                <div v-if="databaseError" class="mx-4 mt-4 rounded-2xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-200">
                    {{ databaseError }}
                </div>

                <!-- Content Area -->
                <div
                    class="flex-1  overflow-auto px-4 py-4 sm:px-5"
                    :style="{ paddingBottom: 'calc(var(--phpmyadmin-sql-history-height, 0px) + 1rem)' }"
                >
                    <Transition name="content-fade" mode="out-in">
                        <!-- Transfer Panel -->
                        <ImportExportPanel
                            v-if="overviewMode === 'transfer'"
                            :key="'transfer'"
                            :panel-token="panelToken"
                            :databases="databases"
                            :tables="currentDatabaseTables"
                            :selected-database="selectedDatabase || server?.current_database || ''"
                            :initial-tab="transferActiveTab"
                            :notify="pushToast"
                            @completed="handleTransferCompleted"
                        />

                        <!-- SQL Console -->
                        <SqlConsole
                            v-else-if="overviewMode === 'sql'"
                            :key="'sql'"
                            :panel-route="panelRoute"
                            :selected-database="selectedDatabase || server?.current_database || ''"
                            :selected-table="selectedTable"
                            :initial-sql="querySql"
                            :schema-tables="currentDatabaseTables"
                            :history-open-trigger="historyOpenTrigger"
                            :plain="true"
                            :auto-focus="true"
                            :notify="pushToast"
                            @history-updated="handleHistoryUpdated"
                        />

                        <!-- Schema Designer -->
                        <TableSchemaDesigner
                            v-else-if="schemaEditorMode === 'create' || schemaEditorMode === 'edit'"
                            :key="'schema-' + schemaEditorMode"
                            :mode="schemaEditorMode"
                            :selected-database="selectedDatabase"
                            :selected-table="selectedTable"
                            :initial-columns="schemaEditorMode === 'edit' ? schemaEditorColumns : []"
                            :save-busy="createInProgress"
                            @save="handleSchemaSave"
                            @cancel="handleCloseSchemaEditor"
                        />

                        <!-- Database Summary -->
                        <DatabaseSummary
                            v-else-if="!selectedTable"
                            :key="'summary-' + (selectedDatabase || 'none')"
                            :server="server"
                            :databases="databases"
                            :database-summary="databaseSummary"
                            :selected-database="selectedDatabase"
                            :selected-table="selectedTable"
                            :tables="currentDatabaseTables"
                            :format-bytes="formatBytes"
                            :loading="loadingDatabase || loadingDatabases"
                            :plain="true"
                            :overview-mode="overviewMode"
                            @select-table="handleSelectTable"
                            @select-database="handleSelectDatabaseFromSummary"
                            @reset="resetView"
                            @action="handleAction"
                            @bulk-action="handleBulkTableAction"
                        />

                        <!-- Table Browser -->
                        <TableBrowser
                            v-else
                            :key="'browse-' + (selectedTable || 'none')"
                            :table-details="tableDetails"
                            :selected-database="selectedDatabase"
                            :selected-table="selectedTable"
                            :loading="loadingTable"
                            :error="tableError"
                            :active-action="activeTableAction"
                            :query-label="tableQueryMeta.label"
                            :query-duration-ms="tableQueryMeta.durationMs"
                            :rows-per-page="perPage"
                            :sort-column="sortColumn"
                            :sort-direction="sortDirection"
                            :rename-busy="renameInProgress"
                            :plain="true"
                            @paginate="handlePaginate"
                            @per-page-change="handlePerPageChange"
                            @sort-change="handleSortChange"
                            @bulk-delete="handleBulkDelete"
                            @row-delete="handleRowDelete"
                            @row-save="handleRowSave"
                            @insert-submit="handleInsertSubmit"
                            @table-rename="handleTableRename"
                            @edit-structure="handleOpenStructureEditor"
                        />
                    </Transition>
                </div>
            </div>
        </div>

        <!-- SQL History -->
        <Sqlhistory
            :history-entries="sqlHistoryEntries"
            @use-entry="handleUseHistoryEntry"
        />

        <!-- Toast Notifications -->
        <PhpMyAdminToastStack :toasts="toasts" @dismiss="removeToast" />

        <!-- Drop/Empty Confirmation Modal -->
        <Modal :show="dropConfirmOpen" max-width="lg" @close="closeDropConfirm">
            <div class="border-b border-slate-200 px-6 py-4 dark:border-slate-700">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">
                    {{ confirmAction === 'empty' ? 'Empty Table' : 'Drop Table' }}
                </h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    {{ confirmAction === 'empty'
                        ? 'This action will remove all rows but keep the table structure.'
                        : 'This action will permanently remove the table and all of its data.' }}
                </p>
            </div>

            <div class="px-6 py-5">
                <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-800 dark:bg-red-900/30 dark:text-red-300">
                    <p class="font-semibold">
                        Are you sure you want to {{ confirmAction === 'empty' ? 'empty' : 'drop' }} this table?
                    </p>
                    <p class="mt-1 break-words">
                        <span class="font-medium">{{ dropTarget.database }}</span>.<span class="font-medium">{{ dropTarget.table }}</span>
                    </p>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 border-t border-slate-200 px-6 py-4 dark:border-slate-700">
                <button
                    type="button"
                    class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-50 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-800"
                    :disabled="dropInProgress"
                    @click="closeDropConfirm"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="dropInProgress"
                    @click="confirmTableMutation"
                >
                    {{ dropInProgress ? (confirmAction === 'empty' ? 'Emptying...' : 'Dropping...') : (confirmAction === 'empty' ? 'Empty Table' : 'Drop Table') }}
                </button>
            </div>
        </Modal>
    </DatabaseStudioLayout>
</template>

<style scoped>
.content-fade-enter-active,
.content-fade-leave-active {
    transition: opacity 0.2s ease, transform 0.2s ease;
}

.content-fade-enter-from {
    opacity: 0;
    transform: translateY(8px);
}

.content-fade-leave-to {
    opacity: 0;
    transform: translateY(-8px);
}
</style>
