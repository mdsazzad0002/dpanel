<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { usePhpMyAdminTransport } from '../composables/usePhpMyAdminTransport.js';

const props = defineProps({
    panelToken: {
        type: String,
        default: '',
    },
    databases: {
        type: Array,
        default: () => [],
    },
    selectedDatabase: {
        type: String,
        default: '',
    },
    tables: {
        type: Array,
        default: () => [],
    },
    initialTab: {
        type: String,
        default: 'export',
    },
    notify: {
        type: Function,
        default: null,
    },
});

const emit = defineEmits(['completed']);
const transport = usePhpMyAdminTransport({ panelToken: props.panelToken });
const { requestBlob, requestJson } = transport;

const activeTab = ref(props.initialTab === 'import' ? 'import' : 'export');
const selectedDatabaseName = ref(String(props.selectedDatabase || '').trim());
const exportScope = ref('database');
const selectedTableName = ref('');
const importFile = ref(null);
const exportBusy = ref(false);
const importBusy = ref(false);
const statusText = ref('');
const fileInputRef = ref(null);

const notify = (message, type = 'error') => {
    if (!message) return;
    if (props.notify) {
        props.notify(message, type);
        return;
    }
    transport.pushToast(message, type);
};

const databaseOptions = computed(() => (Array.isArray(props.databases) ? props.databases : []).map((database) => String(database || '').trim()).filter(Boolean));
const tableOptions = computed(() => (Array.isArray(props.tables) ? props.tables : []).map((table) => String(table?.name || table || '').trim()).filter(Boolean));
const effectiveDatabase = computed(() => selectedDatabaseName.value || props.selectedDatabase || databaseOptions.value[0] || '');
const effectiveTable = computed(() => selectedTableName.value || tableOptions.value[0] || '');

const syncDatabase = () => {
    const next = String(props.selectedDatabase || '').trim();
    if (next) {
        selectedDatabaseName.value = next;
        return;
    }

    if (!selectedDatabaseName.value && databaseOptions.value.length > 0) {
        selectedDatabaseName.value = databaseOptions.value[0];
    }
};

const syncTable = () => {
    if (!selectedTableName.value && tableOptions.value.length > 0) {
        selectedTableName.value = tableOptions.value[0];
    }
};

watch(
    () => [props.selectedDatabase, props.databases],
    () => {
        syncDatabase();
    },
    { immediate: true, deep: true },
);

watch(
    [() => props.tables, () => selectedDatabaseName.value],
    () => {
        selectedTableName.value = '';
        syncTable();
    },
    { immediate: true, deep: true },
);

watch(
    () => props.initialTab,
    (value) => {
        activeTab.value = value === 'import' ? 'import' : 'export';
    }
);

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

const runExport = async () => {
    const database = effectiveDatabase.value;
    const table = exportScope.value === 'table' ? effectiveTable.value : '';
    if (!database) {
        notify('Select a database before exporting.', 'error');
        return;
    }

    if (exportScope.value === 'table' && !table) {
        notify('Select a table before exporting a specific table.', 'error');
        return;
    }

    exportBusy.value = true;
    statusText.value = '';

    try {
        const { response, blob, data } = await requestBlob('phpmyadmin.export', {}, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': transport.csrfToken.value,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                database,
                scope: exportScope.value,
                table,
            }),
        });

        if (!response.ok || !blob) {
            throw new Error(data?.message || 'Export failed.');
        }

        const filename = extractDownloadFilename(response, table ? `${database}-${table}.sql` : `${database}.sql`);
        triggerDownload(blob, filename);
        statusText.value = exportScope.value === 'table'
            ? `Export ready for ${database}.${table}.`
            : `Export ready for ${database}.`;
        notify(statusText.value, 'success');
        emit('completed', { type: 'export', database, table, filename });
    } catch (error) {
        notify(error?.message || 'Export failed.', 'error');
    } finally {
        exportBusy.value = false;
    }
};

const openFilePicker = async () => {
    await nextTick();
    fileInputRef.value?.click?.();
};

const onFileChange = (event) => {
    const file = event?.target?.files?.[0] || null;
    importFile.value = file;
};

