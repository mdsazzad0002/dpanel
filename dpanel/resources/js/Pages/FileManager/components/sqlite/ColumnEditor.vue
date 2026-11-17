<script setup>
import { computed, ref, watch } from 'vue';
import SideDrawer from './SideDrawer.vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

// Add a column (column null) or rename one, through ALTER TABLE.
const props = defineProps({
    open: { type: Boolean, default: false },
    table: { type: Object, required: true },
    column: { type: Object, default: null },
    run: { type: Function, required: true },
});

const emit = defineEmits(['close', 'saved']);

const TYPES = ['TEXT', 'INTEGER', 'REAL', 'NUMERIC', 'BLOB', 'VARCHAR(255)', 'BOOLEAN', 'DATETIME', 'DATE', 'JSON'];

const name = ref('');
const type = ref('TEXT');
const notNull = ref(false);
const defaultValue = ref('');
const defaultKind = ref('none');
const saving = ref(false);
const error = ref('');

const isAdd = computed(() => props.column === null);
const quote = (s) => `"${String(s).replace(/"/g, '""')}"`;
const literal = (s) => `'${String(s).replace(/'/g, "''")}'`;

watch(() => [props.open, props.column], () => {
    if (!props.open) return;
    error.value = '';
    name.value = props.column?.name || '';
    type.value = props.column?.type || 'TEXT';
    notNull.value = false;
    defaultKind.value = 'none';
    defaultValue.value = '';
}, { immediate: true });

const defaultSql = computed(() => {
    switch (defaultKind.value) {
        case 'value': return /^-?\d+(\.\d+)?$/.test(defaultValue.value.trim()) ? defaultValue.value.trim() : literal(defaultValue.value);
        case 'null': return 'NULL';
        case 'now': return 'CURRENT_TIMESTAMP';
        default: return '';
    }
});

const sql = computed(() => {
    const tbl = quote(props.table.name);
    if (!name.value.trim()) return '';
    if (!isAdd.value) {
        return name.value.trim() === props.column.name ? '' : `ALTER TABLE ${tbl} RENAME COLUMN ${quote(props.column.name)} TO ${quote(name.value.trim())}`;
    }
    let def = `${quote(name.value.trim())}${type.value.trim() ? ` ${type.value.trim()}` : ''}`;
    if (notNull.value) def += ' NOT NULL';
    if (defaultSql.value) def += ` DEFAULT ${defaultSql.value}`;
    return `ALTER TABLE ${tbl} ADD COLUMN ${def}`;
});

async function save() {
    error.value = '';
    if (isAdd.value && notNull.value && !['value', 'now'].includes(defaultKind.value)) {
        error.value = t('fileManager.sqlite.column.needDefault');
        return;
    }
    if (!sql.value) {
        emit('close');
        return;
    }
    saving.value = true;
    try {
        await props.run('query', { sql: sql.value });
        emit('saved', isAdd.value ? t('fileManager.sqlite.column.added', { name: name.value.trim() }) : t('fileManager.sqlite.column.renamed', { name: name.value.trim() }));
    } catch (e) {
        error.value = e.message;
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <SideDrawer
        :open="open"
        :title="isAdd ? t('fileManager.sqlite.structure.addColumn') : t('fileManager.sqlite.column.editTitle', { name: column.name })"
        :subtitle="table.name"
        :icon="isAdd ? 'bi-plus-square' : 'bi-layout-three-columns'"
        @close="emit('close')"
    >
        <div class="space-y-3 text-xs">
            <label class="block">
                <span class="mb-1 block font-medium">{{ t('fileManager.sqlite.column.name') }}</span>
                <input v-model="name" spellcheck="false" class="w-full rounded-md border border-slate-300 px-2 py-1.5 font-mono dark:border-slate-700 dark:bg-slate-900" />
            </label>

            <template v-if="isAdd">
                <label class="block">
                    <span class="mb-1 block font-medium">{{ t('fileManager.sqlite.structure.type') }}</span>
                    <input v-model="type" list="sqlite-column-types" spellcheck="false" class="w-full rounded-md border border-slate-300 px-2 py-1.5 font-mono dark:border-slate-700 dark:bg-slate-900" />
                    <datalist id="sqlite-column-types">
                        <option v-for="ty in TYPES" :key="ty" :value="ty" />
                    </datalist>
                </label>
                <label class="flex items-center gap-2">
                    <input v-model="notNull" type="checkbox" /> NOT NULL
                </label>
                <div>
                    <span class="mb-1 block font-medium">{{ t('fileManager.sqlite.structure.default') }}</span>
                    <div class="flex gap-2">
                        <select v-model="defaultKind" class="rounded-md border border-slate-300 px-1.5 py-1.5 dark:border-slate-700 dark:bg-slate-900">
                            <option value="none">{{ t('common.none') }}</option>
                            <option value="value">{{ t('fileManager.sqlite.column.value') }}</option>
                            <option value="null">NULL</option>
                            <option value="now">CURRENT_TIMESTAMP</option>
                        </select>
                        <input v-if="defaultKind === 'value'" v-model="defaultValue" class="min-w-0 flex-1 rounded-md border border-slate-300 px-2 py-1.5 font-mono dark:border-slate-700 dark:bg-slate-900" />
                    </div>
                </div>
            </template>
            <div v-else class="rounded-md bg-slate-50 p-2.5 text-slate-600 dark:bg-slate-900 dark:text-slate-400">
                <p>{{ t('fileManager.sqlite.column.typeLabel') }} <code>{{ column.type || '—' }}</code> · {{ column.not_null ? 'NOT NULL' : t('fileManager.sqlite.column.nullable') }}<template v-if="column.default !== null"> · {{ t('fileManager.sqlite.column.defaultLabel') }} <code>{{ column.default }}</code></template></p>
                <p class="mt-1 text-[11px]">{{ t('fileManager.sqlite.column.renameOnly') }}</p>
            </div>

            <div v-if="sql">
                <span class="mb-1 block font-medium">{{ t('fileManager.sqlite.column.sqlPreview') }}</span>
                <pre class="overflow-x-auto whitespace-pre-wrap rounded-md bg-slate-900 p-2.5 text-[11px] text-slate-100">{{ sql }}</pre>
            </div>

            <div v-if="error" class="rounded-md border border-red-200 bg-red-50 p-2.5 text-red-700 dark:border-red-800 dark:bg-red-950 dark:text-red-300">{{ error }}</div>
        </div>

        <template #footer>
            <span class="ml-auto"></span>
            <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-xs dark:border-slate-700" @click="emit('close')">{{ t('common.cancel') }}</button>
            <button type="button" :disabled="saving || !name.trim()" class="rounded-md bg-blue-600 px-3 py-1.5 text-xs text-white hover:bg-blue-700 disabled:opacity-50" @click="save">
                <i v-if="saving" class="bi bi-arrow-repeat inline-block animate-spin"></i>
                {{ isAdd ? t('fileManager.sqlite.structure.addColumn') : t('fileManager.sqlite.column.rename') }}
            </button>
        </template>
    </SideDrawer>
</template>
