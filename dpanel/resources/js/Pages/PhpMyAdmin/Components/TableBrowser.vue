<script setup>
import { computed } from 'vue';

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
    plain: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['paginate']);

const selectedTableDetails = computed(() => props.tableDetails || null);
const selectedTableColumns = computed(() => selectedTableDetails.value?.columns || []);
const selectedTableRows = computed(() => selectedTableDetails.value?.rows || []);
const pagination = computed(() => selectedTableDetails.value?.pagination || null);
const structureRows = computed(() => selectedTableColumns.value);
</script>

<template>
    <section
        class="flex h-full min-h-0 flex-col p-5 xl:col-span-2"
        :class="plain ? 'bg-transparent shadow-none' : 'rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900'"
    >
        <div class="mb-4 flex items-center justify-between gap-3">
            <div>
                <h2 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">Browse Table</h2>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    {{ selectedDatabase && selectedTable ? `${selectedDatabase}.${selectedTable}` : 'Choose a table to inspect rows.' }}
                </p>
            </div>
            <div class="flex items-center gap-2">
                <span class="rounded-full border border-cyan-200 bg-cyan-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-cyan-700 dark:border-cyan-900/50 dark:bg-cyan-950/30 dark:text-cyan-300">
                    {{ activeAction === 'browse' ? 'Browse active' : activeAction }}
                </span>
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

        <div v-else-if="selectedTableDetails && activeAction === 'structure'" class="min-h-0 flex-1 overflow-hidden rounded-xl border border-slate-200 dark:border-slate-800">
            <div class="h-full overflow-auto">
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
        </div>

        <div v-else-if="selectedTableDetails" class="min-h-0 flex-1 overflow-hidden rounded-xl border border-slate-200 dark:border-slate-800">
            <div class="h-full overflow-auto">
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
        </div>

        <div v-if="pagination" class="mt-4 flex items-center justify-between gap-3 text-sm">
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
