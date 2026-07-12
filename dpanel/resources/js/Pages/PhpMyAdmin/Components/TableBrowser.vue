<script setup>
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps({
    tableDetails: {
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
    activeAction: {
        type: String,
        default: 'browse',
    },
    loading: {
        type: Boolean,
        default: false,
    },
    error: {
        type: String,
        default: '',
    },
    queryLabel: {
        type: String,
        default: '',
    },
    queryDurationMs: {
        type: Number,
        default: 0,
    },
    rowsPerPage: {
        type: Number,
        default: 25,
    },
    renameBusy: {
        type: Boolean,
        default: false,
    },
    plain: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits([
    'paginate',
    'insert-submit',
    'per-page-change',
    'bulk-delete',
    'row-delete',
    'row-save',
    'table-rename',
    'edit-structure',
]);

const selectedTableDetails = computed(() => props.tableDetails || null);
const selectedTableColumns = computed(() => selectedTableDetails.value?.columns || []);
const selectedTableRows = computed(() => selectedTableDetails.value?.rows || []);
const pagination = computed(() => selectedTableDetails.value?.pagination || null);
const structureRows = computed(() => selectedTableColumns.value);
const primaryKeyColumns = computed(() => selectedTableColumns.value.filter((column) => column.is_primary));

const insertState = reactive({});
const searchTerm = ref('');
const selectedRowKeys = ref(new Set());
const editingRowKey = ref('');
const editDraft = reactive({});
const bulkAction = ref('delete');
const renameTarget = ref('');

const functionOptions = [
    { label: 'None', value: '' },
    { label: 'NOW()', value: 'NOW' },
    { label: 'CURRENT_TIMESTAMP', value: 'CURRENT_TIMESTAMP' },
    { label: 'LOWER', value: 'LOWER' },
    { label: 'UPPER', value: 'UPPER' },
];

const isInsertAction = computed(() => props.activeAction === 'insert');
const isOperationsAction = computed(() => props.activeAction === 'operations');
const title = computed(() => {
    if (props.activeAction === 'operations') return 'Table Operations';
    if (props.activeAction === 'structure') return 'Table Structure';
    if (props.activeAction === 'insert') return 'Insert Row';
    return 'Browse Table';
});

const insertableColumns = computed(() => selectedTableColumns.value.filter((column) => !String(column.extra || '').toLowerCase().includes('auto_increment')));

const isLongTextColumn = (column) => {
    const type = String(column?.type || '').toLowerCase();
    return ['text', 'mediumtext', 'longtext', 'blob', 'json'].some((needle) => type.includes(needle));
};

const isNumericColumn = (column) => {
    const type = String(column?.type || '').toLowerCase();
    return ['int', 'decimal', 'float', 'double', 'numeric', 'bigint', 'smallint', 'mediumint', 'tinyint'].some((needle) => type.includes(needle));
};

const resetInsertValues = () => {
    Object.keys(insertState).forEach((key) => {
        delete insertState[key];
    });

    insertableColumns.value.forEach((column) => {
        insertState[column.name] = {
            value: '',
            function: '',
            useNull: String(column.is_nullable || '').toUpperCase() === 'YES',
        };
    });
};

const rowKeyFor = (row, index) => {
    const pkColumns = primaryKeyColumns.value;
    if (pkColumns.length > 0) {
        return pkColumns.map((column) => String(row?.[column.name] ?? '')).join('__pk__');
    }

    return `row-${index}-${JSON.stringify(row || {})}`;
};

const isEditableRow = (row) => {
    if (primaryKeyColumns.value.length === 0) return false;
    return primaryKeyColumns.value.every((column) => row?.[column.name] !== undefined);
};

const cloneRow = (row) => JSON.parse(JSON.stringify(row || {}));

const startInlineEdit = (row, index) => {
    if (!isEditableRow(row)) return;

    editingRowKey.value = rowKeyFor(row, index);
    Object.keys(editDraft).forEach((field) => {
        delete editDraft[field];
    });
    Object.assign(editDraft, cloneRow(row));
};

const cancelInlineEdit = () => {
    editingRowKey.value = '';
    Object.keys(editDraft).forEach((field) => {
        delete editDraft[field];
    });
};

const saveInlineEdit = (row, index) => {
    if (!isEditableRow(row)) return;

    emit('row-save', {
        original: row,
        draft: cloneRow(editDraft),
        rowKey: rowKeyFor(row, index),
    });
    cancelInlineEdit();
};

const browseRows = computed(() => {
    const rows = selectedTableRows.value;
    const term = String(searchTerm.value || '').trim().toLowerCase();

    if (!term) return rows;

    return rows.filter((row) => Object.values(row).some((value) => String(value ?? '').toLowerCase().includes(term)));
});

const selectedCount = computed(() => selectedRowKeys.value.size);
const allVisibleSelected = computed(() => browseRows.value.length > 0 && selectedCount.value === browseRows.value.length);

const rowRangeLabel = computed(() => {
    const total = pagination.value?.total ?? selectedTableRows.value.length ?? 0;
    if (total === 0) return 'Showing rows 0 - 0 (0 total)';

    const currentPage = pagination.value?.current_page ?? 1;
    const perPage = pagination.value?.per_page ?? props.rowsPerPage ?? 25;
    const from = ((currentPage - 1) * perPage) + 1;
    const visibleCount = browseRows.value.length > 0 ? browseRows.value.length : 0;
    const to = Math.min(from + Math.max(visibleCount, 1) - 1, total);
    const duration = props.queryDurationMs > 0 ? `, Query took ${(Number(props.queryDurationMs) / 1000).toFixed(4)} seconds.` : '';

    return `Showing rows ${from} - ${to} (${total} total${duration})`;
});

const toggleRowSelected = (row, index, checked) => {
    const key = rowKeyFor(row, index);
    const next = new Set(selectedRowKeys.value);
    if (checked) {
        next.add(key);
    } else {
        next.delete(key);
    }
    selectedRowKeys.value = next;
};

const toggleSelectAll = (checked) => {
    if (!checked) {
        selectedRowKeys.value = new Set();
        return;
    }

    const next = new Set();
    browseRows.value.forEach((row, index) => {
        next.add(rowKeyFor(row, index));
    });
    selectedRowKeys.value = next;
};

const bulkActionLabel = computed(() => (bulkAction.value === 'delete' ? 'Delete selected' : 'Bulk action'));

const runBulkAction = () => {
    if (selectedRowKeys.value.size === 0) return;

    if (bulkAction.value === 'delete') {
        emit('bulk-delete', {
            rowKeys: Array.from(selectedRowKeys.value),
            rows: browseRows.value.filter((row, index) => selectedRowKeys.value.has(rowKeyFor(row, index))),
        });
    }
};

watch(
    [() => props.activeAction, () => props.selectedTable, () => props.tableDetails],
    () => {
        selectedRowKeys.value = new Set();
        cancelInlineEdit();
        searchTerm.value = '';
        if (props.activeAction === 'insert') {
            resetInsertValues();
        }
    },
    { immediate: true }
);

watch(
    [insertableColumns, () => props.activeAction, () => props.selectedTable],
    () => {
        if (props.activeAction === 'insert') {
            resetInsertValues();
        }
    },
    { immediate: true }
);

const submitInsert = () => {
    emit('insert-submit', {
        rows: insertableColumns.value.map((column) => ({
            name: column.name,
            value: insertState[column.name]?.value ?? '',
            function: insertState[column.name]?.function ?? '',
            useNull: Boolean(insertState[column.name]?.useNull),
            type: column.type || '',
            extra: column.extra || '',
            defaultValue: column.default_value ?? null,
        })),
    });
};

const submitRename = () => {
    emit('table-rename', {
        newTable: renameTarget.value,
    });
};

watch(
    [() => props.selectedTable, () => props.activeAction],
    () => {
        if (props.activeAction === 'operations') {
            renameTarget.value = props.selectedTable || '';
        }
    },
    { immediate: true }
);
</script>

<template>
    <section
        class="flex h-full min-h-0 flex-col p-5 xl:col-span-2"
        :class="plain ? 'bg-transparent shadow-none' : 'rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900'"
    >
        <div class="mb-4 flex items-center justify-between gap-3">
            <div>
                <h2 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">{{ title }}</h2>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    {{ selectedDatabase && selectedTable ? `${selectedDatabase}.${selectedTable}` : 'Choose a table to inspect rows or structure.' }}
                </p>
            </div>
            <div class="flex items-center gap-2">
                <span class="rounded-full border border-cyan-200 bg-cyan-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-cyan-700 dark:border-cyan-900/50 dark:bg-cyan-950/30 dark:text-cyan-300">
                    {{ activeAction === 'browse' ? 'Browse active' : activeAction === 'structure' ? 'Structure active' : activeAction === 'insert' ? 'Insert active' : activeAction === 'operations' ? 'Operations active' : activeAction }}
                </span>
                <button
                    v-if="activeAction === 'structure' && selectedTableDetails"
                    type="button"
                    class="rounded-full border border-cyan-200 bg-cyan-50 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-cyan-700 hover:bg-cyan-100 dark:border-cyan-900/50 dark:bg-cyan-950/30 dark:text-cyan-300 dark:hover:bg-cyan-950/50"
                    @click="emit('edit-structure')"
                >
                    Edit Structure
                </button>
                <div v-if="pagination" class="text-xs text-slate-500 dark:text-slate-400">
                    Page {{ pagination.current_page }} of {{ pagination.last_page }}
                </div>
            </div>
        </div>

        <div v-if="loading" class="rounded-xl border border-dashed border-slate-300 p-4 text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">
            Loading table rows...
        </div>

        <div v-else-if="error" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-300">
            {{ error }}
        </div>

        <div v-else-if="selectedTableDetails && activeAction === 'structure'" class="rounded-xl border border-slate-200 dark:border-slate-800">
            <table class="min-w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-[0.14em] text-slate-500 dark:bg-slate-800">
                    <tr>
                        <th class="px-4 py-3">Column</th>
                        <th class="px-4 py-3">Type</th>
                        <th class="px-4 py-3">Null</th>
                        <th class="px-4 py-3">Default</th>
                        <th class="px-4 py-3">Extra</th>
                        <th class="px-4 py-3">Key</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="column in structureRows" :key="column.name" class="border-t border-slate-200 dark:border-slate-800">
                        <td class="px-4 py-3 font-medium text-slate-900 dark:text-slate-100">{{ column.name }}</td>
                        <td class="px-4 py-3 text-slate-700 dark:text-slate-300">{{ column.type || '-' }}</td>
                        <td class="px-4 py-3 text-slate-700 dark:text-slate-300">{{ column.is_nullable || '-' }}</td>
                        <td class="px-4 py-3 text-slate-700 dark:text-slate-300">{{ column.default_value ?? '-' }}</td>
                        <td class="px-4 py-3 text-slate-700 dark:text-slate-300">{{ column.extra || '-' }}</td>
                        <td class="px-4 py-3 text-slate-700 dark:text-slate-300">{{ column.key || '-' }}</td>
                    </tr>
                    <tr v-if="structureRows.length === 0">
                        <td colspan="6" class="px-4 py-6 text-center text-slate-500 dark:text-slate-400">
                            No structure information available.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-else-if="selectedTableDetails && isOperationsAction" class="space-y-4">
            <div class="rounded-xl border border-cyan-200 bg-cyan-50 p-4 dark:border-cyan-900/40 dark:bg-cyan-950/20">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-cyan-700 dark:text-cyan-300">Operations</p>
                <h3 class="mt-1 text-lg font-semibold text-slate-900 dark:text-slate-100">Rename table</h3>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">
                    Rename <span class="font-semibold">{{ selectedDatabase }}</span>.<span class="font-semibold">{{ selectedTable }}</span> without changing the data.
                </p>
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-2 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500 dark:text-slate-400">
                            Current table
                        </label>
                        <input
                            type="text"
                            :value="selectedTable"
                            disabled
                            class="w-full rounded-md border border-slate-300 bg-slate-50 px-3 py-2 text-sm text-slate-700 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-300"
                        >
                    </div>

                    <div>
                        <label class="mb-2 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500 dark:text-slate-400">
                            New table name
                        </label>
                        <input
                            v-model="renameTarget"
                            type="text"
                            placeholder="Enter new table name"
                            class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-cyan-400 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                        >
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap gap-3">
                        <button
                            type="button"
                            class="rounded-md bg-cyan-600 px-4 py-2 text-sm font-medium text-white hover:bg-cyan-500 disabled:cursor-not-allowed disabled:opacity-60"
                            :disabled="renameBusy || !renameTarget.trim() || renameTarget.trim() === selectedTable"
                            @click="submitRename"
                        >
                            {{ renameBusy ? 'Renaming...' : 'Rename Table' }}
                        </button>
                    <button
                        type="button"
                        class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                        @click="renameTarget = selectedTable || ''"
                    >
                        Reset
                    </button>
                </div>
            </div>
        </div>

        <div v-else-if="selectedTableDetails && isInsertAction" class="rounded-xl border border-slate-200 p-4 dark:border-slate-800">
            <div class="mb-4 flex items-center justify-between gap-3">
                <div>
                    <h3 class="text-sm font-semibold uppercase tracking-[0.16em] text-slate-500">Insert Row</h3>
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        Fill the values below for {{ selectedDatabase }}.{{ selectedTable }} and submit a new record.
                    </p>
                </div>
                <span class="text-xs text-slate-500 dark:text-slate-400">
                    {{ insertableColumns.length }} fields
                </span>
            </div>

            <form class="space-y-4" @submit.prevent="submitInsert">
                <div class="overflow-hidden rounded-xl border border-slate-200 dark:border-slate-800">
                    <table class="min-w-full border-collapse text-left text-sm">
                        <thead class="bg-slate-100 text-xs uppercase tracking-[0.14em] text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                            <tr>
                                <th class="w-[18%] px-4 py-3">Column</th>
                                <th class="w-[18%] px-4 py-3">Type</th>
                                <th class="w-[18%] px-4 py-3">Function</th>
                                <th class="w-[8%] px-4 py-3">Null</th>
                                <th class="px-4 py-3">Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(column, index) in insertableColumns"
                                :key="column.name"
                                class="border-t border-slate-200 align-top dark:border-slate-800"
                                :class="index % 2 === 0 ? 'bg-slate-50/70 dark:bg-slate-950/20' : 'bg-white dark:bg-slate-900'"
                            >
                                <td class="px-4 py-4 font-medium text-slate-900 dark:text-slate-100">
                                    {{ column.name }}
                                </td>
                                <td class="px-4 py-4 text-slate-700 dark:text-slate-300">
                                    {{ column.type || '-' }}
                                </td>
                                <td class="px-4 py-4">
                                    <select
                                        v-model="insertState[column.name].function"
                                        class="w-full rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm text-slate-900 outline-none focus:border-cyan-400 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                                    >
                                        <option v-for="option in functionOptions" :key="option.value || option.label" :value="option.value">
                                            {{ option.label }}
                                        </option>
                                    </select>
                                </td>
                                <td class="px-4 py-4 text-center">
                                    <input
                                        v-model="insertState[column.name].useNull"
                                        type="checkbox"
                                        class="h-4 w-4 rounded border-slate-300 text-cyan-600 focus:ring-cyan-500 dark:border-slate-700"
                                        :disabled="String(column.extra || '').toLowerCase().includes('auto_increment')"
                                    >
                                </td>
                                <td class="px-4 py-3">
                                    <textarea
                                        v-if="isLongTextColumn(column)"
                                        v-model="insertState[column.name].value"
                                        rows="5"
                                        class="min-h-[120px] w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-cyan-400 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                                        :placeholder="column.default_value ?? 'Enter value'"
                                        :disabled="insertState[column.name].useNull"
                                    />
                                    <input
                                        v-else
                                        v-model="insertState[column.name].value"
                                        :type="isNumericColumn(column) ? 'number' : 'text'"
                                        class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-cyan-400 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                                        :placeholder="column.default_value ?? 'Enter value'"
                                        :disabled="insertState[column.name].useNull"
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="flex items-center justify-between gap-3">
                    <button
                        type="reset"
                        class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                        @click="resetInsertValues"
                    >
                        Clear
                    </button>
                    <button
                        type="submit"
                        class="rounded-md bg-cyan-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-cyan-500"
                    >
                        Insert Row
                    </button>
                </div>
            </form>
        </div>

        <div v-else-if="selectedTableDetails && activeAction === 'browse'" class="space-y-3">
            <div class="rounded-xl border border-lime-200 bg-lime-50 px-4 py-3 text-sm text-lime-900 dark:border-lime-900/50 dark:bg-lime-950/30 dark:text-lime-100">
                {{ rowRangeLabel }}
                <span v-if="queryLabel" class="mt-1 block text-xs text-lime-800/80 dark:text-lime-100/80">
                    {{ queryLabel }}
                </span>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm dark:border-slate-800 dark:bg-slate-950/40">
                <div class="flex flex-wrap items-center gap-3">
                    <label class="flex items-center gap-2 text-slate-600 dark:text-slate-300">
                        <span>Show</span>
                        <select
                            :value="pagination?.per_page || rowsPerPage"
                            class="rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"
                            @change="emit('per-page-change', Number($event.target.value))"
                        >
                            <option :value="10">10</option>
                            <option :value="25">25</option>
                            <option :value="50">50</option>
                            <option :value="100">100</option>
                        </select>
                    </label>
                    <label class="flex items-center gap-2 text-slate-600 dark:text-slate-300">
                        <span>Search</span>
                        <input
                            v-model="searchTerm"
                            type="search"
                            placeholder="Search this table"
                            class="w-56 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-cyan-400 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"
                        >
                    </label>
                </div>

                <div class="flex items-center gap-2">
                    <select
                        v-model="bulkAction"
                        class="rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"
                    >
                        <option value="delete">Delete selected</option>
                    </select>
                    <button
                        type="button"
                        class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white transition hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white"
                        :disabled="selectedCount === 0"
                        @click="runBulkAction"
                    >
                        {{ bulkActionLabel }}
                    </button>
                </div>
            </div>

            <div class="overflow-hidden rounded-xl border border-slate-200 dark:border-slate-800">
                <div class="overflow-x-auto">
                    <table class="min-w-full border-collapse text-left text-sm">
                        <thead class="bg-slate-100 text-xs uppercase tracking-[0.14em] text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                            <tr>
                                <th class="w-10 px-3 py-3">
                                    <input
                                        type="checkbox"
                                        :checked="allVisibleSelected"
                                        class="h-4 w-4 rounded border-slate-300 text-cyan-600 focus:ring-cyan-500 dark:border-slate-700"
                                        @change="toggleSelectAll($event.target.checked)"
                                    >
                                </th>
                                <th v-for="column in selectedTableColumns" :key="column.name" class="px-4 py-3">
                                    {{ column.name }}
                                </th>
                                <th class="w-36 px-4 py-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(row, index) in browseRows"
                                :key="rowKeyFor(row, index)"
                                class="group border-t border-slate-200 dark:border-slate-800"
                                :class="editingRowKey === rowKeyFor(row, index) ? 'bg-cyan-50/70 dark:bg-cyan-950/20' : index % 2 === 0 ? 'bg-white dark:bg-slate-900' : 'bg-slate-50/60 dark:bg-slate-950/20'"
                                @dblclick="startInlineEdit(row, index)"
                            >
                                <td class="px-3 py-3 align-top">
                                    <input
                                        type="checkbox"
                                        :checked="selectedRowKeys.has(rowKeyFor(row, index))"
                                        class="h-4 w-4 rounded border-slate-300 text-cyan-600 focus:ring-cyan-500 dark:border-slate-700"
                                        @change="toggleRowSelected(row, index, $event.target.checked)"
                                    >
                                </td>

                                <template v-if="editingRowKey === rowKeyFor(row, index)">
                                    <td v-for="column in selectedTableColumns" :key="column.name" class="max-w-[220px] px-4 py-3 align-top">
                                        <textarea
                                            v-if="isLongTextColumn(column)"
                                            v-model="editDraft[column.name]"
                                            rows="4"
                                            class="min-h-[90px] w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-cyan-400 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"
                                        />
                                        <input
                                            v-else
                                            v-model="editDraft[column.name]"
                                            :type="isNumericColumn(column) ? 'number' : 'text'"
                                            class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-cyan-400 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"
                                        >
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex flex-wrap gap-2">
                                            <button
                                                type="button"
                                                class="rounded-md bg-cyan-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-cyan-500"
                                                @click="saveInlineEdit(row, index)"
                                            >
                                                Save
                                            </button>
                                            <button
                                                type="button"
                                                class="rounded-md border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                                                @click="cancelInlineEdit"
                                            >
                                                Cancel
                                            </button>
                                        </div>
                                    </td>
                                </template>

                                <template v-else>
                                    <td v-for="column in selectedTableColumns" :key="column.name" class="max-w-[220px] px-4 py-3 align-top">
                                        <span class="block truncate" :title="String(row[column.name] ?? '')">
                                            {{ row[column.name] ?? 'NULL' }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex flex-wrap gap-2">
                                            <button
                                                type="button"
                                                class="rounded-md border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                                                @click="startInlineEdit(row, index)"
                                            >
                                                Edit
                                            </button>
                                            <button
                                                type="button"
                                                class="rounded-md border border-rose-300 px-3 py-1.5 text-xs font-medium text-rose-700 hover:bg-rose-50 dark:border-rose-800 dark:text-rose-300 dark:hover:bg-rose-950/30"
                                                @click="emit('row-delete', { row, rowKey: rowKeyFor(row, index) })"
                                            >
                                                Delete
                                            </button>
                                        </div>
                                    </td>
                                </template>
                            </tr>
                            <tr v-if="browseRows.length === 0">
                                <td :colspan="selectedTableColumns.length + 2" class="px-4 py-6 text-center text-slate-500 dark:text-slate-400">
                                    No rows returned for the current page.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-if="pagination" class="flex flex-wrap items-center justify-between gap-3 text-sm">
                <span class="text-slate-500 dark:text-slate-400">
                    {{ pagination.total }} total rows · {{ pagination.per_page }} per page
                </span>
                <div class="flex gap-2">
                    <button
                        v-if="pagination.has_previous"
                        type="button"
                        class="rounded-md border border-slate-300 px-3 py-2 hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800"
                        @click="emit('paginate', pagination.current_page - 1)"
                    >
                        Previous
                    </button>
                    <button
                        v-if="pagination.has_more"
                        type="button"
                        class="rounded-md border border-slate-300 px-3 py-2 hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800"
                        @click="emit('paginate', pagination.current_page + 1)"
                    >
                        Next
                    </button>
                </div>
            </div>
        </div>

        <div v-else-if="selectedTableDetails" class="rounded-xl border border-slate-200 dark:border-slate-800">
            <table class="min-w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-[0.14em] text-slate-500 dark:bg-slate-800">
                    <tr>
                        <th v-for="column in selectedTableColumns" :key="column.name" class="px-4 py-3">
                            {{ column.name }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, index) in selectedTableRows" :key="index" class="border-t border-slate-200 dark:border-slate-800">
                        <td v-for="column in selectedTableColumns" :key="column.name" class="max-w-[220px] px-4 py-3 align-top">
                            <span class="block truncate" :title="String(row[column.name] ?? '')">
                                {{ row[column.name] ?? 'NULL' }}
                            </span>
                        </td>
                    </tr>
                    <tr v-if="selectedTableRows.length === 0">
                        <td :colspan="Math.max(selectedTableColumns.length, 1)" class="px-4 py-6 text-center text-slate-500 dark:text-slate-400">
                            No rows returned for the current page.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="pagination && activeAction !== 'browse'" class="mt-4 flex items-center justify-between gap-3 text-sm">
            <span class="text-slate-500 dark:text-slate-400">
                {{ pagination.total }} total rows · {{ pagination.per_page }} per page
            </span>
            <div class="flex gap-2">
                <button
                    v-if="pagination.has_previous"
                    type="button"
                    class="rounded-md border border-slate-300 px-3 py-2 hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800"
                    @click="emit('paginate', pagination.current_page - 1)"
                >
                    Previous
                </button>
                <button
                    v-if="pagination.has_more"
                    type="button"
                    class="rounded-md border border-slate-300 px-3 py-2 hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800"
                    @click="emit('paginate', pagination.current_page + 1)"
                >
                    Next
                </button>
            </div>
        </div>
    </section>
</template>
