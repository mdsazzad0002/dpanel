<script setup>
import { computed, ref, watch } from 'vue';
import SideDrawer from './SideDrawer.vue';
import { FORMAT_GROUPS, FORMATS, typeOf, valueFor } from '../../helpers/sqliteFormats';
import { cellText } from '../../helpers/sqliteCells';

// Edit one row (rowid set) or add a new one (rowid null) in a drawer.
const props = defineProps({
    open: { type: Boolean, default: false },
    table: { type: Object, required: true },
    // The grid's column order and this row's cells; empty when adding.
    columns: { type: Array, default: () => [] },
    row: { type: Array, default: null },
    rowid: { type: [Number, String], default: null },
    run: { type: Function, required: true },
    // (format, text) => Promise<string>: server-side hashes.
    convert: { type: Function, required: true },
});

const emit = defineEmits(['close', 'saved', 'delete']);

const fields = ref([]);
const saving = ref(false);
const error = ref('');
const isInsert = computed(() => props.rowid === null);

function affinity(declared) {
    const t = String(declared || '').toUpperCase();
    if (t.includes('INT')) return 'integer';
    if (/REAL|FLOA|DOUB|NUMERIC|DECIMAL/.test(t)) return 'real';
    return 'text';
}

watch(() => [props.open, props.rowid, props.row], () => {
    if (!props.open) return;
    error.value = '';
    fields.value = props.table.columns.filter((c) => !c.hidden).map((col) => {
        const index = props.columns.indexOf(col.name);
        const value = props.row && index >= 0 ? props.row[index] : null;
        // Blobs and cut text are not loaded in full, so they stay as they are.
        const locked = !isInsert.value && value !== null && typeof value === 'object';
        const type = isInsert.value ? affinity(col.type) : typeOf(value);
        const text = value === null || locked ? '' : String(value);
        return { col, value, locked, text, type, origText: text, origType: type, touched: false, busy: false, error: '' };
    });
}, { immediate: true });

const changed = (f) => (isInsert.value ? f.touched : f.text !== f.origText || f.type !== f.origType);
const changedCount = computed(() => fields.value.filter((f) => !f.locked && changed(f)).length);
const multiline = (f) => f.text.length > 60 || f.text.includes('\n');

async function applyFormat(f, id, select) {
    select.value = '';
    const format = FORMATS[id];
    if (!format) return;
    f.error = '';
    f.busy = true;
    try {
        const source = f.type === 'null' ? '' : f.text;
        f.text = format.server ? await props.convert(id, source) : await format.apply(source);
        f.type = format.type || (format.server || f.type === 'null' ? 'text' : f.type);
        f.touched = true;
    } catch (e) {
        f.error = e.message;
    } finally {
        f.busy = false;
    }
}

function reset(f) {
    f.text = f.origText;
    f.type = f.origType;
    f.touched = false;
    f.error = '';
}

async function save() {
    error.value = '';
    const values = {};
    try {
        for (const f of fields.value) {
            if (f.locked || !changed(f)) continue;
            values[f.col.name] = valueFor(f.text, f.type);
        }
    } catch (e) {
        error.value = e.message;
        return;
    }
    if (!isInsert.value && !Object.keys(values).length) {
        emit('close');
        return;
    }
    saving.value = true;
    try {
        const res = isInsert.value
            ? await props.run('insert_row', { table: props.table.name, values_json: JSON.stringify(values) })
            : await props.run('update_row', { table: props.table.name, rowid: props.rowid, values_json: JSON.stringify(values) });
        emit('saved', { ...res, insert: isInsert.value, count: Object.keys(values).length });
    } catch (e) {
        error.value = e.message;
    } finally {
        saving.value = false;
    }
}

const typeBadge = { text: 'TEXT', integer: 'INT', real: 'REAL', null: 'NULL' };
</script>

