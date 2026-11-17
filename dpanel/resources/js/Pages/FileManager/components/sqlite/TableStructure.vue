<script setup>
import { computed, ref } from 'vue';
import ColumnEditor from './ColumnEditor.vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    table: { type: Object, required: true },
    run: { type: Function, required: true },
    editing: { type: Boolean, default: false },
});

const emit = defineEmits(['changed']);

const canEdit = computed(() => props.editing && props.table.type === 'table');
// Column drawer: undefined closed, null adding, otherwise the column.
const editorColumn = ref(undefined);
const error = ref('');
const notice = ref('');
const quote = (s) => `"${String(s).replace(/"/g, '""')}"`;

function done(message) {
    editorColumn.value = undefined;
    notice.value = message;
    setTimeout(() => { if (notice.value === message) notice.value = ''; }, 3000);
    emit('changed');
}

async function dropColumn(col) {
    if (!confirm(t('fileManager.sqlite.structure.dropConfirm', { column: col.name, table: props.table.name }))) return;
    error.value = '';
    try {
        await props.run('query', { sql: `ALTER TABLE ${quote(props.table.name)} DROP COLUMN ${quote(col.name)}` });
        done(t('fileManager.sqlite.structure.dropped', { column: col.name }));
    } catch (e) {
        error.value = e.message;
    }
}
</script>

<template>
    <div class="space-y-4">
        <div v-if="canEdit || notice" class="flex items-center gap-2">
            <button v-if="canEdit" type="button" class="rounded-md border border-slate-300 px-2 py-1.5 text-xs hover:bg-slate-100 dark:border-slate-600 dark:hover:bg-slate-800" @click="editorColumn = null">
                <i class="bi bi-plus-lg"></i> {{ t('fileManager.sqlite.structure.addColumn') }}
            </button>
            <span v-if="notice" class="text-xs text-emerald-600">{{ notice }}</span>
        </div>
        <div v-if="error" class="rounded-md border border-red-200 bg-red-50 p-3 text-xs text-red-700 dark:border-red-800 dark:bg-red-950 dark:text-red-300">{{ error }}</div>

        <div class="overflow-x-auto rounded-md border border-slate-200 dark:border-slate-700">
            <table class="min-w-full text-xs">
                <thead class="bg-slate-50 text-left text-slate-600 dark:bg-slate-900 dark:text-slate-300">
                    <tr>
                        <th class="px-3 py-2 font-medium">{{ t('fileManager.sqlite.structure.column') }}</th>
                        <th class="px-3 py-2 font-medium">{{ t('fileManager.sqlite.structure.type') }}</th>
                        <th class="px-3 py-2 font-medium">Null</th>
                        <th class="px-3 py-2 font-medium">{{ t('fileManager.sqlite.structure.default') }}</th>
                        <th class="px-3 py-2 font-medium">{{ t('fileManager.sqlite.structure.key') }}</th>
                        <th v-if="canEdit" class="px-3 py-2 font-medium">{{ t('fileManager.sqlite.structure.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <tr v-for="col in table.columns" :key="col.name">
                        <td class="px-3 py-1.5 font-mono">{{ col.name }} <span v-if="col.hidden" class="text-slate-400">({{ t('fileManager.sqlite.structure.generated') }})</span></td>
                        <td class="px-3 py-1.5 font-mono text-slate-500">{{ col.type || '—' }}</td>
                        <td class="px-3 py-1.5">{{ col.not_null ? 'NO' : 'YES' }}</td>
                        <td class="px-3 py-1.5 font-mono text-slate-500">{{ col.default ?? '—' }}</td>
                        <td class="px-3 py-1.5">
                            <span v-if="col.pk" class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">PK</span>
                        </td>
                        <td v-if="canEdit" class="whitespace-nowrap px-3 py-1">
                            <button type="button" class="rounded border border-slate-200 px-1.5 py-0.5 text-blue-600 hover:bg-blue-50 dark:border-slate-700 dark:text-blue-400 dark:hover:bg-blue-950/40" @click="editorColumn = col">
                                <i class="bi bi-pencil-square"></i> {{ t('fileManager.sqlite.structure.edit') }}
                            </button>
                            <button type="button" :title="t('fileManager.sqlite.structure.deleteColumn')" class="ml-1 rounded border border-slate-200 px-1.5 py-0.5 text-red-600 hover:bg-red-50 dark:border-slate-700 dark:text-red-400 dark:hover:bg-red-950/40" @click="dropColumn(col)">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="table.indexes.length">
            <h3 class="mb-1 text-xs font-semibold">{{ t('fileManager.sqlite.structure.indexes') }}</h3>
            <ul class="space-y-1 text-xs">
                <li v-for="idx in table.indexes" :key="idx.name" class="flex flex-wrap items-center gap-2">
                    <code>{{ idx.name }}</code>
                    <span class="text-slate-500">({{ idx.columns.join(', ') }})</span>
                    <span v-if="idx.unique" class="rounded bg-sky-100 px-1.5 py-0.5 text-[10px] text-sky-700 dark:bg-sky-900/40 dark:text-sky-300">UNIQUE</span>
                    <span v-if="idx.origin === 'pk'" class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">PRIMARY KEY</span>
                </li>
            </ul>
        </div>

        <div v-if="table.sql">
            <h3 class="mb-1 text-xs font-semibold">{{ t('fileManager.sqlite.structure.createStatement') }}</h3>
            <pre class="overflow-x-auto rounded-md bg-slate-900 p-3 text-xs text-slate-100">{{ table.sql }}</pre>
        </div>

        <ColumnEditor
            v-if="canEdit"
            :open="editorColumn !== undefined"
            :table="table"
            :column="editorColumn ?? null"
            :run="run"
            @close="editorColumn = undefined"
            @saved="done"
        />
    </div>
</template>
