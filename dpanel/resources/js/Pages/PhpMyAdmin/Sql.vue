<script setup>
import { Head, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import DatabaseStudioLayout from '@/Layouts/DatabaseStudioLayout.vue';
import DatabaseSidebar from './Components/DatabaseSidebar.vue';
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

const databases = ref([]);
const selectedDatabase = ref(String(props.initialSelection?.database || ''));
const selectedTable = ref(String(props.initialSelection?.table || ''));
const expandedDatabases = ref([]);
const databaseCache = ref({});
const loadingDatabases = ref(false);
const loadingDatabase = ref(false);
const databaseError = ref('');
const sidebarFilter = ref('');
const querySql = ref(String(props.queryDefaults?.sql || ''));
const toasts = ref([]);
const consoleFullscreen = ref(false);
let toastSeq = 0;

const tablesByDatabase = computed(() => databaseCache.value);
const currentDatabaseTables = computed(() => databaseCache.value[selectedDatabase.value]?.tables || []);
const currentDatabase = computed(() => selectedDatabase.value || props.server?.current_database || '');
const mainHref = computed(() => panelRoute('phpmyadmin.index'));

const safeJson = async (response) => response.json().catch(() => ({}));

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

const loadDatabase = async (database) => {
    if (!database) return;

    loadingDatabase.value = true;
    databaseError.value = '';
    selectedDatabase.value = database;

    try {
        const response = await fetch(panelRoute('phpmyadmin.database', {
            database,
            page: 1,
            perPage: 25,
        }), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        const data = await safeJson(response);
        if (!response.ok || !data?.ok) {
            databaseError.value = data?.message || 'Failed to load database details.';
            pushToast(databaseError.value);
            return;
        }

        databaseCache.value = {
            ...databaseCache.value,
            [database]: {
                summary: data.summary || null,
                tables: Array.isArray(data.tables) ? data.tables : [],
            },
        };

        if (!selectedTable.value) {
            querySql.value = String(data.query_defaults?.sql || querySql.value || '');
        }
    } catch (error) {
        databaseError.value = error?.message || 'Failed to load database details.';
        pushToast(databaseError.value);
    } finally {
        loadingDatabase.value = false;
    }
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
        }
        return;
    }

    if (!isOpen) {
        expandedDatabases.value = [...expandedDatabases.value, database];
    }

    await loadDatabase(database);
};

const handleSelectDatabase = async (database) => {
    consoleFullscreen.value = false;
    await toggleDatabase(database);
};

const handleSelectTable = async ({ database, table }) => {
    if (!database || !table) return;

    if (!expandedDatabases.value.includes(database)) {
        await toggleDatabase(database, true);
    }

    selectedDatabase.value = database;
    selectedTable.value = table;
    consoleFullscreen.value = false;
    querySql.value = `SELECT * FROM \`${database}\`.\`${table}\` LIMIT 25;`;
};

const handleSidebarFilterChange = (value) => {
    sidebarFilter.value = value;
};

const handleExecuted = () => {
    consoleFullscreen.value = true;
};

const handleToolbarAction = (action) => {
    if (action === 'sql') {
        consoleFullscreen.value = false;
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

onMounted(() => {
    void loadDatabases();

    if (selectedDatabase.value) {
        expandedDatabases.value = [selectedDatabase.value];
        void loadDatabase(selectedDatabase.value);
    }
});
</script>

<template>
    <Head title="SQL" />

    <DatabaseStudioLayout :server="server" @toolbar-action="handleToolbarAction">
        <div class="flex h-full min-h-0 flex-col gap-4 overflow-hidden">
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
                <div v-if="!consoleFullscreen" class="min-h-0 lg:col-span-4">
                    <DatabaseSidebar
                        :databases="databases"
                        :expanded-databases="expandedDatabases"
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

                <div class="min-h-0" :class="consoleFullscreen ? 'lg:col-span-12' : 'lg:col-span-8'">
                    <div v-if="consoleFullscreen" class="mb-2 flex justify-end">
                        <button
                            type="button"
                            class="rounded-full border border-slate-300 bg-slate-50 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-100 dark:border-slate-700 dark:bg-slate-950/40 dark:text-slate-200 dark:hover:bg-slate-800"
                            @click="consoleFullscreen = false"
                        >
                            Show sidebar
                        </button>
                    </div>

                    <SqlConsole
                        :panel-route="panelRoute"
                        :selected-database="selectedDatabase"
                        :initial-sql="querySql"
                        :schema-tables="currentDatabaseTables"
                        :plain="true"
                        :auto-focus="true"
                        :notify="pushToast"
                        @executed="handleExecuted"
                    />
                </div>
            </div>
        </div>
        <PhpMyAdminToastStack :toasts="toasts" @dismiss="removeToast" />
    </DatabaseStudioLayout>
</template>