<template>
    <SideDrawer
        :open="open"
        :title="isInsert ? `Add row to ${table.name}` : `Edit row ${rowid}`"
        :subtitle="isInsert ? 'Empty fields use the column default' : table.name"
        :icon="isInsert ? 'bi-plus-square' : 'bi-pencil-square'"
        @close="emit('close')"
    >
        <div class="space-y-3">
            <div
                v-for="f in fields"
                :key="f.col.name"
                class="rounded-lg border p-2.5"
                :class="!f.locked && changed(f) ? 'border-amber-300 bg-amber-50/40 dark:border-amber-700/60 dark:bg-amber-950/10' : 'border-slate-200 dark:border-slate-800'"
            >
                <div class="mb-1.5 flex items-center gap-1.5 text-[11px]">
                    <span class="font-mono font-semibold text-slate-700 dark:text-slate-200">{{ f.col.name }}</span>
                    <span class="font-mono text-slate-400">{{ f.col.type }}</span>
                    <span v-if="f.col.pk" class="rounded bg-amber-100 px-1 text-[9px] text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">PK</span>
                    <span v-if="f.col.not_null" class="text-[9px] text-red-500">NOT NULL</span>
                    <span class="ml-auto rounded px-1.5 py-0.5 text-[9px] font-semibold"
                        :class="f.type === 'null' ? 'bg-slate-100 text-slate-500 dark:bg-slate-800' : 'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300'">
                        {{ typeBadge[f.type] }}
                    </span>
                    <button v-if="!f.locked && changed(f)" type="button" class="text-slate-400 hover:text-slate-700 dark:hover:text-slate-200" title="Undo changes to this field" @click="reset(f)">
                        <i class="bi bi-arrow-counterclockwise"></i>
                    </button>
                </div>

                <p v-if="f.locked" class="rounded bg-slate-50 px-2 py-1.5 text-xs italic text-violet-600 dark:bg-slate-900">
                    {{ cellText(f.value) }} — not loaded in full; change it with SQL.
                </p>
                <div v-else class="flex items-start gap-1.5">
                    <textarea v-if="multiline(f)" v-model="f.text" rows="4" :disabled="f.type === 'null' || f.busy" spellcheck="false"
                        class="min-w-0 flex-1 rounded-md border border-slate-300 px-2 py-1 font-mono text-xs disabled:bg-slate-50 disabled:text-slate-400 dark:border-slate-700 dark:bg-slate-900 dark:disabled:bg-slate-900/50"
                        @input="f.touched = true"></textarea>
                    <input v-else v-model="f.text" :disabled="f.type === 'null' || f.busy"
                        :placeholder="f.type === 'null' ? 'NULL' : (isInsert && f.col.pk ? 'auto' : (isInsert && f.col.default !== null ? `default: ${f.col.default}` : ''))"
                        class="min-w-0 flex-1 rounded-md border border-slate-300 px-2 py-1 font-mono text-xs disabled:bg-slate-50 disabled:text-slate-400 dark:border-slate-700 dark:bg-slate-900 dark:disabled:bg-slate-900/50"
                        @input="f.touched = true" />
                    <select
                        :disabled="f.busy"
                        title="Convert / change format"
                        class="w-28 shrink-0 rounded-md border border-slate-300 px-1 py-1 text-xs dark:border-slate-700 dark:bg-slate-900"
                        @change="applyFormat(f, $event.target.value, $event.target)"
                    >
                        <option value="">{{ f.busy ? 'Working…' : 'Format…' }}</option>
                        <optgroup v-for="g in FORMAT_GROUPS" :key="g.label" :label="g.label">
                            <option v-for="o in g.items" :key="o.id" :value="o.id">{{ o.label }}</option>
                        </optgroup>
                    </select>
                </div>
                <p v-if="f.error" class="mt-1 text-[11px] text-red-600">{{ f.error }}</p>
            </div>
        </div>

        <div v-if="error" class="mt-3 rounded-md border border-red-200 bg-red-50 p-2.5 text-xs text-red-700 dark:border-red-800 dark:bg-red-950 dark:text-red-300">{{ error }}</div>

        <template #footer>
            <button v-if="!isInsert" type="button" class="rounded-md border border-red-300 px-3 py-1.5 text-xs text-red-600 hover:bg-red-50 dark:border-red-800 dark:hover:bg-red-950" @click="emit('delete')">
                <i class="bi bi-trash"></i> Delete row
            </button>
            <span class="ml-auto text-xs text-slate-500">{{ changedCount }} field(s) changed</span>
            <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-xs dark:border-slate-700" @click="emit('close')">Cancel</button>
            <button type="button" :disabled="saving || (!isInsert && !changedCount)" class="rounded-md bg-blue-600 px-3 py-1.5 text-xs text-white hover:bg-blue-700 disabled:opacity-50" @click="save">
                <i v-if="saving" class="bi bi-arrow-repeat inline-block animate-spin"></i>
                {{ isInsert ? 'Insert row' : 'Save changes' }}
            </button>
        </template>
    </SideDrawer>
</template>
