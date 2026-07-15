import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { buildTableQueryLabel } from '../helpers/phpMyAdminSql.js';

const SPLIT_KEY = 'serverpanel-phpmyadmin-split-width';
const SPLIT_MIN = 24;
const SPLIT_MAX = 58;
const THEME_KEY = 'serverpanel-theme';
const UI_STATE_KEY = 'serverpanel-phpmyadmin-ui';

const normalizeIdentifier = (value) => {
    const text = String(value ?? '').trim();
    if (!text) return '';
    return /^[A-Za-z0-9_]+$/.test(text) ? text : '';
};

const clamp = (value, min, max, fallback) => {
    const numeric = Number(value);
    if (!Number.isFinite(numeric)) return fallback;
    return Math.min(max, Math.max(min, numeric));
};

const clampSplitWidth = (value) => clamp(value, SPLIT_MIN, SPLIT_MAX, 34);

const readPersistedUiState = () => {
    if (typeof window === 'undefined') {
        return {};
    }

    try {
        return JSON.parse(window.sessionStorage.getItem(UI_STATE_KEY) || '{}') || {};
    } catch {
        return {};
    }
};

const writePersistedUiState = (state) => {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        window.sessionStorage.setItem(UI_STATE_KEY, JSON.stringify(state));
    } catch {
        // Ignore storage failures.
    }
};

