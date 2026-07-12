<script setup>
import { Head, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import DatabaseStudioLayout from '@/Layouts/DatabaseStudioLayout.vue';
import DatabaseSidebar from './Components/DatabaseSidebar.vue';
import DatabaseSummary from './Components/DatabaseSummary.vue';
import DatabaseTopbarMenu from './Components/DatabaseTopbarMenu.vue';
import TableBrowser from './Components/TableBrowser.vue';
import SqlConsole from './Components/SqlConsole.vue';
import PhpMyAdminToastStack from './Components/PhpMyAdminToastStack.vue';
import Modal from '@/Components/Modal.vue';

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
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
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

const escapeSqlValue = (value) => {
    if (value === null || value === undefined) {
        return 'NULL';
    }

    const text = String(value);
    if (text.trim().toUpperCase() === 'NULL') {
        return 'NULL';
    }

    return `'${text.replaceAll("'", "''")}'`;
};

const buildInsertSql = (database, table, rows) => {
    const normalizedRows = Array.isArray(rows) ? rows : [];
    const entries = normalizedRows
        .map((row) => {
            const name = String(row?.name || '').trim();
            if (!name) return null;

            if (row.useNull) {
                return { name, value: 'NULL', raw: true };
            }

            const rawValue = String(row?.value ?? '').trim();
            if (rawValue === '' && !row.function) {
                return null;
            }

            if (row.function === 'NOW' || row.function === 'CURRENT_TIMESTAMP') {
                return { name, value: row.function === 'NOW' ? 'NOW()' : 'CURRENT_TIMESTAMP', raw: true };
            }

            let transformed = String(row?.value ?? '');
            if (row.function === 'LOWER') transformed = transformed.toLowerCase();
            if (row.function === 'UPPER') transformed = transformed.toUpperCase();

            return { name, value: transformed, raw: false };
        })
        .filter(Boolean);

    if (entries.length === 0) {
        throw new Error('Please enter at least one value before inserting.');
    }

    const columns = entries.map((entry) => `\`${entry.name}\``).join(', ');
    const payload = entries.map((entry) => (entry.raw ? entry.value : escapeSqlValue(entry.value))).join(', ');

    return `INSERT INTO \`${database}\`.\`${table}\` (${columns}) VALUES (${payload});`;
};

const quoteIdentifier = (value) => `\`${String(value).replaceAll('`', '``')}\``;

const buildTableQueryLabel = (database, table, page, limit, action = 'browse') => {
    if (!database || !table) return '';

    if (action === 'structure') {
        return `DESCRIBE ${quoteIdentifier(database)}.${quoteIdentifier(table)};`;
    }

    const safeLimit = Math.max(1, Number(limit || 25));
    const safePage = Math.max(1, Number(page || 1));
    const offset = (safePage - 1) * safeLimit;

    return `SELECT * FROM ${quoteIdentifier(database)}.${quoteIdentifier(table)} LIMIT ${safeLimit} OFFSET ${offset};`;
};

const buildRowCondition = (row, columns) => {
    const primaryColumns = (columns || []).filter((column) => column.is_primary);
    if (primaryColumns.length === 0) {
        throw new Error('This table needs a primary key for row actions.');
    }

    return primaryColumns.map((column) => {
        const value = row?.[column.name];
        if (value === null || value === undefined) {
            return `${quoteIdentifier(column.name)} IS NULL`;
        }

        return `${quoteIdentifier(column.name)} = ${escapeSqlValue(value)}`;
    }).join(' AND ');
};

const buildUpdateSql = (database, table, original, draft, columns) => {
    const editableColumns = (columns || []).filter((column) => !column.is_primary && !String(column.extra || '').toLowerCase().includes('auto_increment'));
    const changes = editableColumns
        .map((column) => {
            const nextValue = draft?.[column.name];
            const currentValue = original?.[column.name];
            if (String(nextValue ?? '') === String(currentValue ?? '')) {
                return null;
            }
            return `${quoteIdentifier(column.name)} = ${escapeSqlValue(nextValue)}`;
        })
        .filter(Boolean);

    if (changes.length === 0) {
        throw new Error('No changes to save.');
    }

    return `UPDATE ${quoteIdentifier(database)}.${quoteIdentifier(table)} SET ${changes.join(', ')} WHERE ${buildRowCondition(original, columns)} LIMIT 1;`;
};

const buildDeleteSql = (database, table, row, columns) => (
    `DELETE FROM ${quoteIdentifier(database)}.${quoteIdentifier(table)} WHERE ${buildRowCondition(row, columns)} LIMIT 1;`
);

const runExecuteSql = async (sql) => {
    const response = await fetch(panelRoute('phpmyadmin.execute'), {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        body: JSON.stringify({
            sql,
            database: selectedDatabase.value,
        }),
    });

    const data = await safeJson(response);
    if (!response.ok || !data?.ok) {
        throw new Error(data?.message || 'Query execution failed.');
    }

    return data;
};

const databases = ref([]);
const selectedDatabase = ref('');
const selectedTable = ref('');
const databaseSummary = ref(null);
const tables = ref([]);
const tableDetails = ref(null);
const tableQueryMeta = ref({ label: '', durationMs: 0 });
const activeTableAction = ref('browse');
const loadingDatabases = ref(false);
const loadingDatabase = ref(false);
const loadingTable = ref(false);
const databaseError = ref('');
const tableError = ref('');
const pageNumber = ref(Number(props.initialSelection?.page || 1));
const perPage = ref(Number(props.initialSelection?.perPage || 25));
const selectedTablePage = ref(Number(props.initialSelection?.page || 1));
const databaseCache = ref({});
const expandedDatabases = ref(new Set());
const sidebarFilter = ref('');
const toasts = ref([]);
const dropConfirmOpen = ref(false);
const confirmAction = ref('drop');
const dropTarget = ref({ database: '', table: '' });
const dropInProgress = ref(false);
const splitRow = ref(null);
const splitWidth = ref(34);
const isResizing = ref(false);
const overviewMode = ref('about');
const overviewSqlFullscreen = ref(false);
const historyOpenTrigger = ref(0);
const theme = ref('light');
let toastSeq = 0;
const SPLIT_KEY = 'serverpanel-phpmyadmin-split-width';
const SPLIT_MIN = 24;
const SPLIT_MAX = 58;
const THEME_KEY = 'serverpanel-theme';
const tablesByDatabase = computed(() => databaseCache.value);
const currentDatabaseTables = computed(() => tables.value);
const headerMode = computed(() => (selectedDatabase.value ? 'compact' : 'overview'));
const topbarActiveAction = computed(() => (overviewMode.value === 'sql' ? 'sql' : activeTableAction.value));
const overviewActiveTab = computed(() => (
    overviewMode.value === 'sql'
        ? 'SQL'
        : selectedDatabase.value
            ? 'about'
            : (overviewMode.value === 'databases'
                ? 'Databases'
                : 'About')
));
const isDatabaseExpanded = (database) => expandedDatabases.value.has(String(database || ''));

const setDatabaseExpanded = (database, expanded) => {
    const name = String(database || '');
    if (!name) return;

    const next = new Set(expandedDatabases.value);
    if (expanded) {
        next.add(name);
    } else {
        next.delete(name);
    }
    expandedDatabases.value = next;
};

const toggleDatabaseExpanded = (database) => {
    const name = String(database || '');
    if (!name) return;
    setDatabaseExpanded(name, !isDatabaseExpanded(name));
};

const applyTheme = (mode) => {
    if (typeof document === 'undefined') return;
    document.documentElement.classList.toggle('dark', mode === 'dark');
};

const toggleTheme = () => {
    theme.value = theme.value === 'dark' ? 'light' : 'dark';
    if (typeof window !== 'undefined') {
        window.localStorage.setItem(THEME_KEY, theme.value);
    }
    applyTheme(theme.value);
};

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
    if (!loadRows && options.selectDatabase !== false) {
        selectedTable.value = '';
        tableDetails.value = null;
        activeTableAction.value = 'structure';
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
                action: options.action || 'structure',
            });
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

    const queryStartedAt = performance?.now?.() || Date.now();
    tableQueryMeta.value = {
        label: buildTableQueryLabel(database, table, options.page || selectedTablePage.value || 1, options.perPage || perPage.value || 25, options.action || activeTableAction.value || 'browse'),
        durationMs: 0,
    };

    try {
        if (options.reusePayload) {
            tableDetails.value = options.reusePayload;
            tableQueryMeta.value.durationMs = Math.max(0, (performance?.now?.() || Date.now()) - queryStartedAt);
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
        tableQueryMeta.value.durationMs = Math.max(0, (performance?.now?.() || Date.now()) - queryStartedAt);
    } catch (error) {
        tableError.value = error?.message || 'Failed to load table rows.';
        pushToast(tableError.value);
        tableDetails.value = null;
        tableQueryMeta.value = { label: '', durationMs: 0 };
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
    if (!database) return;

    overviewMode.value = 'about';
    overviewSqlFullscreen.value = false;
    selectedTable.value = '';
    tableDetails.value = null;
    activeTableAction.value = 'structure';
    await loadDatabase(database, {
        page: 1,
        perPage: perPage.value,
        loadRows: false,
        selectDatabase: true,
    });
    setDatabaseExpanded(database, true);
};

const handleSelectTable = async ({ database, table }) => {
    if (!database || !table) return;
    activeTableAction.value = 'browse';
    await loadTable(database, table, { page: 1, perPage: perPage.value, action: 'browse' });
    setDatabaseExpanded(database, true);
};

const openConfirm = (action, table) => {
    if (!selectedDatabase.value || !table) return;

    dropTarget.value = {
        database: selectedDatabase.value,
        table,
    };
    confirmAction.value = action;
    dropConfirmOpen.value = true;
};

const closeDropConfirm = () => {
    if (dropInProgress.value) return;

    dropConfirmOpen.value = false;
    confirmAction.value = 'drop';
    dropTarget.value = { database: '', table: '' };
};

const confirmTableMutation = async () => {
    if (!dropTarget.value.database || !dropTarget.value.table || dropInProgress.value) return;

    dropInProgress.value = true;

    try {
        const routeName = confirmAction.value === 'empty'
            ? 'phpmyadmin.table.empty'
            : 'phpmyadmin.table.destroy';

        const response = await fetch(panelRoute(routeName, {
            database: dropTarget.value.database,
            table: dropTarget.value.table,
        }), {
            method: confirmAction.value === 'empty' ? 'POST' : 'DELETE',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });

        const data = await safeJson(response);
        if (!response.ok || !data?.ok) {
            throw new Error(data?.message || `Failed to ${confirmAction.value === 'empty' ? 'empty' : 'drop'} table.`);
        }

        pushToast(data.message || 'Table dropped successfully.');
        dropConfirmOpen.value = false;
        selectedTable.value = '';
        tableDetails.value = null;
        activeTableAction.value = 'structure';
        await loadDatabase(dropTarget.value.database, {
            page: 1,
            perPage: perPage.value,
            loadRows: false,
            selectDatabase: true,
        });
        setDatabaseExpanded(dropTarget.value.database, true);
        confirmAction.value = 'drop';
        dropTarget.value = { database: '', table: '' };
    } catch (error) {
        pushToast(error?.message || `Failed to ${confirmAction.value === 'empty' ? 'empty' : 'drop'} table.`);
    } finally {
        dropInProgress.value = false;
    }
};

const handlePaginate = async (page) => {
    if (!selectedDatabase.value || !selectedTable.value) return;
    selectedTablePage.value = page;
    await loadTable(selectedDatabase.value, selectedTable.value, { page, perPage: perPage.value });
};

const handlePerPageChange = async (nextPerPage) => {
    if (!selectedDatabase.value || !selectedTable.value) return;

    perPage.value = Math.max(1, Number(nextPerPage || 25));
    selectedTablePage.value = 1;
    await loadTable(selectedDatabase.value, selectedTable.value, {
        page: 1,
        perPage: perPage.value,
        action: activeTableAction.value || 'browse',
    });
};

const handleRowSave = async ({ original, draft }) => {
    if (!selectedDatabase.value || !selectedTable.value) return;

    try {
        const sql = buildUpdateSql(selectedDatabase.value, selectedTable.value, original, draft, tableDetails.value?.columns || []);
        const data = await runExecuteSql(sql);
        pushToast(data.message || 'Row updated successfully.');
        await loadTable(selectedDatabase.value, selectedTable.value, {
            page: selectedTablePage.value || 1,
            perPage: perPage.value,
            action: 'browse',
        });
        activeTableAction.value = 'browse';
    } catch (error) {
        pushToast(error?.message || 'Row update failed.');
    }
};

const handleRowDelete = async ({ row }) => {
    if (!selectedDatabase.value || !selectedTable.value) return;

    if (typeof window !== 'undefined' && !window.confirm(`Delete this row from ${selectedDatabase.value}.${selectedTable.value}?`)) {
        return;
    }

    try {
        const sql = buildDeleteSql(selectedDatabase.value, selectedTable.value, row, tableDetails.value?.columns || []);
        const data = await runExecuteSql(sql);
        pushToast(data.message || 'Row deleted successfully.');
        await loadTable(selectedDatabase.value, selectedTable.value, {
            page: selectedTablePage.value || 1,
            perPage: perPage.value,
            action: 'browse',
        });
        activeTableAction.value = 'browse';
    } catch (error) {
        pushToast(error?.message || 'Row delete failed.');
    }
};

const handleBulkDelete = async ({ rows }) => {
    if (!selectedDatabase.value || !selectedTable.value || !Array.isArray(rows) || rows.length === 0) return;

    if (typeof window !== 'undefined' && !window.confirm(`Delete ${rows.length} selected row(s) from ${selectedDatabase.value}.${selectedTable.value}?`)) {
        return;
    }

    try {
        for (const row of rows) {
            const sql = buildDeleteSql(selectedDatabase.value, selectedTable.value, row, tableDetails.value?.columns || []);
            await runExecuteSql(sql);
        }

        pushToast(`${rows.length} row(s) deleted successfully.`);
        await loadTable(selectedDatabase.value, selectedTable.value, {
            page: selectedTablePage.value || 1,
            perPage: perPage.value,
            action: 'browse',
        });
        activeTableAction.value = 'browse';
    } catch (error) {
        pushToast(error?.message || 'Bulk delete failed.');
    }
};

const handleAction = async ({ action, table }) => {
    if (!table) return;

    if (action !== 'drop') {
        activeTableAction.value = action;
    }

    if (action === 'structure') {
        setDatabaseExpanded(selectedDatabase.value, true);
        await loadTable(selectedDatabase.value, table, {
            page: selectedTablePage.value || 1,
            perPage: perPage.value,
            action: 'structure',
        });
        return;
    }

    if (action === 'browse') {
        setDatabaseExpanded(selectedDatabase.value, true);
        await loadTable(selectedDatabase.value, table, {
            page: selectedTablePage.value || 1,
            perPage: perPage.value,
            action: 'browse',
        });
        return;
    }

    if (action === 'select' || action === 'update') {
        setDatabaseExpanded(selectedDatabase.value, true);
        await loadTable(selectedDatabase.value, table, {
            page: selectedTablePage.value || 1,
            perPage: perPage.value,
            action: 'browse',
        });
        return;
    }

    if (action === 'insert') {
        setDatabaseExpanded(selectedDatabase.value, true);
        await loadTable(selectedDatabase.value, table, {
            page: selectedTablePage.value || 1,
            perPage: perPage.value,
            action: 'insert',
        });
        return;
    }

    if (action === 'drop') {
        openConfirm('drop', table);
        return;
    }

    if (action === 'empty') {
        openConfirm('empty', table);
        return;
    }

    if (action === 'search') {
        setDatabaseExpanded(selectedDatabase.value, true);
        await handleSelectTable({ database: selectedDatabase.value, table });
    }
};

const handleToolbarAction = async (action) => {
    if (action === 'history') {
        overviewMode.value = 'sql';
        overviewSqlFullscreen.value = false;
        historyOpenTrigger.value += 1;
        return;
    }

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

    if (action === 'insert') {
        await loadTable(selectedDatabase.value, table, {
            page: selectedTablePage.value || 1,
            perPage: perPage.value,
            action: 'insert',
        });
        return;
    }

    if (action === 'search') {
        await loadTable(selectedDatabase.value, table, {
            page: selectedTablePage.value || 1,
            perPage: perPage.value,
            action,
        });
    }
};

const handleInsertSubmit = async ({ rows }) => {
    if (!selectedDatabase.value || !selectedTable.value) return;

    try {
        const sql = buildInsertSql(selectedDatabase.value, selectedTable.value, rows);
        const response = await fetch(panelRoute('phpmyadmin.execute'), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                sql,
                database: selectedDatabase.value,
            }),
        });

        const data = await safeJson(response);
        if (!response.ok || !data?.ok) {
            throw new Error(data?.message || 'Insert failed.');
        }

        pushToast(data.message || 'Row inserted successfully.');
        activeTableAction.value = 'browse';
        await loadTable(selectedDatabase.value, selectedTable.value, {
            page: selectedTablePage.value || 1,
            perPage: perPage.value,
            action: 'browse',
        });
    } catch (error) {
        pushToast(error?.message || 'Insert failed.');
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
    await handleSelectDatabase(database);
};