const runImport = async () => {
    const database = effectiveDatabase.value;
    const file = importFile.value;

    if (!database) {
        notify('Select a database before importing.', 'error');
        return;
    }

    if (!file) {
        notify('Choose an SQL file to import.', 'error');
        return;
    }

    importBusy.value = true;
    statusText.value = '';

    try {
        const formData = new FormData();
        formData.append('database', database);
        formData.append('file', file);

        const { response, data } = await requestJson('phpmyadmin.import', {}, {
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

        statusText.value = data.message || `Import completed for ${database}.`;
        notify(statusText.value, 'success');
        emit('completed', { type: 'import', database, filename: file.name || '' });
        importFile.value = null;
        if (fileInputRef.value) {
            fileInputRef.value.value = '';
        }
    } catch (error) {
        notify(error?.message || 'Import failed.', 'error');
    } finally {
        importBusy.value = false;
    }
};
</script>

<template>
    <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 pb-4 dark:border-slate-800">
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-cyan-600 dark:text-cyan-300">
                    Transfer
                </p>
                <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">
                    Import / Export
                </h2>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    {{ effectiveDatabase ? `Active database: ${effectiveDatabase}` : 'Select a database to continue.' }}
                </p>
            </div>

            <div class="inline-flex rounded-full border border-slate-200 bg-slate-50 p-1 dark:border-slate-800 dark:bg-slate-950">
                <button
                    type="button"
                    class="rounded-full px-4 py-2 text-xs font-medium transition"
                    :class="activeTab === 'export'
                        ? 'bg-cyan-600 text-white shadow-sm'
                        : 'text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white'"
                    @click="activeTab = 'export'"
                >
                    Export
                </button>
                <button
                    type="button"
                    class="rounded-full px-4 py-2 text-xs font-medium transition"
                    :class="activeTab === 'import'
                        ? 'bg-cyan-600 text-white shadow-sm'
                        : 'text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white'"
                    @click="activeTab = 'import'"
                >
                    Import
                </button>
            </div>
        </div>

        <div v-if="databaseOptions.length > 0" class="mt-4">
            <label class="mb-2 block text-xs font-semibold uppercase tracking-[0.16em] text-slate-500 dark:text-slate-400">
                Database
            </label>
            <select
                v-model="selectedDatabaseName"
                class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2 text-sm outline-none transition focus:border-cyan-400 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
            >
                <option v-for="database in databaseOptions" :key="database" :value="database">
                    {{ database }}
                </option>
            </select>
        </div>

        <div v-if="statusText" class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-200">
            {{ statusText }}
        </div>

        <div v-if="activeTab === 'export'" class="mt-4 space-y-4">
            <p class="text-sm text-slate-600 dark:text-slate-300">
                Export the selected database as a SQL dump. The download starts in the background, no page reload.
            </p>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="grid gap-2">
                    <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500 dark:text-slate-400">
                        Export scope
                    </span>
                    <select
                        v-model="exportScope"
                        class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2 text-sm outline-none transition focus:border-cyan-400 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                    >
                        <option value="database">All tables in database</option>
                        <option value="table">Specific table</option>
                    </select>
                </label>

                <label v-if="exportScope === 'table'" class="grid gap-2">
                    <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500 dark:text-slate-400">
                        Table
                    </span>
                    <select
                        v-model="selectedTableName"
                        class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2 text-sm outline-none transition focus:border-cyan-400 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                    >
                        <option value="">Select table</option>
                        <option v-for="table in tableOptions" :key="table" :value="table">
                            {{ table }}
                        </option>
                    </select>
                </label>
            </div>
            <button
                type="button"
                class="rounded-full bg-cyan-600 px-4 py-2 text-sm font-medium text-white hover:bg-cyan-700 disabled:cursor-not-allowed disabled:opacity-60"
                :disabled="exportBusy || !effectiveDatabase"
                @click="runExport"
            >
                {{ exportBusy ? 'Preparing export...' : 'Start Export' }}
            </button>
        </div>

        <div v-else class="mt-4 space-y-4">
            <p class="text-sm text-slate-600 dark:text-slate-300">
                Import a SQL file into the selected database. Upload and execution happen in the background.
            </p>
            <input
                ref="fileInputRef"
                type="file"
                accept=".sql,.txt,application/sql,text/plain"
                class="block w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2 text-sm text-slate-700 file:mr-3 file:rounded-full file:border-0 file:bg-cyan-600 file:px-4 file:py-1.5 file:text-sm file:font-medium file:text-white hover:file:bg-cyan-700 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-200"
                @change="onFileChange"
            >
            <div class="flex flex-wrap items-center gap-3">
                <button
                    type="button"
                    class="rounded-full border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                    @click="openFilePicker"
                >
                    Choose file
                </button>
                <button
                    type="button"
                    class="rounded-full bg-cyan-600 px-4 py-2 text-sm font-medium text-white hover:bg-cyan-700 disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="importBusy || !effectiveDatabase || !importFile"
                    @click="runImport"
                >
                    {{ importBusy ? 'Importing...' : 'Run Import' }}
                </button>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-400">
                {{ importFile ? `Selected file: ${importFile.name}` : 'No file selected yet.' }}
            </p>
        </div>
    </section>
</template>