export function usePhpMyAdminReadState(props, transport) {
    const initialSelection = props.initialSelection || {};
    const accessControl = props.accessControl || {};
    const persistedState = readPersistedUiState();

    const databases = ref([]);
    const selectedDatabase = ref(normalizeIdentifier(initialSelection.database || persistedState.database));
    const selectedTable = ref(normalizeIdentifier(initialSelection.table || persistedState.table));
    const databaseSummary = ref(null);
    const tables = ref([]);
    const tableDetails = ref(null);
    const tableQueryMeta = ref({ label: '', durationMs: 0 });
    const activeTableAction = ref(normalizeIdentifier(initialSelection.action || persistedState.action || (selectedTable.value ? 'browse' : 'structure')) || 'browse');
    const loadingDatabases = ref(false);
    const loadingDatabase = ref(false);
    const loadingTable = ref(false);
    const databaseError = ref('');
    const tableError = ref('');
    const pageNumber = ref(clamp(initialSelection.page || persistedState.page || 1, 1, 9999, 1));
    const perPage = ref(clamp(initialSelection.perPage || persistedState.perPage || 25, 10, 200, 25));
    const selectedTablePage = ref(clamp(initialSelection.page || persistedState.page || 1, 1, 9999, 1));
    const databaseCache = ref({});
    const expandedDatabases = ref(new Set());
    const sidebarFilter = ref('');
    const overviewMode = ref(String(initialSelection.view || persistedState.view || 'about'));
    const overviewTab = ref(String(
        initialSelection.tab
        || persistedState.tab
        || (overviewMode.value === 'sql' ? 'SQL' : overviewMode.value === 'transfer' ? 'Transfer' : overviewMode.value === 'databases' ? 'Databases' : 'About')
    ));
    const overviewSqlFullscreen = ref(false);
    const historyOpenTrigger = ref(0);
    const theme = ref('light');
    const splitWidth = ref(34);
    const splitRow = ref(null);
    const isResizing = ref(false);
    const allowedDatabases = computed(() => {
        if (!Array.isArray(accessControl.databases)) {
            return [];
        }

        return accessControl.databases
            .map(normalizeIdentifier)
            .filter((name) => name !== '');
    });

    const isDatabaseAllowed = (database) => {
        const name = normalizeIdentifier(database);
        if (!name) {
            return false;
        }

        if (String(accessControl.mode || '') === 'global') {
            return true;
        }

        const list = allowedDatabases.value;
        return list.includes(name);
    };

    const currentDatabaseTables = computed(() => {
        const cached = selectedDatabase.value ? databaseCache.value[selectedDatabase.value]?.tables : null;
        if (Array.isArray(cached)) {
            return cached;
        }

        return tables.value;
    });

    const tablesByDatabase = computed(() => databaseCache.value);
    const currentDatabase = computed(() => selectedDatabase.value || props.server?.current_database || '');
    const headerMode = computed(() => (selectedDatabase.value ? 'compact' : 'overview'));
    const topbarActiveAction = computed(() => (overviewMode.value === 'sql' ? 'sql' : activeTableAction.value));
    const overviewActiveTab = computed(() => {
        if (selectedDatabase.value) {
            return 'about';
        }

        return overviewTab.value || 'About';
    });

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

    const handleResizeMove = (event) => {
        updateSplitWidth(event.clientX);
    };

    const stopResize = () => {
        if (!isResizing.value) return;

        isResizing.value = false;
        if (typeof window === 'undefined') return;

        window.removeEventListener('pointermove', handleResizeMove);
        window.removeEventListener('pointerup', stopResize);
        window.removeEventListener('pointercancel', stopResize);
    };

    const startResize = (event) => {
        if (typeof window === 'undefined') return;

        event.preventDefault();
        isResizing.value = true;
        updateSplitWidth(event.clientX);
        window.addEventListener('pointermove', handleResizeMove);
        window.addEventListener('pointerup', stopResize);
        window.addEventListener('pointercancel', stopResize);
    };

    const readHashState = () => {
        if (typeof window === 'undefined') {
            return {};
        }

        const params = new URLSearchParams(window.location.hash.replace(/^#/, ''));
        return {
            database: normalizeIdentifier(params.get('database')),
            table: normalizeIdentifier(params.get('table')),
            page: clamp(params.get('page') || pageNumber.value, 1, 9999, pageNumber.value),
            perPage: clamp(params.get('perPage') || perPage.value, 10, 200, perPage.value),
            view: params.get('view') || '',
            fullscreen: params.get('fullscreen') === '1',
        };
    };

    let suppressHashSync = false;

    const syncHashState = () => {
        if (typeof window === 'undefined' || suppressHashSync) return;

        const params = new URLSearchParams();
        if (selectedDatabase.value) params.set('database', selectedDatabase.value);
        if (selectedTable.value) params.set('table', selectedTable.value);
        if (pageNumber.value > 1) params.set('page', String(pageNumber.value));
        if (perPage.value !== 25) params.set('perPage', String(perPage.value));
        if (overviewMode.value !== 'about') params.set('view', overviewMode.value);
        if (overviewTab.value && overviewTab.value !== 'About') params.set('tab', overviewTab.value);
        if (overviewSqlFullscreen.value) params.set('fullscreen', '1');

        const hash = params.toString();
        const nextUrl = `${window.location.pathname}${window.location.search}${hash ? `#${hash}` : ''}`;
        window.history.replaceState(null, '', nextUrl);
    };

    watch(
        [selectedDatabase, selectedTable, pageNumber, perPage, overviewMode, overviewTab, overviewSqlFullscreen, activeTableAction],
        () => {
            syncHashState();
            writePersistedUiState({
                database: selectedDatabase.value,
                table: selectedTable.value,
                page: pageNumber.value,
                perPage: perPage.value,
                view: overviewMode.value,
                tab: overviewTab.value,
                action: activeTableAction.value,
                fullscreen: overviewSqlFullscreen.value,
            });
        },
        { flush: 'post' },
    );

    const hydrateTheme = () => {
        if (typeof window === 'undefined') return;

        const saved = window.localStorage.getItem(THEME_KEY);
        const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
        theme.value = saved === 'dark' || saved === 'light' ? saved : (prefersDark ? 'dark' : 'light');
        applyTheme(theme.value);
    };

    const loadDatabases = async () => {
        loadingDatabases.value = true;
        databaseError.value = '';
        const previousSelectedDatabase = selectedDatabase.value;

        try {
            const { response, data } = await transport.requestJson('phpmyadmin.databases');
            if (!response.ok || !data?.ok) {
                databaseError.value = data?.message || 'Failed to load databases.';
                transport.pushToast(databaseError.value);
                databases.value = [];
                return;
            }

            databases.value = Array.isArray(data.databases) ? data.databases : [];

            if (selectedDatabase.value && !databases.value.includes(selectedDatabase.value)) {
                selectedDatabase.value = databases.value[0] || '';
            }

            if (!selectedDatabase.value && databases.value.length > 0) {
                selectedDatabase.value = databases.value[0];
            }

            if (selectedDatabase.value && selectedDatabase.value !== previousSelectedDatabase) {
                await loadDatabase(selectedDatabase.value, {
                    page: pageNumber.value,
                    perPage: perPage.value,
                    loadRows: Boolean(selectedTable.value),
                    table: selectedTable.value,
                    selectDatabase: true,
                    action: selectedTable.value ? 'browse' : 'structure',
                });
            }
        } catch (error) {
            databaseError.value = error?.message || 'Failed to load databases.';
            transport.pushToast(databaseError.value);
        } finally {
            loadingDatabases.value = false;
        }
    };

    const loadDatabase = async (database, options = {}) => {
        if (!database) return;
        if (!isDatabaseAllowed(database)) {
            transport.pushToast(`Access denied for ${database}.`, 'error');
            return;
        }

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
            const { response, data } = await transport.requestJson('phpmyadmin.database', { database, ...query });
            if (!response.ok || !data?.ok) {
                databaseError.value = data?.message || 'Failed to load database details.';
                transport.pushToast(databaseError.value);
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
            transport.pushToast(databaseError.value);
            tables.value = [];
            databaseSummary.value = null;
        } finally {
            loadingDatabase.value = false;
            loadingTable.value = false;
        }
    };

    const loadTable = async (database, table, options = {}) => {
        if (!database || !table) return;
        if (!isDatabaseAllowed(database)) {
            transport.pushToast(`Access denied for ${database}.`, 'error');
            return;
        }

        selectedDatabase.value = database;
        selectedTable.value = table;
        loadingTable.value = true;
        tableError.value = '';

        if (options.action) {
            activeTableAction.value = options.action;
        }

        if (options.page) {
            selectedTablePage.value = options.page;
            pageNumber.value = options.page;
        }

        if (options.perPage) {
            perPage.value = options.perPage;
        }

        const queryStartedAt = performance?.now?.() || Date.now();
        tableQueryMeta.value = {
            label: buildTableQueryLabel(
                database,
                table,
                options.page || selectedTablePage.value || 1,
                options.perPage || perPage.value || 25,
                options.action || activeTableAction.value || 'browse',
            ),
            durationMs: 0,
        };

        try {
            if (options.reusePayload) {
                tableDetails.value = options.reusePayload;
                tableQueryMeta.value.durationMs = Math.max(0, (performance?.now?.() || Date.now()) - queryStartedAt);
                return;
            }

            const { response, data } = await transport.requestJson('phpmyadmin.table', {
                database,
                table,
                page: options.page || selectedTablePage.value || 1,
                perPage: options.perPage || perPage.value || 25,
            });

            if (!response.ok || !data?.ok) {
                tableError.value = data?.message || 'Failed to load table rows.';
                transport.pushToast(tableError.value);
                tableDetails.value = null;
                return;
            }

            tableDetails.value = data.table_details || null;
            tableQueryMeta.value.durationMs = Math.max(0, (performance?.now?.() || Date.now()) - queryStartedAt);
        } catch (error) {
            tableError.value = error?.message || 'Failed to load table rows.';
            transport.pushToast(tableError.value);
            tableDetails.value = null;
            tableQueryMeta.value = { label: '', durationMs: 0 };
        } finally {
            loadingTable.value = false;
        }
    };

    const resetView = async () => {
        selectedTable.value = '';
        selectedTablePage.value = 1;
        pageNumber.value = 1;
        tableDetails.value = null;
        overviewSqlFullscreen.value = false;

        if (selectedDatabase.value) {
            await loadDatabase(selectedDatabase.value, { page: 1, perPage: perPage.value, loadRows: false });
        }
    };

    const handleSelectDatabase = async (database) => {
        if (!database) return;
        if (!isDatabaseAllowed(database)) {
            transport.pushToast(`Access denied for ${database}.`, 'error');
            return;
        }

        overviewMode.value = 'about';
        overviewSqlFullscreen.value = false;
        selectedTable.value = '';
        tableDetails.value = null;
        activeTableAction.value = 'structure';
        pageNumber.value = 1;
        selectedTablePage.value = 1;

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
        if (!isDatabaseAllowed(database)) {
            transport.pushToast(`Access denied for ${database}.`, 'error');
            return;
        }

        overviewMode.value = 'about';
        overviewSqlFullscreen.value = false;
        activeTableAction.value = 'browse';
        pageNumber.value = 1;
        selectedTablePage.value = 1;
        await loadTable(database, table, { page: 1, perPage: perPage.value, action: 'browse' });
        setDatabaseExpanded(database, true);
    };

    const handleSidebarFilterChange = (value) => {
        sidebarFilter.value = value;
    };

    const handleOverviewSelect = (tab) => {
        if (tab === 'Databases') {
            overviewTab.value = 'Databases';
            overviewMode.value = 'databases';
            overviewSqlFullscreen.value = false;
            return;
        }

        if (tab === 'SQL') {
            overviewTab.value = 'SQL';
            overviewMode.value = 'sql';
            return;
        }

        if (tab === 'Transfer') {
            overviewTab.value = 'Transfer';
            overviewMode.value = 'transfer';
            return;
        }

        overviewTab.value = tab || 'About';
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
        if (!isDatabaseAllowed(name)) {
            transport.pushToast(`Access denied for ${name}.`, 'error');
            return;
        }

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

    const activeDatabaseName = () => selectedDatabase.value || props.server?.current_database || '';

    const extractDownloadFilename = (response, fallback) => {
        const header = String(response.headers?.get?.('content-disposition') || '');
        const utf8Match = header.match(/filename\*=UTF-8''([^;]+)/i);
        if (utf8Match?.[1]) {
            try {
                return decodeURIComponent(utf8Match[1]);
            } catch {
                return utf8Match[1];
            }
        }

        const simpleMatch = header.match(/filename="?([^";]+)"?/i);
        if (simpleMatch?.[1]) {
            return simpleMatch[1];
        }

        return fallback;
    };

    const triggerDownload = (blob, filename) => {
        if (typeof window === 'undefined') return;

        const url = window.URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        link.rel = 'noopener';
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.setTimeout(() => window.URL.revokeObjectURL(url), 1000);
    };

    const handleExportDatabase = async () => {
        const database = activeDatabaseName();
        if (!database) {
            transport.pushToast('Select a database before exporting.', 'error');
            return;
        }

        try {
            const { response, blob, data } = await transport.requestBlob('phpmyadmin.export', {}, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': transport.csrfToken.value,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ database }),
            });

            if (!response.ok || !blob) {
                throw new Error(data?.message || 'Export failed.');
            }

            const filename = extractDownloadFilename(response, `${database}.sql`);
            triggerDownload(blob, filename);
            transport.pushToast(`Export ready for ${database}.`, 'success');
        } catch (error) {
            transport.pushToast(error?.message || 'Export failed.', 'error');
        }
    };

    const handleImportDatabase = async () => {
        const database = activeDatabaseName();
        if (!database) {
            transport.pushToast('Select a database before importing.', 'error');
            return;
        }

        if (typeof document === 'undefined') return;

        const input = document.createElement('input');
        input.type = 'file';
        input.accept = '.sql,.txt,application/sql,text/plain';
        input.style.display = 'none';
        document.body.appendChild(input);

        const file = await new Promise((resolve) => {
            const finalize = () => {
                window.removeEventListener('focus', handleFocus);
                resolve(input.files?.[0] || null);
            };

            const handleFocus = () => {
                window.setTimeout(finalize, 0);
            };

            input.addEventListener('change', finalize, { once: true });
            window.addEventListener('focus', handleFocus, { once: true });
            input.click();
        });

        input.remove();

        if (!file) {
            return;
        }

        try {
            const formData = new FormData();
            formData.append('database', database);
            formData.append('file', file);

            const { response, data } = await transport.requestJson('phpmyadmin.import', {}, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': transport.csrfToken.value,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: formData,
            });

            if (!response.ok || !data?.ok) {
                throw new Error(data?.message || 'Import failed.');
            }

            transport.pushToast(data.message || `Import completed for ${database}.`, 'success');
            await loadDatabases();
            if (selectedDatabase.value === database) {
                await loadDatabase(database, {
                    page: pageNumber.value,
                    perPage: perPage.value,
                    loadRows: Boolean(selectedTable.value),
                    table: selectedTable.value,
                    selectDatabase: true,
                    action: selectedTable.value ? 'browse' : 'structure',
                });
            }
        } catch (error) {
            transport.pushToast(error?.message || 'Import failed.', 'error');
        }
    };

    const handlePaginate = async (page) => {
        if (!selectedDatabase.value || !selectedTable.value) return;

        selectedTablePage.value = page;
        pageNumber.value = page;
        await loadTable(selectedDatabase.value, selectedTable.value, { page, perPage: perPage.value });
    };

    const handlePerPageChange = async (nextPerPage) => {
        if (!selectedDatabase.value || !selectedTable.value) return;

        perPage.value = Math.max(1, Number(nextPerPage || 25));
        selectedTablePage.value = 1;
        pageNumber.value = 1;
        await loadTable(selectedDatabase.value, selectedTable.value, {
            page: 1,
            perPage: perPage.value,
            action: activeTableAction.value || 'browse',
        });
    };

    const handleToolbarAction = async (action) => {
        if (action === 'history') {
            overviewMode.value = 'sql';
            overviewSqlFullscreen.value = false;
            historyOpenTrigger.value += 1;
            return;
        }

        if (action === 'export') {
            await handleExportDatabase();
            return;
        }

        if (action === 'import') {
            await handleImportDatabase();
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

        overviewMode.value = 'about';

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
            return;
        }

        if (action === 'create') {
            if (!selectedDatabase.value) {
                transport.pushToast('Select a database before creating a table.', 'error');
                return;
            }

            activeTableAction.value = 'create';
            selectedTable.value = '';
            tableDetails.value = null;
            overviewMode.value = 'about';
            return;
        }

        if (action === 'operations') {
            await loadTable(selectedDatabase.value, table, {
                page: selectedTablePage.value || 1,
                perPage: perPage.value,
                action: 'operations',
            });
        }
    };

    const initializeState = async () => {
        if (!selectedDatabase.value) {
            return;
        }

        if (!isDatabaseAllowed(selectedDatabase.value)) {
            selectedDatabase.value = databases.value[0] || '';
        }

        if (!selectedDatabase.value) {
            return;
        }

        setDatabaseExpanded(selectedDatabase.value, true);
        await loadDatabase(selectedDatabase.value, {
            page: pageNumber.value,
            perPage: perPage.value,
            loadRows: Boolean(selectedTable.value),
            table: selectedTable.value,
            selectDatabase: true,
            action: selectedTable.value ? 'browse' : 'structure',
        });
    };

    const applyHashState = async () => {
        if (typeof window === 'undefined') return;

        const hashState = readHashState();
        const hasHashSelection = Boolean(hashState.database || hashState.table || hashState.view || hashState.fullscreen);

        suppressHashSync = true;

        if (hashState.database && isDatabaseAllowed(hashState.database)) {
            selectedDatabase.value = hashState.database;
        } else if (hashState.database && !isDatabaseAllowed(hashState.database)) {
            transport.pushToast(`Access denied for ${hashState.database}.`, 'error');
        }

        if (hashState.table && (!hashState.database || isDatabaseAllowed(hashState.database))) {
            selectedTable.value = hashState.table;
        }

        pageNumber.value = hashState.page;
        selectedTablePage.value = hashState.page;
        perPage.value = hashState.perPage;

        if (hashState.view === 'sql' || hashState.view === 'databases' || hashState.view === 'about' || hashState.view === 'transfer') {
            overviewMode.value = hashState.view;
        }

        if (hashState.tab) {
            overviewTab.value = hashState.tab;
        }

        overviewSqlFullscreen.value = Boolean(hashState.fullscreen);

        setTimeout(() => {
            suppressHashSync = false;
        }, 0);

        if (hasHashSelection) {
            await initializeState();
        }
    };

    const handleHashChange = () => {
        void applyHashState();
    };

    onMounted(() => {
        loadSplitWidth();
        hydrateTheme();
        window.addEventListener('hashchange', handleHashChange);
        const hashState = readHashState();
        const hasHashSelection = Boolean(hashState.database || hashState.table || hashState.view || hashState.fullscreen);

        if (hasHashSelection) {
            void applyHashState();
        } else {
            void initializeState();
        }

        void loadDatabases();
    });

    onBeforeUnmount(() => {
        stopResize();
        if (typeof window !== 'undefined') {
            window.removeEventListener('hashchange', handleHashChange);
        }
    });

    return {
        databases,
        selectedDatabase,
        selectedTable,
        databaseSummary,
        tables,
        tableDetails,
        tableQueryMeta,
        activeTableAction,
        loadingDatabases,
        loadingDatabase,
        loadingTable,
        databaseError,
        tableError,
        pageNumber,
        perPage,
        selectedTablePage,
        databaseCache,
        expandedDatabases,
        sidebarFilter,
        overviewMode,
        overviewTab,
        overviewSqlFullscreen,
        historyOpenTrigger,
        theme,
        splitWidth,
        splitRow,
        isResizing,
        currentDatabaseTables,
        tablesByDatabase,
        currentDatabase,
        headerMode,
        topbarActiveAction,
        overviewActiveTab,
        isDatabaseExpanded,
        setDatabaseExpanded,
        toggleDatabaseExpanded,
        toggleTheme,
        startResize,
        stopResize,
        loadDatabases,
        loadDatabase,
        loadTable,
        resetView,
        handleSelectDatabase,
        handleSelectTable,
        handleSidebarFilterChange,
        handleOverviewSelect,
        handleSelectDatabaseFromSummary,
        handleSidebarToggleDatabase,
        handleOverviewSqlExecuted,
        handleExportDatabase,
        handleImportDatabase,
        handlePaginate,
        handlePerPageChange,
        handleToolbarAction,
        initializeState,
        applyHashState,
    };
}
