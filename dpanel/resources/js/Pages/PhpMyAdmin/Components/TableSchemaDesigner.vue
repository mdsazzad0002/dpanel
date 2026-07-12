<script setup>
import { computed, ref, watch } from 'vue';

const props = defineProps({
    mode: {
        type: String,
        default: 'create',
    },
    selectedDatabase: {
        type: String,
        default: '',
    },
    selectedTable: {
        type: String,
        default: '',
    },
    initialColumns: {
        type: Array,
        default: () => [],
    },
    saveBusy: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['save', 'cancel']);

const typeFilter = ref('');
const tableName = ref('');
const columns = ref([]);

const baseTypeOptions = [
    'INT',
    'BIGINT',
    'SMALLINT',
    'TINYINT',
    'DECIMAL',
    'FLOAT',
    'DOUBLE',
    'VARCHAR',
    'CHAR',
    'TEXT',
    'MEDIUMTEXT',
    'LONGTEXT',
    'BLOB',
    'DATE',
    'DATETIME',
    'TIMESTAMP',
    'TIME',
    'JSON',
    'BOOLEAN',
];

const filteredTypeOptions = computed(() => {
    const needle = typeFilter.value.trim().toLowerCase();
    if (!needle) return baseTypeOptions;
    return baseTypeOptions.filter((type) => type.toLowerCase().includes(needle));
});

const normalizeColumn = (column = {}, index = 0) => ({
    originalName: String(column.originalName || column.name || '').trim(),
    name: String(column.name || column.originalName || '').trim(),
    type: String(column.type || 'VARCHAR').trim().toUpperCase() || 'VARCHAR',
    length: String(column.length || '').trim(),
    nullable: column.nullable !== undefined
        ? Boolean(column.nullable)
        : String(column.is_nullable || '').toUpperCase() !== 'NO',
    defaultValue: column.defaultValue !== undefined
        ? String(column.defaultValue ?? '')
        : String(column.default_value ?? ''),
    unsigned: Boolean(column.unsigned || false),
    autoIncrement: Boolean(column.autoIncrement || String(column.extra || '').toLowerCase().includes('auto_increment')),
    primaryKey: Boolean(column.primaryKey || String(column.key || '').toUpperCase() === 'PRI'),
    comment: String(column.comment || '').trim(),
    after: String(column.after || '').trim(),
    remove: Boolean(column.remove || false),
    _key: `${Date.now()}-${index}-${Math.random().toString(16).slice(2)}`,
});

const resetFromProps = () => {
    tableName.value = props.mode === 'edit' ? props.selectedTable || '' : '';

    const source = Array.isArray(props.initialColumns) && props.initialColumns.length > 0
        ? props.initialColumns
        : [{
            name: 'id',
            type: 'BIGINT',
            length: '',
            nullable: false,
            defaultValue: '',
            unsigned: true,
            autoIncrement: true,
            primaryKey: true,
        }];

    columns.value = source.map((column, index) => normalizeColumn(column, index));
};

const addColumn = () => {
    const previous = columns.value[columns.value.length - 1] || null;
    columns.value = [
        ...columns.value,
        normalizeColumn({
            name: '',
            type: 'VARCHAR',
            length: '255',
            nullable: true,
            defaultValue: '',
            after: previous?.name || '',
        }, columns.value.length),
    ];
};

const removeColumn = (key) => {
    columns.value = columns.value.filter((column) => column._key !== key);
};

const save = () => {
    emit('save', {
        mode: props.mode,
        tableName: tableName.value,
        columns: columns.value.map((column) => ({
            originalName: column.originalName,
            name: column.name,
            type: column.type,
            length: column.length,
            nullable: column.nullable,
            defaultValue: column.defaultValue,
            unsigned: column.unsigned,
            autoIncrement: column.autoIncrement,
            primaryKey: column.primaryKey,
            comment: column.comment,
            after: column.after,
            remove: column.remove,
        })),
    });
};

watch(
    () => [props.mode, props.selectedTable, props.initialColumns],
    () => {
        resetFromProps();
    },
    { immediate: true, deep: true }
);
</script>

<template>
    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <div class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-200 pb-4 dark:border-slate-800">
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-cyan-600 dark:text-cyan-300">
                    {{ mode === 'edit' ? 'Structure' : 'Create' }}
                </p>
                <h2 class="mt-1 text-xl font-semibold text-slate-900 dark:text-slate-100">
                    {{ mode === 'edit' ? 'Edit table structure' : 'Create table' }}
                </h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    Database: <strong>{{ selectedDatabase || 'none' }}</strong>
                    <span v-if="selectedTable" class="ml-2">Table: <strong>{{ selectedTable }}</strong></span>
                </p>
            </div>

            <div class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-600 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-300">
                phpMyAdmin-style schema editor
            </div>
        </div>

        <div class="mt-4 grid gap-4 lg:grid-cols-12">
            <div class="lg:col-span-4">
                <label class="mb-2 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500 dark:text-slate-400">
                    Table name
                </label>
                <input
                    v-model="tableName"
                    type="text"
                    :disabled="mode === 'edit'"
                    placeholder="new_table"
                    class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-cyan-400 disabled:bg-slate-100 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:disabled:bg-slate-900"
                >
            </div>

            <div class="lg:col-span-8">
                <label class="mb-2 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500 dark:text-slate-400">
                    Live type search
                </label>
                <input
                    v-model="typeFilter"
                    type="text"
                    placeholder="Search data types"
                    class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-cyan-400 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                >
                <div class="mt-2 flex flex-wrap gap-2">
                    <span
                        v-for="type in filteredTypeOptions"
                        :key="type"
                        class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[11px] font-medium text-slate-600 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-300"
                    >
                        {{ type }}
                    </span>
                </div>
            </div>
        </div>

        <div class="mt-5 overflow-hidden rounded-xl border border-slate-200 dark:border-slate-800">
            <table class="min-w-full border-collapse text-left text-sm">
                <thead class="bg-slate-100 text-xs uppercase tracking-[0.14em] text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                    <tr>
                        <th class="px-3 py-3">Name</th>
                        <th class="px-3 py-3">Type</th>
                        <th class="px-3 py-3">Length/Values</th>
                        <th class="px-3 py-3">Nullable</th>
                        <th class="px-3 py-3">Default</th>
                        <th class="px-3 py-3">Extra</th>
                        <th class="px-3 py-3">After</th>
                        <th class="px-3 py-3">Comment</th>
                        <th class="px-3 py-3">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(column, index) in columns"
                        :key="column._key"
                        class="border-t border-slate-200 dark:border-slate-800"
                        :class="index % 2 === 0 ? 'bg-white dark:bg-slate-900' : 'bg-slate-50/60 dark:bg-slate-950/20'"
                    >
                        <td class="px-3 py-3">
                            <input
                                v-model="column.name"
                                type="text"
                                placeholder="column_name"
                                class="w-full rounded-md border border-slate-300 bg-white px-2 py-2 text-sm text-slate-900 outline-none focus:border-cyan-400 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                            >
                        </td>
                        <td class="px-3 py-3">
                            <select
                                v-model="column.type"
                                class="w-full rounded-md border border-slate-300 bg-white px-2 py-2 text-sm text-slate-900 outline-none focus:border-cyan-400 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                            >
                                <option v-for="type in baseTypeOptions" :key="type" :value="type">{{ type }}</option>
                            </select>
                        </td>
                        <td class="px-3 py-3">
                            <input
                                v-model="column.length"
                                type="text"
                                placeholder="255"
                                class="w-full rounded-md border border-slate-300 bg-white px-2 py-2 text-sm text-slate-900 outline-none focus:border-cyan-400 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                            >
                        </td>
                        <td class="px-3 py-3 text-center">
                            <input
                                v-model="column.nullable"
                                type="checkbox"
                                class="h-4 w-4 rounded border-slate-300 text-cyan-600 focus:ring-cyan-500 dark:border-slate-700"
                            >
                        </td>
                        <td class="px-3 py-3">
                            <input
                                v-model="column.defaultValue"
                                type="text"
                                placeholder="default"
                                class="w-full rounded-md border border-slate-300 bg-white px-2 py-2 text-sm text-slate-900 outline-none focus:border-cyan-400 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                            >
                        </td>
                        <td class="px-3 py-3">
                            <label class="flex items-center gap-2 text-xs text-slate-600 dark:text-slate-300">
                                <input
                                    v-model="column.autoIncrement"
                                    type="checkbox"
                                    class="h-4 w-4 rounded border-slate-300 text-cyan-600 focus:ring-cyan-500 dark:border-slate-700"
                                >
                                AUTO_INCREMENT
                            </label>
                            <label class="mt-2 flex items-center gap-2 text-xs text-slate-600 dark:text-slate-300">
                                <input
                                    v-model="column.unsigned"
                                    type="checkbox"
                                    class="h-4 w-4 rounded border-slate-300 text-cyan-600 focus:ring-cyan-500 dark:border-slate-700"
                                >
                                UNSIGNED
                            </label>
                            <label class="mt-2 flex items-center gap-2 text-xs text-slate-600 dark:text-slate-300">
                                <input
                                    v-model="column.primaryKey"
                                    type="checkbox"
                                    class="h-4 w-4 rounded border-slate-300 text-cyan-600 focus:ring-cyan-500 dark:border-slate-700"
                                >
                                PRIMARY
                            </label>
                        </td>
                        <td class="px-3 py-3">
                            <input
                                v-model="column.after"
                                type="text"
                                placeholder="after column"
                                class="w-full rounded-md border border-slate-300 bg-white px-2 py-2 text-sm text-slate-900 outline-none focus:border-cyan-400 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                            >
                        </td>
                        <td class="px-3 py-3">
                            <input
                                v-model="column.comment"
                                type="text"
                                placeholder="comment"
                                class="w-full rounded-md border border-slate-300 bg-white px-2 py-2 text-sm text-slate-900 outline-none focus:border-cyan-400 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                            >
                        </td>
                        <td class="px-3 py-3">
                            <button
                                type="button"
                                class="rounded-md border border-rose-300 px-3 py-2 text-xs font-medium text-rose-700 hover:bg-rose-50 dark:border-rose-800 dark:text-rose-300 dark:hover:bg-rose-950/30"
                                :disabled="columns.length === 1"
                                @click="removeColumn(column._key)"
                            >
                                Remove
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
            <button
                type="button"
                class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                @click="addColumn"
            >
                Add column
            </button>

            <div class="flex flex-wrap gap-3">
                <button
                    type="button"
                    class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                    @click="emit('cancel')"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    class="rounded-md bg-cyan-600 px-4 py-2 text-sm font-medium text-white hover:bg-cyan-500 disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="saveBusy || !tableName.trim()"
                    @click="save"
                >
                    {{ saveBusy ? 'Saving...' : (mode === 'edit' ? 'Save Structure' : 'Create Table') }}
                </button>
            </div>
        </div>
    </section>
</template>
