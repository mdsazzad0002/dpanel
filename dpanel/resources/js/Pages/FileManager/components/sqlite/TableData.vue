<script setup>
import { computed, ref, watch } from 'vue';
import ResultGrid from './ResultGrid.vue';
import RowEditor from './RowEditor.vue';

const props = defineProps({
    table: { type: Object, required: true },
    run: { type: Function, required: true },
    editing: { type: Boolean, default: false },
    convert: { type: Function, required: true },
});

const emit = defineEmits(['changed']);

const result = ref(null);
const loading = ref(false);
const error = ref('');
const notice = ref('');
const page = ref(1);
const perPage = ref(50);
const sortBy = ref('');
const sortDesc = ref(false);
const search = ref('');
const selected = ref([]);
// Row drawer: null closed, -1 adding, otherwise the index of the row on this page.
const editorRow = ref(null);
let searchTimer = null;

const pages = computed(() => Math.max(1, Math.ceil((result.value?.total || 0) / perPage.value)));
const canEdit = computed(() => props.editing && !!result.value?.editable);

const load = async () => {
    loading.value = true;
    error.value = '';
    selected.value = [];
    try {
        result.value = await props.run('rows', {
            table: props.table.name,
            page: page.value,
            per_page: perPage.value,
            order_by: sortBy.value || null,
            order_desc: sortDesc.value,
            search: search.value.trim() || null,
        });
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
};

const flash = (text) => {
    notice.value = text;
    setTimeout(() => { if (notice.value === text) notice.value = ''; }, 3000);
};

const saveCell = async ({ rowIndex, column, value }) => {
    error.value = '';
    try {
        await props.run('update_cell', {
            table: props.table.name,
            rowid: result.value.rowids[rowIndex],
            column,
            value_json: JSON.stringify(value),
        });
        flash(`Saved ${column}.`);
        await load();
    } catch (e) {
        error.value = e.message;
    }
};

const deleteSelected = async () => {
    const count = selected.value.length;
    if (!count || !confirm(`Delete ${count} row(s) from "${props.table.name}"? This cannot be undone.`)) return;
    error.value = '';
    try {
        const res = await props.run('delete_rows', {
            table: props.table.name,
            rowids: selected.value.map((r) => result.value.rowids[r]),
        });
        flash(`Deleted ${res.changes} row(s).`);
        await load();
        emit('changed');
    } catch (e) {
        error.value = e.message;
    }
};

const deleteRow = async (r) => {
    const rowid = result.value.rowids[r];
    if (!confirm(`Delete row ${rowid} from "${props.table.name}"? This cannot be undone.`)) return;
    error.value = '';
    try {
        await props.run('delete_rows', { table: props.table.name, rowids: [rowid] });
        editorRow.value = null;
        flash(`Deleted row ${rowid}.`);
        await load();
        emit('changed');
    } catch (e) {
        error.value = e.message;
    }
};

const onRowSaved = async (res) => {
    editorRow.value = null;
    flash(res.insert ? `Row added (rowid ${res.rowid}).` : `Saved ${res.count} field(s).`);
    await load();
    if (res.insert) emit('changed');
};

const sort = (col) => {
    if (sortBy.value === col) sortDesc.value = !sortDesc.value;
    else { sortBy.value = col; sortDesc.value = false; }
    page.value = 1;
    load();
};

const go = (p) => { page.value = Math.min(Math.max(1, p), pages.value); load(); };

watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => { page.value = 1; load(); }, 350);
});
watch(perPage, () => { page.value = 1; load(); });
watch(() => props.editing, (on) => { if (!on) { selected.value = []; editorRow.value = null; } });
watch(() => props.table.name, () => {
    page.value = 1; sortBy.value = ''; sortDesc.value = false; search.value = ''; editorRow.value = null;
    load();
}, { immediate: true });

defineExpose({ load });
</script>

<template>
    <div class="space-y-2">
        <div class="flex flex-wrap items-center gap-2">
            <div class="relative">
                <i class="bi bi-search absolute left-2 top-1/2 -translate-y-1/2 text-xs text-slate-400"></i>
                <input v-model="search" type="text" placeholder="Search all columns"
                    class="w-56 rounded-md border border-slate-300 py-1.5 pl-7 pr-2 text-xs dark:border-slate-600 dark:bg-slate-900" />
            </div>
            <template v-if="canEdit">
                <button type="button" @click="editorRow = -1"
                    class="rounded-md border border-slate-300 px-2 py-1.5 text-xs hover:bg-slate-100 dark:border-slate-600 dark:hover:bg-slate-800">
                    <i class="bi bi-plus-lg"></i> Add row
                </button>
                <button v-if="selected.length" type="button" @click="deleteSelected"
                    class="rounded-md border border-red-300 px-2 py-1.5 text-xs text-red-600 hover:bg-red-50 dark:border-red-700 dark:hover:bg-red-950">
                    <i class="bi bi-trash"></i> Delete {{ selected.length }}
                </button>
            </template>
            <span class="text-xs text-slate-500">
                <template v-if="result">{{ result.total.toLocaleString() }} row(s){{ search.trim() ? ' match' : '' }}</template>
                <i v-if="loading" class="bi bi-arrow-repeat ml-1 inline-block animate-spin"></i>
            </span>
            <span v-if="notice" class="text-xs text-emerald-600">{{ notice }}</span>
            <div class="ml-auto flex items-center gap-1 text-xs">
                <select v-model.number="perPage" class="rounded-md border border-slate-300 px-1.5 py-1 dark:border-slate-600 dark:bg-slate-900">
                    <option v-for="n in [25, 50, 100, 250, 500]" :key="n" :value="n">{{ n }} / page</option>
                </select>
                <button @click="go(page - 1)" :disabled="page <= 1" class="rounded border border-slate-300 px-2 py-1 disabled:opacity-40 dark:border-slate-600"><i class="bi bi-chevron-left"></i></button>
                <span class="px-1 text-slate-500">{{ page }} / {{ pages }}</span>
                <button @click="go(page + 1)" :disabled="page >= pages" class="rounded border border-slate-300 px-2 py-1 disabled:opacity-40 dark:border-slate-600"><i class="bi bi-chevron-right"></i></button>
            </div>
        </div>

        <p v-if="editing && result && !result.editable" class="text-xs text-amber-600">
            {{ table.type === 'view' ? 'Views' : 'This table has no rowid, so it' }} can't be edited in the grid — use the SQL tab.
        </p>


        <div v-if="error" class="rounded-md border border-red-200 bg-red-50 p-3 text-xs text-red-700 dark:border-red-800 dark:bg-red-950 dark:text-red-300">{{ error }}</div>

        <ResultGrid v-if="result" :columns="result.columns" :rows="result.rows" :offset="(page - 1) * perPage"
            sortable :sort-by="sortBy" :sort-desc="sortDesc" @sort="sort"
            :editable="canEdit" v-model:selected="selected" @save="saveCell" class="max-h-[65vh]"
            :row-actions="canEdit" @edit-row="editorRow = $event" @delete-row="deleteRow" />

        <RowEditor
            v-if="result && canEdit"
            :open="editorRow !== null"
            :table="table"
            :columns="result.columns"
            :row="editorRow !== null && editorRow >= 0 ? result.rows[editorRow] : null"
            :rowid="editorRow !== null && editorRow >= 0 ? result.rowids[editorRow] : null"
            :run="run"
            :convert="convert"
            @close="editorRow = null"
            @saved="onRowSaved"
            @delete="deleteRow(editorRow)"
        />
    </div>
</template>
