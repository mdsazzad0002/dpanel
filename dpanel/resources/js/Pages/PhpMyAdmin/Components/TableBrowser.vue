<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';

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
    sortColumn: {
        type: String,
        default: '',
    },
    sortDirection: {
        type: String,
        default: 'asc',
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
    'sort-change',
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
const editingCell = ref({ rowKey: '', rowIndex: -1, columnName: '', columnIndex: -1 });
const editDraft = ref('');
const suppressBlurSave = ref(false);
const editSessionId = ref(0);
const bulkAction = ref('delete');
const renameTarget = ref('');
const editInputRef = ref(null);
const expandedCells = ref(new Set());
const copyFeedback = ref('');
const structureMoreOpenFor = ref('');
const normalizedSortDirection = computed(() => (String(props.sortDirection || '').toLowerCase() === 'desc' ? 'desc' : 'asc'));

const isSortedColumn = (columnName) => String(columnName || '') === String(props.sortColumn || '');

const handleColumnSort = (columnName) => {
    if (!columnName) return;

    const nextDirection = isSortedColumn(columnName) && normalizedSortDirection.value === 'asc' ? 'desc' : 'asc';
    emit('sort-change', {
        column: columnName,
        direction: nextDirection,
    });
};

const toggleCellExpand = (rowKey, columnName) => {
    const key = `${rowKey}-${columnName}`;
    const next = new Set(expandedCells.value);
    if (next.has(key)) {
        next.delete(key);
    } else {
        next.add(key);
    }
    expandedCells.value = next;
};

const copyCellValue = async (value) => {
    const text = String(value ?? '');
    try {
        await navigator.clipboard.writeText(text);
        copyFeedback.value = 'Copied!';
        setTimeout(() => { copyFeedback.value = ''; }, 1500);
    } catch {
        // Fallback
        const textarea = document.createElement('textarea');
        textarea.value = text;
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        copyFeedback.value = 'Copied!';
        setTimeout(() => { copyFeedback.value = ''; }, 1500);
    }
};

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

const getEditTarget = () => {
    const target = editInputRef.value;
    if (!target) return null;
    return Array.isArray(target) ? target[0] : target;
};

const focusEditInput = async () => {
    await nextTick();

    const target = getEditTarget();
    if (!target) return;

    const focusTarget = typeof target.focus === 'function' ? target : target?.$el;
    if (focusTarget && typeof focusTarget.focus === 'function') {
        focusTarget.focus();
    }

    const selectTarget = typeof target.select === 'function' ? target : target?.$el;
    if (selectTarget && typeof selectTarget.select === 'function') {
        selectTarget.select();
    }
};

const isCellEditing = (rowKey, columnName) => {
    return editingCell.value.rowKey === rowKey && editingCell.value.columnName === columnName;
};

const getEditableCell = (rowIndex, columnIndex) => {
    const row = browseRows.value[rowIndex];
    const column = selectedTableColumns.value[columnIndex];

    if (!row || !column) return null;

    return {
        row,
        rowIndex,
        column,
        columnIndex,
        rowKey: rowKeyFor(row, rowIndex),
    };
};

const startRowEdit = async (row, rowIndex) => {
    const firstCell = getEditableCell(rowIndex, 0);
    if (!firstCell) return;

    await startCellEdit(firstCell.row, firstCell.column, firstCell.rowIndex, firstCell.columnIndex);
};

const startCellEdit = async (row, column, rowIndex, columnIndex) => {
    editSessionId.value += 1;
    const rowKey = rowKeyFor(row, rowIndex);
    editingCell.value = {
        rowKey,
        rowIndex,
        columnName: column.name,
        columnIndex,
    };
    editDraft.value = String(row[column.name] ?? '');
    await focusEditInput();
    suppressBlurSave.value = false;
};

const cancelCellEdit = () => {
    editSessionId.value += 1;
    suppressBlurSave.value = false;
    editingCell.value = { rowKey: '', rowIndex: -1, columnName: '', columnIndex: -1 };
    editDraft.value = '';
};

const moveToAdjacentCell = async (direction) => {
    const { rowIndex, columnIndex } = editingCell.value;
    const totalRows = browseRows.value.length;

    if (totalRows === 0 || columnIndex < 0) {
        cancelCellEdit();
        return;
    }

    const nextRowIndex = rowIndex + direction;
    if (nextRowIndex < 0 || nextRowIndex >= totalRows) {
        cancelCellEdit();
        return;
    }

    const nextCell = getEditableCell(nextRowIndex, columnIndex);
    if (!nextCell) {
        cancelCellEdit();
        return;
    }

    await startCellEdit(nextCell.row, nextCell.column, nextCell.rowIndex, nextCell.columnIndex);
};

const saveCellEdit = async (row, column, rowIndex, columnIndex, options = {}) => {
    const rowKey = rowKeyFor(row, rowIndex);
    const originalValue = String(row[column.name] ?? '');
    const newValue = editDraft.value;
    const shouldAdvance = Boolean(options.advance);
    const direction = options.direction ?? 1;
    const sessionId = editSessionId.value;

    const finishSuccess = async () => {
        if (sessionId !== editSessionId.value) {
            return;
        }

        suppressBlurSave.value = true;
        if (shouldAdvance) {
            await moveToAdjacentCell(direction);
            return;
        }

        cancelCellEdit();
    };

    const finishError = () => {
        if (sessionId !== editSessionId.value) {
            return;
        }

        editingCell.value = {
            rowKey,
            rowIndex,
            columnName: column.name,
            columnIndex,
        };
        editDraft.value = originalValue;
        void focusEditInput();
    };

    // Only save if value changed
    if (originalValue !== newValue) {
        const updatedRow = cloneRow(row);
        updatedRow[column.name] = newValue;
        emit('row-save', {
            original: row,
            draft: updatedRow,
            rowKey,
            rowIndex,
            columnName: column.name,
            previousValue: originalValue,
            onSuccess: finishSuccess,
            onError: finishError,
        });
        return;
    }

    await finishSuccess();
};

const handleCellKeydown = async (event, row, column, rowIndex, columnIndex) => {
    if (event.key === 'Enter') {
        event.preventDefault();
        suppressBlurSave.value = true;
        await saveCellEdit(row, column, rowIndex, columnIndex, { advance: true, direction: 1 });
    } else if (event.key === 'Escape') {
        event.preventDefault();
        cancelCellEdit();
    } else if (event.key === 'Tab') {
        event.preventDefault();
        suppressBlurSave.value = true;
        await saveCellEdit(row, column, rowIndex, columnIndex, {
            advance: true,
            direction: event.shiftKey ? -1 : 1,
        });
    } else if (event.key === 'ArrowDown') {
        event.preventDefault();
        suppressBlurSave.value = true;
        await saveCellEdit(row, column, rowIndex, columnIndex, { advance: true, direction: 1 });
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        suppressBlurSave.value = true;
        await saveCellEdit(row, column, rowIndex, columnIndex, { advance: true, direction: -1 });
    }
};

const handleCellBlur = async (event, row, column, rowIndex, columnIndex) => {
    if (suppressBlurSave.value) {
        suppressBlurSave.value = false;
        return;
    }

    await saveCellEdit(row, column, rowIndex, columnIndex);
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
        cancelCellEdit();
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

const openStructureEditor = (column = null) => {
    emit('edit-structure', {
        action: 'change',
        column: column?.name || '',
    });
};

const openColumnDrop = (column = null) => {
    emit('edit-structure', {
        action: 'drop',
        column: column?.name || '',
    });
};

const toggleMoreMenu = (columnName) => {
    structureMoreOpenFor.value = structureMoreOpenFor.value === columnName ? '' : columnName;
};

const closeMoreMenu = () => {
    structureMoreOpenFor.value = '';
};

const copyStructureText = async (text) => {
    const value = String(text ?? '');
    try {
        await navigator.clipboard.writeText(value);
        copyFeedback.value = 'Copied!';
        setTimeout(() => { copyFeedback.value = ''; }, 1500);
    } catch {
        const textarea = document.createElement('textarea');
        textarea.value = value;
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        copyFeedback.value = 'Copied!';
        setTimeout(() => { copyFeedback.value = ''; }, 1500);
    }
};

const buildColumnDefinitionLabel = (column) => {
    if (!column) return '';

    const pieces = [
        column.name || '',
        column.type || '',
        column.collation || '',
        column.extra || '',
    ].filter(Boolean);

    return pieces.join(' | ');
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

onMounted(() => {
    document.addEventListener('click', closeMoreMenu);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', closeMoreMenu);
});
</script>

<template>
    <section
        class="flex h-full min-h-0 flex-col"
        :class="plain ? 'bg-transparent' : 'rounded-3xl border border-slate-800 bg-[#08111d] shadow-[0_24px_80px_rgba(0,0,0,0.35)]'"
    >
        <div class="border-b border-slate-800 px-4 py-4 sm:px-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.28em] text-slate-500">Browse table</p>
                    <h2 class="mt-1 truncate text-lg font-semibold text-slate-100">
                        {{ selectedDatabase && selectedTable ? `${selectedDatabase}.${selectedTable}` : 'Choose a table to inspect rows or structure.' }}
                    </h2>
                    <p class="mt-1 text-sm text-slate-400">
                        {{ title }}
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded-full border border-cyan-400/30 bg-cyan-500/10 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-cyan-200">
                        {{ activeAction === 'browse' ? 'Browse active' : activeAction === 'structure' ? 'Structure active' : activeAction === 'insert' ? 'Insert active' : activeAction === 'operations' ? 'Operations active' : activeAction }}
                    </span>
                    <button
                        v-if="activeAction === 'structure' && selectedTableDetails"
                        type="button"
                        class="rounded-full border border-slate-700 bg-slate-900 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-200 hover:bg-slate-800"
                        @click="emit('edit-structure')"
                    >
                        Edit Structure
                    </button>
                    <span v-if="pagination" class="rounded-full border border-slate-700 bg-slate-900 px-3 py-1 text-[11px] font-medium text-slate-400">
                        Page {{ pagination.current_page }} of {{ pagination.last_page }}
                    </span>
                </div>
            </div>
        </div>

        <div v-if="loading" class="m-4 rounded-2xl border border-dashed border-slate-700 bg-[#0b1220] px-4 py-4 text-sm text-slate-400">
            Loading table rows...
        </div>

        <div v-else-if="error" class="m-4 rounded-2xl border border-red-500/30 bg-red-500/10 px-4 py-4 text-sm text-red-200">
            {{ error }}
        </div>

        <template v-else-if="selectedTableDetails && activeAction === 'structure'">
            <div class="m-4  rounded-2xl border border-slate-800 bg-[#0b1220]">
                <div class="border-b border-slate-800 px-4 py-3 text-sm text-slate-400">
                    Structure for <span class="font-semibold text-slate-200">{{ selectedDatabase }}.{{ selectedTable }}</span>
                </div>
                <div >
                <table class="min-w-full border-collapse text-left text-sm">
                    <thead class="sticky top-0 z-10 bg-[#111a2d] text-xs uppercase tracking-[0.16em] text-slate-400">
                        <tr>
                            <th class="w-12 px-4 py-3 text-center">#</th>
                            <th class="px-4 py-3 text-center">Action</th>
                            <th class="px-4 py-3">Name</th>
                            <th class="px-4 py-3">Type</th>
                            <th class="px-4 py-3">Collation</th>
                            <th class="px-4 py-3">Attributes</th>
                            <th class="px-4 py-3 text-center">Null</th>
                            <th class="px-4 py-3">Default</th>
                            <th class="px-4 py-3">Comments</th>
                            <th class="px-4 py-3">Extra</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(column, index) in structureRows" :key="column.name" class="border-t border-slate-800">
                               <td class="px-4 py-3 text-center text-slate-400">{{ index + 1 }}</td>
                            <td class="px-4 py-3">
                                <div class="relative flex items-center justify-center gap-2">
                                    <button
                                        type="button"
                                        class="rounded-md border border-cyan-500/30 px-3 py-1.5 text-xs font-medium text-cyan-200 transition hover:bg-cyan-500/10"
                                        @click="openStructureEditor(column)"
                                    >
                                        Change
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded-md border border-rose-500/30 px-3 py-1.5 text-xs font-medium text-rose-200 transition hover:bg-rose-500/10"
                                        @click="openColumnDrop(column)"
                                    >
                                        Drop
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded-md border border-slate-600 px-2.5 py-1.5 text-xs font-medium text-slate-300 transition hover:bg-white/5"
                                        @click.stop="toggleMoreMenu(column.name)"
                                    >
                                        More
                                    </button>
                                    <div
                                        v-if="structureMoreOpenFor === column.name"
                                        class="absolute right-0 top-full z-20 mt-2 w-56 rounded-lg border border-slate-700 bg-[#0b1220] py-1 shadow-[0_18px_40px_rgba(0,0,0,0.45)]"
                                    >
                                        <button
                                            type="button"
                                            class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-slate-300 hover:bg-white/5"
                                            @click="copyStructureText(column.name)"
                                        >
                                            <i class="bi bi-copy text-xs text-slate-500"></i>
                                            Copy column name
                                        </button>
                                        <button
                                            type="button"
                                            class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-slate-300 hover:bg-white/5"
                                            @click="copyStructureText(buildColumnDefinitionLabel(column))"
                                        >
                                            <i class="bi bi-clipboard text-xs text-slate-500"></i>
                                            Copy definition
                                        </button>
                                        <button
                                            type="button"
                                            class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-slate-300 hover:bg-white/5"
                                            @click="openStructureEditor(column)"
                                        >
                                            <i class="bi bi-pencil text-xs text-slate-500"></i>
                                            Open editor
                                        </button>
                                    </div>
                                </div>
                            </td>

                            <td class="px-4 py-3 font-medium text-slate-100">{{ column.name }}</td>
                            <td class="px-4 py-3 text-slate-300">{{ column.type || '-' }}</td>
                            <td class="px-4 py-3 text-slate-300">{{ column.collation || '-' }}</td>
                            <td class="px-4 py-3 text-slate-300">{{ column.unsigned ? 'UNSIGNED' : '-' }}</td>
                            <td class="px-4 py-3 text-center text-slate-300">{{ column.is_nullable || '-' }}</td>
                            <td class="px-4 py-3 text-slate-300">{{ column.default_value ?? '-' }}</td>
                            <td class="px-4 py-3 text-slate-300">{{ column.comment || '-' }}</td>
                            <td class="px-4 py-3 text-slate-300">{{ column.extra || '-' }}</td>

                        </tr>
                        <tr v-if="structureRows.length === 0">
                            <td colspan="10" class="px-4 py-6 text-center text-slate-500">
                                No structure information available.
                            </td>
                        </tr>
                    </tbody>
                </table>
                </div>
            </div>
        </template>

        <template v-else-if="selectedTableDetails && isOperationsAction">
            <div class="m-4 space-y-4">
                <div class="rounded-2xl border border-cyan-500/20 bg-cyan-500/10 px-4 py-4 text-cyan-100">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-cyan-200">Operations</p>
                    <h3 class="mt-1 text-lg font-semibold text-white">Rename table</h3>
                    <p class="mt-1 text-sm text-cyan-100/80">
                        Rename <span class="font-semibold">{{ selectedDatabase }}</span>.<span class="font-semibold">{{ selectedTable }}</span> without changing the data.
                    </p>
                </div>

                <div class="rounded-2xl border border-slate-800 bg-[#0b1220] p-4">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-2 block text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">
                                Current table
                            </label>
                            <input
                                type="text"
                                :value="selectedTable"
                                disabled
                                class="w-full rounded-xl border border-slate-700 bg-[#111a2d] px-3 py-2 text-sm text-slate-300"
                            >
                        </div>
                        <div>
                            <label class="mb-2 block text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">
                                New table name
                            </label>
                            <input
                                v-model="renameTarget"
                                type="text"
                                placeholder="Enter new table name"
                                class="w-full rounded-xl border border-slate-700 bg-[#111a2d] px-3 py-2 text-sm text-slate-100 outline-none placeholder:text-slate-500 focus:border-cyan-500"
                            >
                        </div>
                    </div>

                    <div class="mt-4 flex flex-wrap gap-3">
                        <button
                            type="button"
                            class="rounded-xl bg-cyan-600 px-4 py-2 text-sm font-medium text-white hover:bg-cyan-500 disabled:cursor-not-allowed disabled:opacity-60"
                            :disabled="renameBusy || !renameTarget.trim() || renameTarget.trim() === selectedTable"
                            @click="submitRename"
                        >
                            {{ renameBusy ? 'Renaming...' : 'Rename Table' }}
                        </button>
                        <button
                            type="button"
                            class="rounded-xl border border-slate-700 px-4 py-2 text-sm font-medium text-slate-200 hover:bg-white/5"
                            @click="renameTarget = selectedTable || ''"
                        >
                            Reset
                        </button>
                    </div>
                </div>
            </div>
        </template>

        <template v-else-if="selectedTableDetails && isInsertAction">
            <div class="m-4 rounded-2xl border border-slate-800 bg-[#0b1220] p-4">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">Insert Row</h3>
                        <p class="text-sm text-slate-400">
                            Fill the values below for {{ selectedDatabase }}.{{ selectedTable }} and submit a new record.
                        </p>
                    </div>
                    <span class="text-xs text-slate-500">
                        {{ insertableColumns.length }} fields
                    </span>
                </div>

                <form class="space-y-4" @submit.prevent="submitInsert">
                    <div class="overflow-hidden rounded-2xl border border-slate-800">
                        <table class="min-w-full border-collapse text-left text-sm">
                            <thead class="bg-[#111a2d] text-xs uppercase tracking-[0.16em] text-slate-400">
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
                                    class="border-t border-slate-800 align-top"
                                    :class="index % 2 === 0 ? 'bg-[#0b1220]' : 'bg-[#0e1627]'"
                                >
                                    <td class="px-4 py-4 font-medium text-slate-100">{{ column.name }}</td>
                                    <td class="px-4 py-4 text-slate-300">{{ column.type || '-' }}</td>
                                    <td class="px-4 py-4">
                                        <select
                                            v-model="insertState[column.name].function"
                                            class="w-full rounded-xl border border-slate-700 bg-[#111a2d] px-2 py-1.5 text-sm text-slate-100 outline-none focus:border-cyan-500"
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
                                            class="h-4 w-4 rounded border-slate-700 text-cyan-500 focus:ring-cyan-500"
                                            :disabled="String(column.extra || '').toLowerCase().includes('auto_increment')"
                                        >
                                    </td>
                                    <td class="px-4 py-3">
                                        <textarea
                                            v-if="isLongTextColumn(column)"
                                            v-model="insertState[column.name].value"
                                            rows="5"
                                            class="min-h-[120px] w-full rounded-xl border border-slate-700 bg-[#111a2d] px-3 py-2 text-sm text-slate-100 outline-none focus:border-cyan-500"
                                            :placeholder="column.default_value ?? 'Enter value'"
                                            :disabled="insertState[column.name].useNull"
                                        />
                                        <input
                                            v-else
                                            v-model="insertState[column.name].value"
                                            :type="isNumericColumn(column) ? 'number' : 'text'"
                                            class="w-full rounded-xl border border-slate-700 bg-[#111a2d] px-3 py-2 text-sm text-slate-100 outline-none focus:border-cyan-500"
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
                            class="rounded-xl border border-slate-700 px-4 py-2 text-sm font-medium text-slate-200 hover:bg-white/5"
                            @click="resetInsertValues"
                        >
                            Clear
                        </button>
                        <button
                            type="submit"
                            class="rounded-xl bg-cyan-600 px-4 py-2 text-sm font-medium text-white hover:bg-cyan-500"
                        >
                            Insert Row
                        </button>
                    </div>
                </form>
            </div>
        </template>

        <template v-else-if="selectedTableDetails && activeAction === 'browse'">
            <div class="m-4 space-y-3">
                <div class="rounded-2xl border border-lime-500/20 bg-lime-500/10 px-4 py-3 text-sm text-lime-100">
                    {{ rowRangeLabel }}
                    <span v-if="queryLabel" class="mt-1 block text-xs text-lime-100/80">
                        {{ queryLabel }}
                    </span>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-800 bg-[#0b1220] px-4 py-3">
                    <div class="flex flex-wrap items-center gap-3">
                        <label class="flex items-center gap-2 text-slate-300">
                            <span>Show</span>
                            <select
                                :value="pagination?.per_page || rowsPerPage"
                                class="rounded-xl border border-slate-700 bg-[#111a2d] px-2 py-1.5 text-sm text-slate-100 outline-none focus:border-cyan-500"
                                @change="emit('per-page-change', Number($event.target.value))"
                            >
                                <option :value="10">10</option>
                                <option :value="25">25</option>
                                <option :value="50">50</option>
                                <option :value="100">100</option>
                            </select>
                        </label>
                        <label class="flex items-center gap-2 text-slate-300">
                            <span>Search</span>
                            <input
                                v-model="searchTerm"
                                type="search"
                                placeholder="Search this table"
                                class="w-56 rounded-xl border border-slate-700 bg-[#111a2d] px-3 py-2 text-sm text-slate-100 outline-none placeholder:text-slate-500 focus:border-cyan-500"
                            >
                        </label>
                    </div>

                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            class="rounded-xl border border-slate-700 bg-[#111a2d] px-4 py-2 text-sm font-medium text-slate-200 hover:bg-white/5 disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="selectedCount === 0"
                            @click="runBulkAction"
                        >
                            {{ bulkActionLabel }}
                        </button>
                        <button
                            type="button"
                            class="rounded-xl bg-slate-200 px-4 py-2 text-sm font-medium text-slate-900 hover:bg-white"
                            :disabled="selectedCount === 0"
                            @click="runBulkAction"
                        >
                            Delete selected
                        </button>
                    </div>
                </div>

                <div class=" rounded-2xl border border-slate-800 bg-[#0b1220]">
                    <div >
                        <table class="min-w-full border-collapse text-left text-sm">
                            <thead class="sticky top-0 z-10 bg-[#111a2d] text-xs tracking-[0.16em] text-slate-400">
                                <tr>
                                    <th class="w-20 px-3 py-3 text-center">Actions</th>
                                    <th class="w-10 px-3 py-3">
                                        <input
                                            type="checkbox"
                                            :checked="allVisibleSelected"
                                            class="h-4 w-4 rounded border-slate-700 text-cyan-500 focus:ring-cyan-500"
                                            @change="toggleSelectAll($event.target.checked)"
                                        >
                                    </th>
                                    <th v-for="column in selectedTableColumns" :key="column.name" class="min-w-[120px] px-4 py-3">
                                        <button
                                            type="button"
                                            class="inline-flex items-center gap-1 text-left font-semibold uppercase tracking-[0.14em] text-slate-400 transition hover:text-slate-100"
                                            @click="handleColumnSort(column.name)"
                                        >
                                            <span>{{ column.name }}</span>
                                            <i
                                                v-if="isSortedColumn(column.name)"
                                                :class="[
                                                    'bi text-[10px]',
                                                    normalizedSortDirection === 'asc' ? 'bi-arrow-up' : 'bi-arrow-down',
                                                ]"
                                            ></i>
                                            <i v-else class="bi bi-arrow-down-up text-[10px] text-slate-600"></i>
                                        </button>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(row, index) in browseRows"
                                    :key="rowKeyFor(row, index)"
                                    class="group border-t border-slate-800"
                                    :class="index % 2 === 0 ? 'bg-[#0b1220]' : 'bg-[#0e1627]'"
                                >
                                    <td class="px-3 py-2.5">
                                        <div class="flex items-center gap-1">
                                            <button
                                                type="button"
                                                class="rounded p-1.5 text-slate-400 transition hover:bg-white/5 hover:text-slate-100"
                                                title="Edit row"
                                                @click="startRowEdit(row, index)"
                                            >
                                                <i class="bi bi-pencil text-xs"></i>
                                            </button>
                                            <button
                                                type="button"
                                                class="rounded p-1.5 text-red-300 transition hover:bg-red-500/10 hover:text-red-200"
                                                title="Delete row"
                                                @click="emit('row-delete', { row, rowKey: rowKeyFor(row, index) })"
                                            >
                                                <i class="bi bi-trash3 text-xs"></i>
                                            </button>
                                        </div>
                                    </td>
                                    <td class="px-3 py-2.5 align-top">
                                        <input
                                            type="checkbox"
                                            :checked="selectedRowKeys.has(rowKeyFor(row, index))"
                                            class="h-4 w-4 rounded border-slate-700 text-cyan-500 focus:ring-cyan-500"
                                            @change="toggleRowSelected(row, index, $event.target.checked)"
                                        >
                                    </td>
                                    <td
                                        v-for="(column, columnIndex) in selectedTableColumns"
                                        :key="column.name"
                                        class="max-w-[220px] cursor-pointer px-3 py-2.5 align-top"
                                        :class="isCellEditing(rowKeyFor(row, index), column.name) ? 'bg-cyan-500/10' : 'hover:bg-white/5'"
                                        @click="!isCellEditing(rowKeyFor(row, index), column.name) && startCellEdit(row, column, index, columnIndex)"
                                        @dblclick.stop="copyCellValue(row[column.name])"
                                    >
                                        <template v-if="isCellEditing(rowKeyFor(row, index), column.name)">
                                            <textarea
                                                v-if="isLongTextColumn(column)"
                                                ref="editInputRef"
                                                v-model="editDraft"
                                                rows="3"
                                                class="min-h-[60px] w-full rounded-lg border border-cyan-500 bg-[#111a2d] px-2 py-1 text-sm text-slate-100 outline-none ring-1 ring-cyan-500/20"
                                                @blur="handleCellBlur($event, row, column, index, columnIndex)"
                                                @keydown="handleCellKeydown($event, row, column, index, columnIndex)"
                                            />
                                            <input
                                                v-else
                                                ref="editInputRef"
                                                v-model="editDraft"
                                                :type="isNumericColumn(column) ? 'number' : 'text'"
                                                class="w-full rounded-lg border border-cyan-500 bg-[#111a2d] px-2 py-1 text-sm text-slate-100 outline-none ring-1 ring-cyan-500/20"
                                                @blur="handleCellBlur($event, row, column, index, columnIndex)"
                                                @keydown="handleCellKeydown($event, row, column, index, columnIndex)"
                                            >
                                        </template>
                                        <template v-else>
                                            <div class="relative">
                                                <span
                                                    class="block rounded px-1 py-0.5 text-sm"
                                                    :class="[
                                                        row[column.name] === null || row[column.name] === '' ? 'italic text-slate-500' : 'text-slate-200',
                                                        expandedCells.has(`${rowKeyFor(row, index)}-${column.name}`) ? '' : 'truncate'
                                                    ]"
                                                    :title="String(row[column.name] ?? 'NULL')"
                                                >
                                                    {{ row[column.name] === null ? 'NULL' : (row[column.name] === '' ? '.empty' : row[column.name]) }}
                                                </span>
                                                <button
                                                    v-if="String(row[column.name] ?? '').length > 50"
                                                    type="button"
                                                    class="absolute -right-1 -top-1 hidden rounded bg-slate-800 p-0.5 text-[8px] text-slate-400 hover:bg-slate-700 group-hover/cell:inline-block"
                                                    @click.stop="toggleCellExpand(rowKeyFor(row, index), column.name)"
                                                >
                                                    <i :class="['bi', expandedCells.has(`${rowKeyFor(row, index)}-${column.name}`) ? 'bi-arrows-collapse' : 'bi-arrows-expand']"></i>
                                                </button>
                                            </div>
                                        </template>
                                    </td>
                                </tr>
                                <tr v-if="browseRows.length === 0">
                                    <td :colspan="selectedTableColumns.length + 2" class="px-4 py-6 text-center text-slate-500">
                                        No rows returned for the current page.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div v-if="pagination" class="flex flex-wrap items-center justify-between gap-3 text-sm text-slate-400">
                    <span>{{ pagination.total }} total rows | {{ pagination.per_page }} per page</span>
                    <div class="flex gap-2">
                        <button
                            v-if="pagination.has_previous"
                            type="button"
                            class="rounded-xl border border-slate-700 px-3 py-2 hover:bg-white/5"
                            @click="emit('paginate', pagination.current_page - 1)"
                        >
                            Previous
                        </button>
                        <button
                            v-if="pagination.has_more"
                            type="button"
                            class="rounded-xl border border-slate-700 px-3 py-2 hover:bg-white/5"
                            @click="emit('paginate', pagination.current_page + 1)"
                        >
                            Next
                        </button>
                    </div>
                </div>
            </div>
        </template>

        <template v-else-if="selectedTableDetails">
            <div class="m-4 overflow-hidden rounded-2xl border border-slate-800 bg-[#0b1220]">
                <table class="min-w-full border-collapse text-left text-sm">
                    <thead class="bg-[#111a2d] text-xs tracking-[0.16em] text-slate-400">
                        <tr>
                            <th v-for="column in selectedTableColumns" :key="column.name" class="min-w-[120px] px-4 py-3">
                                {{ column.name }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, index) in selectedTableRows" :key="index" class="border-t border-slate-800">
                            <td v-for="column in selectedTableColumns" :key="column.name" class="max-w-[220px] px-4 py-3 align-top text-slate-200">
                                <span class="block truncate" :title="String(row[column.name] ?? '')">
                                    {{ row[column.name] ?? 'NULL' }}
                                </span>
                            </td>
                        </tr>
                        <tr v-if="selectedTableRows.length === 0">
                            <td :colspan="Math.max(selectedTableColumns.length, 1)" class="px-4 py-6 text-center text-slate-500">
                                No rows returned for the current page.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

        <div v-if="pagination && activeAction !== 'browse'" class="m-4 flex flex-wrap items-center justify-between gap-3 text-sm text-slate-400">
            <span>{{ pagination.total }} total rows | {{ pagination.per_page }} per page</span>
            <div class="flex gap-2">
                <button
                    v-if="pagination.has_previous"
                    type="button"
                    class="rounded-xl border border-slate-700 px-3 py-2 hover:bg-white/5"
                    @click="emit('paginate', pagination.current_page - 1)"
                >
                    Previous
                </button>
                <button
                    v-if="pagination.has_more"
                    type="button"
                    class="rounded-xl border border-slate-700 px-3 py-2 hover:bg-white/5"
                    @click="emit('paginate', pagination.current_page + 1)"
                >
                    Next
                </button>
            </div>
        </div>
    </section>
</template>
