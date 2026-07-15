<script setup>
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DatabaseStudioLayout from '@/Layouts/DatabaseStudioLayout.vue';
import DatabaseSidebar from './Components/DatabaseSidebar.vue';
import DatabaseTopbarMenu from './Components/DatabaseTopbarMenu.vue';
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
    loadingDatabases,
    databaseError,
    sidebarFilter,
    theme,
    splitWidth,
    splitRow,
    tablesByDatabase,
    headerMode,
    overviewActiveTab,
    toggleTheme,
    startResize,
    handleSelectDatabase,
    handleSelectTable,
    handleSidebarFilterChange,
    handleSidebarToggleDatabase,
    loadDatabases,
} = readState;

const dashboardHref = panelRoute('dashboard');
const logoutHref = panelRoute('logout');
const querySql = ref(String(props.queryDefaults?.sql || 'SELECT * FROM '));
const sqlHistoryEntries = ref([]);

const handleHistoryUpdated = (entries) => {
    sqlHistoryEntries.value = Array.isArray(entries) ? entries : [];
};

const handleUseHistoryEntry = (entry) => {
    querySql.value = String(entry?.sql || '');
};

const handleOverviewSelect = (tab) => {
    // SQL page doesn't need overview tabs
};
</script>

<template>
    <Head title="SQL Query" />

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
            />
        </template>

        <template #navigation>
            <DatabaseTopbarMenu
                :server="server"
                :selected-database="selectedDatabase"
                :header-mode="'table'"
                :overview-active-tab="overviewActiveTab"
                :active-action="'sql'"
                :theme="theme"
                :dashboard-href="dashboardHref"
                :logout-href="logoutHref"
                @toggle-theme="toggleTheme"
                @overview-select="handleOverviewSelect"
                @toolbar-action="() => {}"
            />
        </template>

        <div class="relative flex h-full min-h-0 flex-col overflow-hidden bg-[#070b16]">
            <!-- Resize Handle -->
            <button
                type="button"
                class="hidden w-1 shrink-0 cursor-col-resize bg-slate-200 transition-colors hover:bg-blue-400 dark:bg-slate-700 dark:hover:bg-blue-500 lg:block"
                title="Drag to resize panels"
                aria-label="Resize panels"
                @pointerdown="startResize"
            ></button>

            <!-- Main Content -->
            <div class="relative flex min-h-0 flex-1 flex-col overflow-hidden">
                <!-- Error Message -->
                <div v-if="databaseError" class="mx-4 mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/30 dark:text-red-400">
                    {{ databaseError }}
                </div>

                <!-- SQL Console -->
                <div class="flex-1 overflow-x-hidden overflow-y-auto p-4">
                    <SqlConsole
                        :panel-route="panelRoute"
                        :selected-database="selectedDatabase || server?.current_database || ''"
                        :selected-table="selectedTable"
                        :initial-sql="querySql"
                        :schema-tables="[]"
                        :history-open-trigger="0"
                        :plain="true"
                        :auto-focus="true"
                        :notify="pushToast"
                        @history-updated="handleHistoryUpdated"
                    />
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
    </DatabaseStudioLayout>
</template>