const handleSidebarToggleDatabase = async (database) => {
    const name = String(database || '');
    if (!name) return;

    const shouldExpand = !isDatabaseExpanded(name);
    if (shouldExpand && !tablesByDatabase.value?.[name]) {
        await loadDatabase(name, {
            page: 1,
            perPage: perPage.value,
            loadRows: false,
            selectDatabase: false,
        });
    }

    toggleDatabaseExpanded(name);
};

const handleOverviewSqlExecuted = () => {
    overviewSqlFullscreen.value = true;
};

onMounted(() => {
    loadSplitWidth();
    if (typeof window !== 'undefined') {
        const saved = window.localStorage.getItem(THEME_KEY);
        const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
        theme.value = saved === 'dark' || saved === 'light' ? saved : (prefersDark ? 'dark' : 'light');
        applyTheme(theme.value);
    }
    void loadDatabases();
});
</script>

<template>
    <Head title="Database Studio" />

    <DatabaseStudioLayout
        :server="server"
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
                </div>

                <button
                    v-if="!(overviewMode === 'sql' && overviewSqlFullscreen)"
                    type="button"
                    class="hidden w-1 shrink-0 cursor-col-resize bg-slate-200 transition hover:bg-cyan-400 dark:bg-slate-700 dark:hover:bg-cyan-500 lg:block"
                    title="Drag to resize panels"
                    aria-label="Resize panels"
                    @pointerdown="startResize"
                ></button>

                <div class="relative flex min-h-0 flex-1 flex-col overflow-hidden">
                        <DatabaseTopbarMenu
                            :server="server"
                            :selected-database="selectedDatabase"
                            :header-mode="headerMode"
                            :overview-active-tab="overviewActiveTab"
                        :active-action="topbarActiveAction"
                        :theme="theme"
                        @toggle-theme="toggleTheme"
                        @overview-select="handleOverviewSelect"
                        @toolbar-action="handleToolbarAction"
                    />

                    <div class="min-h-0 flex-1 overflow-x-hidden overflow-y-auto pb-14 pr-1">
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
                            v-if="overviewMode === 'sql'"
                            :panel-route="panelRoute"
                            :selected-database="selectedDatabase || server?.current_database || ''"
                            :initial-sql="queryDefaults?.sql || 'SHOW TABLES;'"
                            :schema-tables="currentDatabaseTables"
                            :history-open-trigger="historyOpenTrigger"
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
                            :query-label="tableQueryMeta.label"
                            :query-duration-ms="tableQueryMeta.durationMs"
                            :rows-per-page="perPage"
                            :plain="true"
                            @paginate="handlePaginate"
                            @per-page-change="handlePerPageChange"
                            @bulk-delete="handleBulkDelete"
                            @row-delete="handleRowDelete"
                            @row-save="handleRowSave"
                            @insert-submit="handleInsertSubmit"
                        />
                    </div>

                    <SqlConsole
                        :panel-route="panelRoute"
                        :selected-database="selectedDatabase || server?.current_database || ''"
                        :initial-sql="queryDefaults?.sql || 'SHOW TABLES;'"
                        :schema-tables="currentDatabaseTables"
                        :history-open-trigger="historyOpenTrigger"
                        :plain="true"
                        :dock-only="true"
                        :notify="pushToast"
                        @executed="handleOverviewSqlExecuted"
                    />
                </div>
            </div>
        </div>

        <PhpMyAdminToastStack :toasts="toasts" @dismiss="removeToast" />

        <Modal :show="dropConfirmOpen" max-width="lg" @close="closeDropConfirm">
            <div class="border-b border-slate-200 px-6 py-4 dark:border-slate-800">
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
                <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-950/30 dark:text-rose-200">
                    <p class="font-semibold">
                        Are you sure you want to {{ confirmAction === 'empty' ? 'empty' : 'drop' }} this table?
                    </p>
                    <p class="mt-1 break-words">
                        <span class="font-medium">{{ dropTarget.database }}</span>.<span class="font-medium">{{ dropTarget.table }}</span>
                    </p>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 border-t border-slate-200 px-6 py-4 dark:border-slate-800">
                <button
                    type="button"
                    class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                    :disabled="dropInProgress"
                    @click="closeDropConfirm"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    class="rounded-md bg-rose-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-rose-500 disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="dropInProgress"
                    @click="confirmTableMutation"
                >
                    {{ dropInProgress ? (confirmAction === 'empty' ? 'Emptying...' : 'Dropping...') : (confirmAction === 'empty' ? 'Empty Table' : 'Drop Table') }}
                </button>
            </div>
        </Modal>
    </DatabaseStudioLayout>
</template>
