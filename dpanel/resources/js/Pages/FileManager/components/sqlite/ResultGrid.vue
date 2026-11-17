<script setup>
import { nextTick, ref } from 'vue';
import { cellClass, cellText, cellTitle } from '../../helpers/sqliteCells';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    columns: { type: Array, default: () => [] },
    rows: { type: Array, default: () => [] },
    sortBy: { type: String, default: '' },
    sortDesc: { type: Boolean, default: false },
    sortable: { type: Boolean, default: false },
    offset: { type: Number, default: 0 },
    // Editing: double-click a cell to change it, tick rows to select them.
    editable: { type: Boolean, default: false },
    selected: { type: Array, default: () => [] },
    // Show an Edit / Delete button on each row.
    rowActions: { type: Boolean, default: false },
});

const emit = defineEmits(['sort', 'save', 'update:selected', 'edit-row', 'delete-row']);

const editing = ref(null);
const editorEl = ref(null);

const copy = (value) => {
    const text = value && typeof value === 'object' ? ('$text' in value ? value.$text : cellTitle(value)) : (value === null ? '' : String(value));
    navigator.clipboard?.writeText(text).catch(() => {});
};

// Blobs and cut text are not shown in full, so they are changed with SQL.
const canEdit = (value) => props.editable && !(value && typeof value === 'object');

const startEdit = async (r, c, value) => {
    if (!canEdit(value)) return;
    editing.value = { r, c, text: value === null ? '' : String(value), original: value, multiline: typeof value === 'string' && (value.length > 60 || value.includes('\n')) };
    await nextTick();
    editorEl.value?.[0]?.focus?.() ?? editorEl.value?.focus?.();
};

const cancel = () => { editing.value = null; };

const save = (asNull = false) => {
    const e = editing.value;
    if (!e) return;
    let value = asNull ? null : e.text;
    // Keep numbers numbers when the old value was one and the text still is.
    if (!asNull && typeof e.original === 'number' && e.text.trim() !== '' && !Number.isNaN(Number(e.text))) value = Number(e.text);
    editing.value = null;
    if (value === e.original) return;
    emit('save', { rowIndex: e.r, column: props.columns[e.c], value });
};

const onEditorKey = (event) => {
    if (event.key === 'Escape') { event.stopPropagation(); cancel(); }
    else if (event.key === 'Enter' && (!editing.value?.multiline || event.ctrlKey || event.metaKey)) { event.preventDefault(); save(); }
};

const isSelected = (r) => props.selected.includes(r);
const toggleRow = (r) => emit('update:selected', isSelected(r) ? props.selected.filter((x) => x !== r) : [...props.selected, r]);
const toggleAll = () => emit('update:selected', props.selected.length === props.rows.length ? [] : props.rows.map((_, r) => r));
</script>

<template>
    <div class="overflow-auto rounded-md border border-slate-200 dark:border-slate-700">
        <table class="min-w-full text-xs">
            <thead class="sticky top-0 z-10 bg-slate-50 text-left text-slate-600 dark:bg-slate-900 dark:text-slate-300">
                <tr>
                    <th v-if="editable" class="w-8 border-b border-slate-200 px-2 py-2 dark:border-slate-700">
                        <input type="checkbox" :checked="rows.length > 0 && selected.length === rows.length" @change="toggleAll" />
                    </th>
                    <th class="w-10 border-b border-slate-200 px-2 py-2 text-right font-normal text-slate-400 dark:border-slate-700">#</th>
                    <th v-if="rowActions" class="w-px whitespace-nowrap border-b border-slate-200 px-2 py-2 font-medium dark:border-slate-700">{{ t('fileManager.sqlite.structure.actions') }}</th>
                    <th v-for="col in columns" :key="col"
                        class="whitespace-nowrap border-b border-slate-200 px-3 py-2 font-medium dark:border-slate-700"
                        :class="sortable ? 'cursor-pointer select-none hover:text-blue-600' : ''"
                        @click="sortable && emit('sort', col)">
                        {{ col }}
                        <i v-if="sortable && sortBy === col" class="bi ml-0.5" :class="sortDesc ? 'bi-caret-down-fill' : 'bi-caret-up-fill'"></i>
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                <tr v-if="!rows.length">
                    <td :colspan="columns.length + (editable ? 2 : 1) + (rowActions ? 1 : 0)" class="px-3 py-6 text-center text-slate-400">{{ t('fileManager.sqlite.grid.noRows') }}</td>
                </tr>
                <tr v-for="(row, r) in rows" :key="r"
                    :class="isSelected(r) ? 'bg-blue-50 dark:bg-blue-950/40' : 'hover:bg-slate-50 dark:hover:bg-slate-800/60'">
                    <td v-if="editable" class="px-2 py-1.5"><input type="checkbox" :checked="isSelected(r)" @change="toggleRow(r)" /></td>
                    <td class="px-2 py-1.5 text-right text-slate-400">{{ offset + r + 1 }}</td>
                    <td v-if="rowActions" class="whitespace-nowrap px-2 py-1">
                        <button type="button" :title="t('fileManager.sqlite.grid.editRow')" class="rounded border border-slate-200 px-1.5 py-0.5 text-blue-600 hover:bg-blue-50 dark:border-slate-700 dark:text-blue-400 dark:hover:bg-blue-950/40" @click="emit('edit-row', r)">
                            <i class="bi bi-pencil-square"></i> {{ t('fileManager.sqlite.structure.edit') }}
                        </button>
                        <button type="button" :title="t('fileManager.sqlite.grid.deleteRow')" class="ml-1 rounded border border-slate-200 px-1.5 py-0.5 text-red-600 hover:bg-red-50 dark:border-slate-700 dark:text-red-400 dark:hover:bg-red-950/40" @click="emit('delete-row', r)">
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                    <template v-for="(value, c) in row" :key="c">
                        <td v-if="editing && editing.r === r && editing.c === c" class="min-w-[12rem] px-1 py-1">
                            <textarea v-if="editing.multiline" ref="editorEl" v-model="editing.text" rows="4" @keydown="onEditorKey"
                                class="w-full rounded border border-blue-500 px-1.5 py-1 font-mono text-xs dark:bg-slate-900"></textarea>
                            <input v-else ref="editorEl" v-model="editing.text" @keydown="onEditorKey"
                                class="w-full rounded border border-blue-500 px-1.5 py-1 text-xs dark:bg-slate-900" />
                            <div class="mt-1 flex gap-1 text-[10px]">
                                <button type="button" @click="save()" class="rounded bg-blue-600 px-1.5 py-0.5 text-white">{{ t('common.save') }}</button>
                                <button type="button" @click="save(true)" class="rounded border border-slate-300 px-1.5 py-0.5 dark:border-slate-600">NULL</button>
                                <button type="button" @click="cancel" class="rounded px-1.5 py-0.5 text-slate-500">{{ t('common.cancel') }}</button>
                            </div>
                        </td>
                        <td v-else
                            class="max-w-xs truncate whitespace-nowrap px-3 py-1.5"
                            :class="[cellClass(value), canEdit(value) ? 'cursor-text' : 'cursor-copy']"
                            :title="cellTitle(value) || (canEdit(value) ? t('fileManager.sqlite.grid.dblClick') : t('fileManager.sqlite.grid.clickCopy'))"
                            @click="!editable && copy(value)"
                            @dblclick="startEdit(r, c, value)">{{ cellText(value) }}</td>
                    </template>
                </tr>
            </tbody>
        </table>
    </div>
</template>
